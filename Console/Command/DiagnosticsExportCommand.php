<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem\Driver\File;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Audit\DiagnosticsAudit;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleGenerator;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleWorkspace;

/**
 * bin/magento taxcloud:diagnostics:export [--order=INCREMENT_ID] [--redact] [--output=PATH]
 *
 * Writes the same bundle the admin "Download Diagnostics" buttons produce, for
 * merchants with SSH access and for our own testing. Exits non-zero only when
 * no bundle could be written at all; a bundle with failed sections is still a
 * success, and its failures are listed.
 */
class DiagnosticsExportCommand extends Command
{
    private const OPTION_ORDER = 'order';
    private const OPTION_REDACT = 'redact';
    private const OPTION_OUTPUT = 'output';
    private const OPTION_STORE = 'store';
    private const OPTION_WEBSITE = 'website';
    private const OPTION_NO_PROBE = 'no-probe';
    private const OPTION_LOG_WINDOW = 'log-window';

    /**
     * @var BundleGenerator
     */
    private $generator;

    /**
     * @var BundleWorkspace
     */
    private $workspace;

    /**
     * @var DiagnosticsAudit
     */
    private $audit;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var File
     */
    private $driver;

    /**
     * @param BundleGenerator  $generator
     * @param BundleWorkspace  $workspace
     * @param DiagnosticsAudit $audit
     * @param State            $appState
     * @param File             $driver
     */
    public function __construct(
        BundleGenerator $generator,
        BundleWorkspace $workspace,
        DiagnosticsAudit $audit,
        State $appState,
        File $driver
    ) {
        parent::__construct();
        $this->generator = $generator;
        $this->workspace = $workspace;
        $this->audit = $audit;
        $this->appState = $appState;
        $this->driver = $driver;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('taxcloud:diagnostics:export');
        $this->setDescription('Write a TaxCloud diagnostics bundle (ZIP) to attach to a support ticket');
        $this->addOption(self::OPTION_ORDER, null, InputOption::VALUE_REQUIRED, 'Order increment ID for a per-order bundle');
        $this->addOption(
            self::OPTION_REDACT,
            null,
            InputOption::VALUE_NONE,
            'Mask customer names, street addresses, emails and phone numbers (credentials are always redacted)'
        );
        $this->addOption(
            self::OPTION_OUTPUT,
            null,
            InputOption::VALUE_REQUIRED,
            'Where to write the ZIP: a file path, or a directory (default: var/)'
        );
        $this->addOption(
            self::OPTION_STORE,
            null,
            InputOption::VALUE_REQUIRED,
            'Limit the bundle to one store view id; with --order, the store the order belongs to'
        );
        $this->addOption(self::OPTION_WEBSITE, null, InputOption::VALUE_REQUIRED, 'Limit the bundle to one website id');
        $this->addOption(self::OPTION_NO_PROBE, null, InputOption::VALUE_NONE, 'Skip the live TaxCloud API probe');
        $this->addOption(
            self::OPTION_LOG_WINDOW,
            null,
            InputOption::VALUE_REQUIRED,
            'Log window: standard (10 MB / 7 days), extended (50 MB / 30 days) or maximum (200 MB / 90 days)',
            BundleRequest::DEFAULT_LOG_WINDOW
        );
        parent::configure();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Store-scoped config resolution needs an area; the CLI starts with none.
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $output->writeln('<comment>' . $e->getMessage() . '</comment>', OutputInterface::VERBOSITY_DEBUG);
        }

        $store = $input->getOption(self::OPTION_STORE);
        $website = $input->getOption(self::OPTION_WEBSITE);
        if ($store !== null && $store !== '') {
            $scopeType = BundleRequest::SCOPE_STORE;
            $scopeId = (int) $store;
        } elseif ($website !== null && $website !== '') {
            $scopeType = BundleRequest::SCOPE_WEBSITE;
            $scopeId = (int) $website;
        } else {
            $scopeType = BundleRequest::SCOPE_DEFAULT;
            $scopeId = null;
        }

        $request = new BundleRequest(
            $scopeType,
            $scopeId,
            (bool) $input->getOption(self::OPTION_REDACT),
            (string) $input->getOption(self::OPTION_LOG_WINDOW),
            !$input->getOption(self::OPTION_NO_PROBE),
            $this->systemUser(),
            BundleRequest::ORIGIN_CLI,
            null,
            $input->getOption(self::OPTION_ORDER) !== null ? (string) $input->getOption(self::OPTION_ORDER) : null
        );

        try {
            $result = $this->generator->generate($request);
        } catch (\Throwable $e) {
            $this->audit->record($request, null, $e->getMessage());
            $output->writeln('<error>No diagnostics bundle was written: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $destination = $this->destination((string) $input->getOption(self::OPTION_OUTPUT), $result->getFileName());
        try {
            $this->driver->rename($result->getPath(), $destination);
        } catch (\Throwable $e) {
            $this->workspace->remove($result->getPath());
            $this->audit->record($request, null, $e->getMessage());
            $output->writeln('<error>No diagnostics bundle was written to ' . $destination . ': '
                . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $this->audit->record($request, $result);

        foreach ($result->getFailures() as $failure) {
            $output->writeln(sprintf(
                '<comment>Partial: the %s collector failed (%s)</comment>',
                $failure['section'],
                $failure['message']
            ));
        }
        $output->writeln($destination);

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param string $option
     * @param string $fileName
     * @return string
     */
    private function destination(string $option, string $fileName): string
    {
        if ($option === '') {
            return dirname($this->workspace->getBaseDir(), 2) . '/' . $fileName;
        }
        if ($this->driver->isDirectory($option)) {
            return rtrim($option, '/') . '/' . $fileName;
        }

        return $option;
    }

    /**
     * @return string
     */
    private function systemUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return 'cli:' . $info['name'];
            }
        }

        return 'cli';
    }
}
