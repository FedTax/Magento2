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
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;

/**
 * Certificate operations over the v3 REST API.
 *
 * The endpoints sit at two different scopes, which is easy to get wrong:
 * listing is ACCOUNT-level (`/tax/exemption-certificates`, filtered by query),
 * while create and delete are CONNECTION-scoped
 * (`/tax/connections/{id}/exemption-certificates`).
 *
 * The listing is filtered by `connectionId` as well as `customerId`. Without
 * it, an account carrying several connections would show one store the
 * certificates of another — the listing is not connection-scoped by default,
 * only by request.
 *
 * Failures throw. An empty list means the identity holds no certificates; it
 * must never be how a failed request looks, or a transient error would read as
 * "this customer is not exempt".
 */
class RestCertificateGateway implements CertificateGatewayInterface
{
    /**
     * Account-level listing, relative to the base URL.
     */
    private const LIST_PATH = '/tax/exemption-certificates';

    /**
     * Connection-scoped collection, relative to the connection prefix the
     * RestClient adds.
     */
    private const CONNECTION_PATH = '/exemption-certificates';

    /**
     * Page size; pages are followed by cursor until exhausted.
     */
    private const PAGE_LIMIT = 100;

    /**
     * Guards against an unbounded cursor loop if the API ever returns a
     * non-advancing cursor.
     */
    private const MAX_PAGES = 50;

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var RestCertificateMapper
     */
    private $mapper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RestClient $restClient
     * @param TaxcloudConfig $config
     * @param RestCertificateMapper $mapper
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        RestClient $restClient,
        TaxcloudConfig $config,
        RestCertificateMapper $mapper,
        ?LoggerInterface $logger = null
    ) {
        $this->restClient = $restClient;
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
        $this->logger->debug('v3 exemption-certificates listing for identity ' . $customerIdentity);

        $connectionId = (string) $this->config->getRestConnectionId($store);
        $certificates = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = 'customerId=' . rawurlencode((string) $customerIdentity)
                . '&limit=' . self::PAGE_LIMIT;
            if ($connectionId !== '') {
                // Not connection-scoped by default: without this filter a
                // multi-connection account leaks certificates between stores.
                $query .= '&connectionId=' . rawurlencode($connectionId);
            }
            if ($cursor !== null) {
                $query .= '&cursor=' . rawurlencode($cursor);
            }

            try {
                $response = $this->restClient->request(
                    'GET',
                    self::LIST_PATH . '?' . $query,
                    null,
                    $store,
                    false
                );
            } catch (\Throwable $e) {
                throw new RuntimeException('v3 certificate listing failed: ' . $e->getMessage(), 0, $e);
            }

            if (!$response->isSuccess()) {
                throw new RuntimeException('v3 certificate listing failed: ' . $response->errorDetail());
            }

            $body = $response->getBody() ?? [];
            foreach ($this->mapper->fromListResponse($body) as $certificate) {
                $certificates[] = $certificate;
            }

            $next = $body['nextCursor'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
            $pages++;
        } while ($cursor !== null && $pages < self::MAX_PAGES);

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
        $states = [];
        foreach ($data['states'] ?? [] as $abbreviation) {
            $states[] = ['abbreviation' => $abbreviation];
        }

        $name = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));

        // No singlePurchase key and no tax id: v3's create schema declares
        // additionalProperties:false and has no field for either, so sending
        // them fails the request outright rather than being ignored.
        $payload = [
            'customerId' => (string) $customerIdentity,
            'customerName' => $name,
            'customerBusinessType' => $data['businessType'] ?? 'Other',
            'customerBusinessDescription' => $data['businessTypeDescription'] ?? '',
            'reason' => $data['reason'] ?? 'Other',
            // v3 caps this at 20 characters and rejects anything longer.
            'reasonDescription' => mb_substr((string) ($data['reasonDescription'] ?? ''), 0, 20),
            'address' => [
                'line1' => $data['address1'] ?? '',
                'line2' => $data['address2'] ?? '',
                'city' => $data['city'] ?? '',
                'state' => $data['state'] ?? '',
                'zip' => mb_substr((string) ($data['zip'] ?? ''), 0, 5),
            ],
            'states' => $states,
        ];

        try {
            $response = $this->restClient->request('POST', self::CONNECTION_PATH, $payload, $store);
        } catch (\Throwable $e) {
            throw new RuntimeException('v3 certificate creation failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->isSuccess()) {
            throw new RuntimeException('v3 certificate creation failed: ' . $response->errorDetail());
        }

        $certificateId = (string) (($response->getBody() ?? [])['certificateId'] ?? '');
        if ($certificateId === '') {
            throw new RuntimeException('v3 certificate creation returned no certificateId');
        }

        return $certificateId;
    }

    /**
     * @param string $certificateId
     * @param string $customerIdentity Unused on v3, which deletes by id alone
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     * @throws RuntimeException
     */
    public function deleteCertificate($certificateId, $customerIdentity, $store = null)
    {
        try {
            $response = $this->restClient->request(
                'DELETE',
                self::CONNECTION_PATH . '/' . rawurlencode((string) $certificateId),
                null,
                $store
            );
        } catch (\Throwable $e) {
            throw new RuntimeException('v3 certificate deletion failed: ' . $e->getMessage(), 0, $e);
        }

        if (!$response->isSuccess()) {
            throw new RuntimeException('v3 certificate deletion failed: ' . $response->errorDetail());
        }
    }
}
