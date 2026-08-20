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

namespace Taxcloud\Magento2\Model\Certificate;

use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Api\CertificateGatewayInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapClientProviderInterface;

/**
 * Certificate operations over the v1 SOAP API.
 *
 * v1 exposes exactly three: `GetExemptCertificates`, `AddExemptCertificate`
 * and `DeleteExemptCertificate`. There is no fetch-by-id, which is why
 * everything here is keyed on the customer identity.
 *
 * Every method throws on failure rather than returning an empty or falsy
 * result. "This customer holds no certificates" and "we could not ask" are
 * opposite answers to whether an order should be taxed, and a caller that
 * cannot tell them apart will eventually get one of them badly wrong.
 */
class SoapCertificateGateway implements CertificateGatewayInterface
{
    /**
     * @var SoapClientProviderInterface
     */
    private $soapClientProvider;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var SoapCertificateMapper
     */
    private $mapper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SoapClientProviderInterface $soapClientProvider
     * @param TaxcloudConfig $config
     * @param SoapCertificateMapper $mapper
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        SoapClientProviderInterface $soapClientProvider,
        TaxcloudConfig $config,
        SoapCertificateMapper $mapper,
        ?LoggerInterface $logger = null
    ) {
        $this->soapClientProvider = $soapClientProvider;
        $this->config = $config;
        $this->mapper = $mapper;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param string $customerIdentity
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return Certificate[]
     * @throws RuntimeException
     */
    public function listCertificates($customerIdentity, $store = null)
    {
        $this->logger->debug('GetExemptCertificates for identity ' . $customerIdentity);

        $response = $this->call('GetExemptCertificates', [
            'customerID' => (string) $customerIdentity,
        ], $store);

        $result = $response->GetExemptCertificatesResult ?? null;
        $responseType = $result->ResponseType ?? '';
        if ($responseType !== 'OK') {
            // Observed live: a customer whose certificates include one created
            // through v3 makes this whole call fail rather than skipping the
            // certificate it cannot read. So a non-OK here does NOT mean the
            // customer has none — it means we do not know, and must say so.
            throw new RuntimeException(
                'GetExemptCertificates returned ' . ($responseType ?: 'no response')
                . $this->messages($result)
            );
        }

        $certificates = [];
        foreach ($this->mapper->fromListResponse($response) as $certificate) {
            // v1 does not echo the identity back per certificate; the listing
            // was made under it, so that is what these are filed under.
            $certificates[] = new Certificate(
                $certificate->getCertificateId(),
                (string) $customerIdentity,
                $certificate->getStates(),
                $certificate->isDisabled(),
                $certificate->isSinglePurchase(),
                $certificate->getDetail()
            );
        }

        return $certificates;
    }

    /**
     * @param string $customerIdentity
     * @param array<string, mixed> $data
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     * @throws RuntimeException
     */
    public function createCertificate($customerIdentity, array $data, $store = null)
    {
        $exemptStates = [];
        foreach ($data['states'] ?? [] as $abbreviation) {
            $exemptStates[] = [
                'StateAbbr' => $abbreviation,
                'ReasonForExemption' => $data['reason'] ?? 'Other',
                'IdentificationNumber' => $data['taxId'] ?? '',
            ];
        }

        $response = $this->call('AddExemptCertificate', [
            'customerID' => (string) $customerIdentity,
            'exemptCert' => [
                'Detail' => [
                    'ExemptStates' => ['ExemptState' => $exemptStates],
                    // Never single-purchase: v3 cannot create one, and a
                    // feature that exists on one transport only is worse than
                    // a feature that exists on neither.
                    'SinglePurchase' => false,
                    'PurchaserFirstName' => $data['firstName'] ?? '',
                    'PurchaserLastName' => $data['lastName'] ?? '',
                    'PurchaserTitle' => $data['title'] ?? '',
                    'PurchaserAddress1' => $data['address1'] ?? '',
                    'PurchaserAddress2' => $data['address2'] ?? '',
                    'PurchaserCity' => $data['city'] ?? '',
                    'PurchaserState' => $data['state'] ?? '',
                    'PurchaserZip' => $data['zip'] ?? '',
                    'PurchaserTaxID' => [
                        'TaxType' => $data['taxType'] ?? 'FEIN',
                        'IDNumber' => $data['taxId'] ?? '',
                        'StateOfIssue' => $data['taxStateOfIssue'] ?? '',
                    ],
                    'PurchaserBusinessType' => $data['businessType'] ?? 'Other',
                    'PurchaserBusinessTypeOtherValue' => $data['businessTypeDescription'] ?? '',
                    'PurchaserExemptionReason' => $data['reason'] ?? 'Other',
                    'PurchaserExemptionReasonValue' => $data['reasonDescription'] ?? '',
                    // Required by the encoder, not by us: the WSDL declares
                    // CreatedDate on ExemptionCertificateDetail, and PHP's SOAP
                    // encoder refuses to serialize the object without it
                    // ("object has no 'CreatedDate' property"). TaxCloud stamps
                    // its own creation time regardless.
                    'CreatedDate' => gmdate('Y-m-d\\TH:i:s\\Z'),
                ],
            ],
        ], $store);

        $result = $response->AddExemptCertificateResult ?? null;
        if (($result->ResponseType ?? '') !== 'OK') {
            throw new RuntimeException(
                'AddExemptCertificate returned ' . ($result->ResponseType ?? 'no response')
                . $this->messages($result)
            );
        }

        $certificateId = (string) ($result->CertificateID ?? '');
        if ($certificateId === '') {
            throw new RuntimeException('AddExemptCertificate returned no CertificateID');
        }

        return $certificateId;
    }

    /**
     * @param string $certificateId
     * @param string $customerIdentity
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     * @throws RuntimeException
     */
    public function deleteCertificate($certificateId, $customerIdentity, $store = null)
    {
        $response = $this->call('DeleteExemptCertificate', [
            'certificateID' => (string) $certificateId,
        ], $store);

        $result = $response->DeleteExemptCertificateResult ?? null;
        if (($result->ResponseType ?? '') !== 'OK') {
            throw new RuntimeException(
                'DeleteExemptCertificate returned ' . ($result->ResponseType ?? 'no response')
                . $this->messages($result)
            );
        }
    }

    /**
     * Issue a SOAP call with the store's credentials prepended.
     *
     * @param string $operation
     * @param array<string, mixed> $params
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return object
     * @throws RuntimeException
     */
    private function call($operation, array $params, $store)
    {
        $client = $this->soapClientProvider->getClient($store);
        if (!$client) {
            throw new RuntimeException('Cannot perform ' . $operation . ': no SOAP client');
        }

        $credentials = [
            'apiLoginID' => $this->config->getApiId($store),
            'apiKey' => $this->config->getApiKey($store),
        ];

        try {
            return $client->{$operation}($credentials + $params);
        } catch (\Throwable $e) {
            $this->logger->error($operation . ' SOAP error: ' . $e->getMessage());
            throw new RuntimeException($operation . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Flatten a v1 response's message list for an exception message.
     *
     * @param object|null $result
     * @return string
     */
    private function messages($result)
    {
        $messages = [];
        $raw = $result->Messages->ResponseMessage ?? [];
        foreach (is_array($raw) ? $raw : [$raw] as $message) {
            $text = is_object($message) ? ($message->Message ?? '') : (string) $message;
            if ($text !== '') {
                $messages[] = $text;
            }
        }

        return $messages ? ': ' . implode('; ', $messages) : '';
    }
}
