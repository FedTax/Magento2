<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\PackageInfo;
use Taxcloud\Magento2\Model\Gateway\UserAgent;

/**
 * Builds a real UserAgent over stubbed version sources, so transport tests
 * assert against the string the module actually sends rather than a mock's
 * say-so — which is what makes the cross-transport identity check meaningful.
 */
trait BuildsUserAgent
{
    /**
     * @param string $extension
     * @param string $magento
     * @param string $edition
     * @return UserAgent
     */
    private function userAgent(
        string $extension = '1.4.0',
        string $magento = '2.4.7-p3',
        string $edition = 'Community'
    ): UserAgent {
        $packageInfo = $this->createMock(PackageInfo::class);
        $packageInfo->method('getVersion')->willReturn($extension);

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn($magento);
        $productMetadata->method('getEdition')->willReturn($edition);

        return new UserAgent($productMetadata, $packageInfo);
    }

    /**
     * The string {@see userAgent()} produces, for assertion.
     *
     * @param string $extension
     * @param string $magento
     * @param string $edition
     * @return string
     */
    private function expectedUserAgent(
        string $extension = '1.4.0',
        string $magento = '2.4.7-p3',
        string $edition = 'Community'
    ): string {
        return sprintf(
            'TaxCloud-Magento2/%s Magento/%s (%s) PHP/%s',
            $extension,
            $magento,
            $edition,
            PHP_VERSION
        );
    }
}
