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

/**
 * A generated bundle on disk, and what went into it.
 */
class BundleResult
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $fileName;

    /**
     * @var array
     */
    private $failures;

    /**
     * @var array<string, int>
     */
    private $entries;

    /**
     * @var BundleScope
     */
    private $scope;

    /**
     * @var string|null
     */
    private $orderIncrementId;

    /**
     * @param string             $path     Absolute path of the ZIP
     * @param string             $fileName Download file name
     * @param array              $failures Sections that failed: [['section' => ..., 'message' => ...]]
     * @param array<string, int> $entries  Archive entries with byte counts
     * @param BundleScope        $scope
     * @param string|null        $orderIncrementId Set for a per-order bundle
     */
    public function __construct(
        string $path,
        string $fileName,
        array $failures,
        array $entries,
        BundleScope $scope,
        ?string $orderIncrementId = null
    ) {
        $this->path = $path;
        $this->fileName = $fileName;
        $this->failures = $failures;
        $this->entries = $entries;
        $this->scope = $scope;
        $this->orderIncrementId = $orderIncrementId;
    }

    /**
     * The order a per-order bundle describes, by its increment id.
     *
     * @return string|null
     */
    public function getOrderIncrementId(): ?string
    {
        return $this->orderIncrementId;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * @return array
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * @return array<string, int>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return BundleScope
     */
    public function getScope(): BundleScope
    {
        return $this->scope;
    }
}
