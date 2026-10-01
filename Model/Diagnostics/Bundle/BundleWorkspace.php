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

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Scratch space for bundle generation, under var/tmp/taxcloud-diagnostics/.
 *
 * Everything created here is removed when generation ends — on success and on
 * failure alike. The finished ZIP lives beside its work directory until the
 * caller has streamed or moved it, then the caller removes it too.
 */
class BundleWorkspace
{
    /**
     * Directory under var/ holding in-flight bundles.
     */
    public const RELATIVE_DIR = 'tmp/taxcloud-diagnostics';

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var File
     */
    private $driver;

    /**
     * @param Filesystem $filesystem
     * @param File       $driver
     */
    public function __construct(Filesystem $filesystem, File $driver)
    {
        $this->filesystem = $filesystem;
        $this->driver = $driver;
    }

    /**
     * Absolute base directory, created if missing.
     *
     * @return string
     */
    public function getBaseDir(): string
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $var->create(self::RELATIVE_DIR);

        return rtrim($var->getAbsolutePath(self::RELATIVE_DIR), '/');
    }

    /**
     * Create a fresh, uniquely named work directory.
     *
     * @return string Absolute path
     */
    public function createWorkDir(): string
    {
        $dir = $this->getBaseDir() . '/' . bin2hex(random_bytes(8));
        $this->driver->createDirectory($dir, 0770);

        return $dir;
    }

    /**
     * Path, relative to var/, of a file inside the base directory.
     *
     * @param string $absolutePath
     * @return string
     */
    public function toVarRelativePath(string $absolutePath): string
    {
        $pos = strrpos($absolutePath, '/');

        return self::RELATIVE_DIR . '/' . ($pos === false ? $absolutePath : substr($absolutePath, $pos + 1));
    }

    /**
     * Remove a file or directory, ignoring anything already gone.
     *
     * @param string $path
     * @return void
     */
    public function remove(string $path): void
    {
        try {
            if ($this->driver->isDirectory($path)) {
                $this->driver->deleteDirectory($path);
            } elseif ($this->driver->isExists($path)) {
                $this->driver->deleteFile($path);
            }
        } catch (\Throwable $e) {
            // Best effort: a leftover temp file must never replace the
            // generation result (or its original exception) with a new error.
            return;
        }
    }
}
