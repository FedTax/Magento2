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

namespace Taxcloud\Magento2\Block\Certificate;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;

/**
 * The certificate management page in My Account.
 *
 * Carries no certificate data itself. Reading them is a live TaxCloud call, and
 * a customer's account page should not fail to render because a third-party API
 * is slow — nor should the failure be indistinguishable from "you have none",
 * which is exactly what a server-rendered empty list would look like.
 */
class Manage extends Template
{
    /**
     * @var CertificateFormReader
     */
    private $formReader;

    /**
     * @param Context $context
     * @param CertificateFormReader $formReader
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        CertificateFormReader $formReader,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->formReader = $formReader;
    }

    /**
     * @return string[]
     */
    public function getReasons(): array
    {
        return CertificateFormReader::REASONS;
    }

    /**
     * @return string[]
     */
    public function getBusinessTypes(): array
    {
        return CertificateFormReader::BUSINESS_TYPES;
    }

    /**
     * @return string
     */
    public function getJsConfig(): string
    {
        return (string) json_encode([
            'endpoints' => [
                'list' => $this->getUrl('taxcloud/certificate/listing'),
                'add' => $this->getUrl('taxcloud/certificate/add'),
                'delete' => $this->getUrl('taxcloud/certificate/delete'),
            ],
            'reasonDescriptionLimit' => CertificateFormReader::REASON_DESCRIPTION_LIMIT,
        ]);
    }
}
