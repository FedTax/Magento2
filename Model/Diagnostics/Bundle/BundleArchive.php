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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle;

use Magento\Framework\Exception\LocalizedException;

/**
 * The ZIP being written.
 *
 * Backed by a file on disk, never an in-memory buffer: staged log files are
 * added by path and only read by the zip library when the archive closes, so
 * a bundle carrying hundreds of megabytes of log never sits in PHP memory.
 */
class BundleArchive
{
    /**
     * @var \ZipArchive
     */
    private $zip;

    /**
     * @var string
     */
    private $path;

    /**
     * @var array<string, int>
     */
    private $entries = [];

    /**
     * @var BundleContext|null
     */
    private $context;

    /**
     * @param string             $path    Absolute path of the ZIP to create
     * @param BundleContext|null $context Scrubs every string entry when set
     * @throws LocalizedException When the archive cannot be created
     */
    public function __construct(string $path, ?BundleContext $context = null)
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new LocalizedException(__('The PHP zip extension is required to generate a diagnostics bundle.'));
        }
        $this->zip = new \ZipArchive();
        $this->path = $path;
        $this->context = $context;
        $result = $this->zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new LocalizedException(__('Could not create the diagnostics archive (zip error %1).', $result));
        }
    }

    /**
     * @param BundleContext $context
     * @return void
     */
    public function setContext(BundleContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Add a text entry, scrubbed.
     *
     * @param string $name
     * @param string $content
     * @return void
     */
    public function addString(string $name, string $content): void
    {
        if ($this->context !== null) {
            $content = $this->context->scrub($content);
        }
        $this->zip->addFromString($name, $content);
        $this->entries[$name] = strlen($content);
    }

    /**
     * Add a JSON document, scrubbed.
     *
     * @param string $name
     * @param array  $data
     * @return void
     */
    public function addJson(string $name, array $data): void
    {
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $this->addString($name, (string) $encoded . "\n");
    }

    /**
     * Add a staged file by path. The caller has already scrubbed its content.
     *
     * @param string $name
     * @param string $localPath
     * @param int    $bytes
     * @return void
     */
    public function addStagedFile(string $name, string $localPath, int $bytes): void
    {
        $this->zip->addFile($localPath, $name);
        $this->entries[$name] = $bytes;
    }

    /**
     * Entries added so far, with byte counts.
     *
     * @return array<string, int>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * Whether an entry exists.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /**
     * Finish writing. Staged files are read here, so they must still exist.
     *
     * @return void
     * @throws LocalizedException
     */
    public function close(): void
    {
        if (!$this->zip->close()) {
            throw new LocalizedException(__('Could not write the diagnostics archive.'));
        }
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
