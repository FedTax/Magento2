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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Taxcloud\Magento2\Model\Diagnostics\StoreVerdict;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;

/**
 * bin/magento taxcloud:diagnose
 *
 * Reports whether TaxCloud is the tax collector Magento will actually run.
 *
 * The surface support reaches for first: it works over SSH without admin
 * access — including on the store whose admin is the thing that looks fine —
 * and its output pastes straight into a ticket. Deliberately indifferent to
 * whether an admin has dismissed the banner: a dismissal is a merchant saying
 * "I have seen this", never a reason to hide it from the person diagnosing it.
 */
class DiagnoseCommand extends Command
{
    /**
     * @var TaxCollectorDiagnostics
     */
    private $diagnostics;

    /**
     * @var State
     */
    private $appState;

    /**
     * @param TaxCollectorDiagnostics $diagnostics
     * @param State                   $appState
     */
    public function __construct(TaxCollectorDiagnostics $diagnostics, State $appState)
    {
        parent::__construct();
        $this->diagnostics = $diagnostics;
        $this->appState = $appState;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('taxcloud:diagnose');
        $this->setDescription(
            'Check whether TaxCloud is the active tax collector, and report the module that displaced it'
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
            // Collector resolution reads store-scoped config, which needs an
            // area set; the CLI starts with none.
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Already set by another command in the same process: fine.
            $output->writeln('<comment>' . $e->getMessage() . '</comment>', OutputInterface::VERBOSITY_DEBUG);
        }

        $verdict = $this->diagnostics->verdict();
        $storeVerdicts = $verdict->getStoreVerdicts();

        if ($storeVerdicts === []) {
            $output->writeln('<comment>TaxCloud is not enabled for any store. Nothing to check.</comment>');

            return Cli::RETURN_SUCCESS;
        }

        foreach ($storeVerdicts as $storeVerdict) {
            $this->writeStore($output, $storeVerdict);
        }

        if ($verdict->isHealthy()) {
            $output->writeln('');
            $output->writeln('<info>TaxCloud is the active tax collector for every enabled store.</info>');
            // Said plainly, because a green result here is routinely mistaken
            // for "tax is working": this command cannot see a bad credential or
            // a Lookup failure swallowed by the Magento fallback.
            $output->writeln(
                '<comment>This checks which extension calculates tax. It does not verify credentials or '
                . 'that tax is calculated correctly — use Verify Credentials in the admin for those.</comment>'
            );

            return Cli::RETURN_SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<error>TaxCloud is not calculating tax for every enabled store.</error>');
        $output->writeln('See https://fedtax.github.io/Magento2/extension-conflicts/');

        return Cli::RETURN_FAILURE;
    }

    /**
     * @param OutputInterface $output
     * @param StoreVerdict    $verdict
     * @return void
     */
    private function writeStore(OutputInterface $output, StoreVerdict $verdict): void
    {
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Store %d (%s)</info>',
            $verdict->getStoreId(),
            $verdict->getStoreName()
        ));

        if ($verdict->isFailure()) {
            $output->writeln('  Status:      <error>could not be checked</error>');
            $output->writeln('  Reason:      ' . $verdict->getFailureReason());

            return;
        }

        $output->writeln('  Status:      ' . ($verdict->isHealthy()
            ? '<info>OK</info>'
            : '<error>TaxCloud will not calculate tax</error>'));
        $output->writeln('  Collector:   ' . ($verdict->getActiveCollectorClass() ?? '<error>none</error>'));

        $interceptors = $verdict->getInterceptors();
        if ($interceptors !== []) {
            $output->writeln('  Intercepted: <error>' . implode(', ', $interceptors) . '</error>');
        }

        $later = $verdict->getLaterCollectors();
        if ($later !== []) {
            $output->writeln('  After tax:   ' . implode(', ', $later) . ' <comment>(may overwrite tax)</comment>');
        }
    }
}
