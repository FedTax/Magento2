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

namespace Taxcloud\Magento2\Model\Gateway\Rest;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Cache for exchanged Bearer tokens, backed by the module's cache type so
 * System → Cache Management flushes live tokens along with everything else.
 *
 * Keyed by (auth endpoint + credential pair): scopes resolving to the same
 * pair share a token, matching TaxCloud's semantics. Entries expire a safety
 * margin before the token itself does, so a token handed out by get() is
 * never on the edge of expiry. A disabled cache degrades to
 * exchange-per-request — correct, just slower.
 */
class TokenCache
{
    /**
     * Seconds before validTo at which a cached token stops being handed out.
     */
    public const EXPIRY_MARGIN = 300;

    private const KEY_PREFIX = 'TAXCLOUD_BEARER_';

    /**
     * @var FrontendInterface
     */
    private $cacheType;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @param FrontendInterface   $cacheType Bound to the TaxCloud cache type in di.xml
     * @param SerializerInterface $serializer
     */
    public function __construct(FrontendInterface $cacheType, SerializerInterface $serializer)
    {
        $this->cacheType = $cacheType;
        $this->serializer = $serializer;
    }

    /**
     * A still-valid cached token for this endpoint + pair, or null.
     *
     * @param string $authEndpoint
     * @param string $apiLoginId
     * @param string $apiKey
     * @return BearerToken|null
     */
    public function get(string $authEndpoint, string $apiLoginId, string $apiKey): ?BearerToken
    {
        $raw = $this->cacheType->load($this->key($authEndpoint, $apiLoginId, $apiKey));
        if ($raw === false || $raw === null) {
            return null;
        }

        try {
            $data = $this->serializer->unserialize($raw);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data) || !isset($data['token'], $data['validTo'])) {
            return null;
        }

        $token = new BearerToken((string) $data['token'], (int) $data['validTo']);

        return $token->isValidAt(time(), self::EXPIRY_MARGIN) ? $token : null;
    }

    /**
     * Store a token until (validTo - margin).
     *
     * @param string $authEndpoint
     * @param string $apiLoginId
     * @param string $apiKey
     * @param BearerToken $token
     * @return void
     */
    public function save(string $authEndpoint, string $apiLoginId, string $apiKey, BearerToken $token): void
    {
        $lifetime = $token->getValidTo() - self::EXPIRY_MARGIN - time();
        if ($lifetime <= 0) {
            return;
        }

        $this->cacheType->save(
            $this->serializer->serialize(['token' => $token->getToken(), 'validTo' => $token->getValidTo()]),
            $this->key($authEndpoint, $apiLoginId, $apiKey),
            [],
            $lifetime
        );
    }

    /**
     * Drop the cached token for this endpoint + pair (e.g. after a 401).
     *
     * @param string $authEndpoint
     * @param string $apiLoginId
     * @param string $apiKey
     * @return void
     */
    public function invalidate(string $authEndpoint, string $apiLoginId, string $apiKey): void
    {
        $this->cacheType->remove($this->key($authEndpoint, $apiLoginId, $apiKey));
    }

    /**
     * @param string $authEndpoint
     * @param string $apiLoginId
     * @param string $apiKey
     * @return string
     */
    private function key(string $authEndpoint, string $apiLoginId, string $apiKey): string
    {
        return self::KEY_PREFIX . hash('sha256', $authEndpoint . '|' . $apiLoginId . '|' . $apiKey);
    }
}
