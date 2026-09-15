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

use Magento\Sales\Api\Data\OrderInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;
use Taxcloud\Magento2\Model\Logging\LogRedactor;

/**
 * State shared by the sections of one bundle while it is being generated.
 *
 * Owns the redaction applied to everything that leaves the store: every string
 * a section hands over passes through scrub() — credential patterns, every
 * known credential value, and customer PII when the merchant asked for it.
 */
class BundleContext
{
    /**
     * @var BundleRequest
     */
    private $request;

    /**
     * @var BundleScope
     */
    private $scope;

    /**
     * @var OrderInterface|null
     */
    private $order;

    /**
     * @var string
     */
    private $workDir;

    /**
     * @var string[]
     */
    private $secrets = [];

    /**
     * @var PiiRedactor|null
     */
    private $piiRedactor;

    /**
     * @var array<string, array>
     */
    private $sectionData = [];

    /**
     * @param BundleRequest       $request
     * @param BundleScope         $scope
     * @param OrderInterface|null $order
     * @param string              $workDir     Scratch directory for staged files, removed after generation
     * @param string[]            $secrets     Exact credential values to scrub
     * @param PiiRedactor|null    $piiRedactor Set only when the merchant asked for customer details to be masked
     */
    public function __construct(
        BundleRequest $request,
        BundleScope $scope,
        ?OrderInterface $order,
        string $workDir,
        array $secrets,
        ?PiiRedactor $piiRedactor
    ) {
        $this->request = $request;
        $this->scope = $scope;
        $this->order = $order;
        $this->workDir = $workDir;
        $this->piiRedactor = $piiRedactor;

        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if ($secret === '') {
                continue;
            }
            $this->secrets[$secret] = true;
            $this->secrets[trim($secret)] = true;
            // The same value as it appears inside a JSON document.
            $encoded = json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && strlen($encoded) > 2) {
                $this->secrets[substr($encoded, 1, -1)] = true;
            }
        }
        unset($this->secrets['']);
    }

    /**
     * @return BundleRequest
     */
    public function getRequest(): BundleRequest
    {
        return $this->request;
    }

    /**
     * @return BundleScope
     */
    public function getScope(): BundleScope
    {
        return $this->scope;
    }

    /**
     * @return OrderInterface|null
     */
    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    /**
     * @return string
     */
    public function getWorkDir(): string
    {
        return $this->workDir;
    }

    /**
     * Whether customer details are being masked.
     *
     * @return bool
     */
    public function isRedactingPii(): bool
    {
        return $this->piiRedactor !== null;
    }

    /**
     * @return PiiRedactor|null
     */
    public function getPiiRedactor(): ?PiiRedactor
    {
        return $this->piiRedactor;
    }

    /**
     * Apply every redaction rule to text leaving the store.
     *
     * @param string $text
     * @return string
     */
    public function scrub(string $text): string
    {
        $text = LogRedactor::redactText($text, array_map('strval', array_keys($this->secrets)));

        return $this->piiRedactor !== null ? $this->piiRedactor->maskText($text) : $text;
    }

    /**
     * Whether a value is (or, trimmed, equals) a known credential value.
     *
     * Lets a section present a non-secret setting that happens to hold a
     * credential's value — on V1→V3 migrated accounts the Connection ID equals
     * the V1 API Key — as a fingerprint with an explanation, instead of
     * leaving the reader with an unexplained redaction marker.
     *
     * @param mixed $value
     * @return bool
     */
    public function isKnownSecret($value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $value = (string) $value;

        return $value !== '' && (isset($this->secrets[$value]) || isset($this->secrets[trim($value)]));
    }

    /**
     * Record a section's collected data for later sections and the summary.
     *
     * @param string $code
     * @param array  $data
     * @return void
     */
    public function setSectionData(string $code, array $data): void
    {
        $this->sectionData[$code] = $data;
    }

    /**
     * @param string $code
     * @return array|null Null when the section did not run or failed
     */
    public function getSectionData(string $code): ?array
    {
        return $this->sectionData[$code] ?? null;
    }
}
