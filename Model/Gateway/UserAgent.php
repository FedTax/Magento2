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

namespace Taxcloud\Magento2\Model\Gateway;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\PackageInfo;
use Throwable;

/**
 * The identity every outbound TaxCloud request carries.
 *
 * One string — "TaxCloud-Magento2/1.4.0 Magento/2.4.7-p3 (Community)
 * PHP/8.3.14" — sent identically by both transports, so TaxCloud support can
 * attribute traffic to a concrete installation without asking the merchant
 * which versions they run.
 *
 * Unlike everything else in this module, it is deliberately NOT store-aware:
 * extension, Magento and PHP versions are properties of the installation, and
 * there is no scope at which they could differ. That is why {@see get()} takes
 * no store and why the result is memoized for the lifetime of the instance
 * (shared by DI, so at most once per request).
 *
 * Diagnostics must never cost a tax lookup: any component that cannot be
 * resolved degrades to {@see UNKNOWN} and any failure while assembling the
 * string is contained here, leaving a well-formed header naming whatever did
 * resolve.
 */
class UserAgent
{
    /**
     * Placeholder for a component whose value cannot be determined.
     */
    public const UNKNOWN = 'unknown';

    /**
     * Module whose declared version identifies the extension.
     */
    private const MODULE_NAME = 'Taxcloud_Magento2';

    /**
     * Magento's own placeholder when it cannot resolve its version, normalized
     * to {@see UNKNOWN} so one condition never has two spellings on the wire.
     *
     * @see \Magento\Framework\App\ProductMetadata::getVersion()
     */
    private const MAGENTO_UNKNOWN = 'UNKNOWN';

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var PackageInfo
     */
    private $packageInfo;

    /**
     * Memoized result, including a degraded one: a misbehaving collaborator is
     * asked once, not once per request.
     *
     * @var string|null
     */
    private $userAgent;

    /**
     * @param ProductMetadataInterface $productMetadata
     * @param PackageInfo $packageInfo
     */
    public function __construct(ProductMetadataInterface $productMetadata, PackageInfo $packageInfo)
    {
        $this->productMetadata = $productMetadata;
        $this->packageInfo = $packageInfo;
    }

    /**
     * The User-Agent for every TaxCloud request, on either API generation.
     *
     * Carries no credential, connection id, store id or customer data, so it
     * is safe to log in full.
     *
     * @return string
     */
    public function get(): string
    {
        if ($this->userAgent === null) {
            $this->userAgent = $this->build();
        }

        return $this->userAgent;
    }

    /**
     * Assemble the string, containing any collaborator failure.
     *
     * @return string
     */
    private function build(): string
    {
        try {
            $extension = $this->normalize($this->packageInfo->getVersion(self::MODULE_NAME));
        } catch (Throwable $e) {
            $extension = self::UNKNOWN;
        }

        try {
            $magento = $this->normalize($this->productMetadata->getVersion());
        } catch (Throwable $e) {
            $magento = self::UNKNOWN;
        }

        try {
            $edition = $this->normalize($this->productMetadata->getEdition());
        } catch (Throwable $e) {
            $edition = self::UNKNOWN;
        }

        return sprintf(
            'TaxCloud-Magento2/%s Magento/%s (%s) PHP/%s',
            $extension,
            $magento,
            $edition,
            $this->normalize(PHP_VERSION)
        );
    }

    /**
     * Blank values and Magento's own "UNKNOWN" both become {@see UNKNOWN}, so
     * a component that cannot be determined never produces an empty token or a
     * missing separator.
     *
     * @param mixed $value
     * @return string
     */
    private function normalize($value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return self::UNKNOWN;
        }

        $value = trim((string) $value);

        if ($value === '' || strcasecmp($value, self::MAGENTO_UNKNOWN) === 0) {
            return self::UNKNOWN;
        }

        return $value;
    }
}
