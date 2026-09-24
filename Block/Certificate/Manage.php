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

use Magento\Customer\Model\Session;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;
use Taxcloud\Magento2\Model\Certificate\ExemptionPolicy;

/**
 * The certificate management page in My Account.
 *
 * Carries no certificate data itself. Reading them is a live TaxCloud call, and
 * a customer's account page should not fail to render because a third-party API
 * is slow — nor should the failure be indistinguishable from "you have none",
 * which is exactly what a server-rendered empty list would look like.
 *
 * What it does carry is whether this customer may manage their certificates,
 * and the add form for those who may. The endpoints ask the same question on
 * every request; this only decides what is shown.
 */
class Manage extends Template
{
    /**
     * @var RegionCollectionFactory
     */
    private $regionCollectionFactory;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var ExemptionPolicy
     */
    private $policy;

    /**
     * @var bool|null
     */
    private $canManage;

    /**
     * @param Context $context
     * @param RegionCollectionFactory $regionCollectionFactory
     * @param Session $customerSession
     * @param ExemptionPolicy $policy
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        RegionCollectionFactory $regionCollectionFactory,
        Session $customerSession,
        ExemptionPolicy $policy,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->regionCollectionFactory = $regionCollectionFactory;
        $this->customerSession = $customerSession;
        $this->policy = $policy;
    }

    /**
     * Whether this customer may create, attach and detach certificates here.
     *
     * @return bool
     */
    public function canManage(): bool
    {
        if ($this->canManage === null) {
            try {
                $this->canManage = $this->policy->mayManage(
                    $this->customer(),
                    (int) $this->_storeManager->getStore()->getId()
                );
            } catch (\Throwable $e) {
                $this->canManage = false;
            }
        }

        return $this->canManage;
    }

    /**
     * US states, code => name, for the "applies in" list and the purchaser's
     * state.
     *
     * @return array<string, string>
     */
    public function getUsStates(): array
    {
        $states = [];

        foreach ($this->regionCollectionFactory->create()->addCountryFilter('US') as $region) {
            $code = (string) $region->getCode();
            if ($code !== '') {
                $states[$code] = (string) $region->getName();
            }
        }

        asort($states);

        return $states;
    }

    /**
     * @return array{reasons: array<string, string>, businessTypes: array<string, string>}
     */
    public function getFormOptions(): array
    {
        return [
            'reasons' => CertificateFormReader::REASONS,
            'businessTypes' => CertificateFormReader::BUSINESS_TYPES,
        ];
    }

    /**
     * @return string
     */
    public function getGuidanceUrl(): string
    {
        return CertificateFormReader::GUIDANCE_URL;
    }

    /**
     * @return int
     */
    public function getReasonDescriptionLimit(): int
    {
        return CertificateFormReader::REASON_DESCRIPTION_LIMIT;
    }

    /**
     * The purchaser details to start the form from: the customer's default
     * billing address, which is usually the organisation the certificate is
     * for. Only a starting point — every field stays editable.
     *
     * @return array<string, string>
     */
    public function getPrefill(): array
    {
        $prefill = [
            'firstName' => '',
            'lastName' => '',
            'address1' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
        ];

        $customer = $this->customer();
        if ($customer === null) {
            return $prefill;
        }

        $prefill['firstName'] = (string) $customer->getFirstname();
        $prefill['lastName'] = (string) $customer->getLastname();

        $billingId = (string) $customer->getDefaultBilling();
        foreach ((array) $customer->getAddresses() as $address) {
            if ($billingId === '' || (string) $address->getId() !== $billingId) {
                continue;
            }
            if ($address->getCountryId() !== 'US') {
                break;
            }

            $street = (array) $address->getStreet();
            $region = $address->getRegion();

            $prefill['firstName'] = (string) $address->getFirstname();
            $prefill['lastName'] = (string) $address->getLastname();
            $prefill['address1'] = (string) ($street[0] ?? '');
            $prefill['city'] = (string) $address->getCity();
            $prefill['state'] = $region === null ? '' : (string) $region->getRegionCode();
            $prefill['zip'] = (string) $address->getPostcode();
            break;
        }

        return $prefill;
    }

    /**
     * @return string
     */
    public function getJsConfig(): string
    {
        return (string) json_encode([
            'endpoints' => [
                'list' => $this->getUrl('taxcloud/certificate/listing'),
                'delete' => $this->getUrl('taxcloud/certificate/delete'),
                'add' => $this->getUrl('taxcloud/certificate/add'),
                'attach' => $this->getUrl('taxcloud/certificate/attach'),
                'refresh' => $this->getUrl('taxcloud/certificate/refresh'),
            ],
        ]);
    }

    /**
     * @return \Magento\Customer\Api\Data\CustomerInterface|null
     */
    private function customer()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        try {
            return $this->customerSession->getCustomerData();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
