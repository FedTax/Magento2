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
 * What to put in one diagnostics bundle.
 *
 * Transport-neutral: the admin controller and the CLI command both build one
 * of these and hand it to {@see BundleGenerator}, so the two can never produce
 * different bundles for the same options. Immutable.
 */
class BundleRequest
{
    public const SCOPE_DEFAULT = 'default';
    public const SCOPE_WEBSITE = 'websites';
    public const SCOPE_STORE = 'stores';

    public const ORIGIN_ADMIN = 'admin';
    public const ORIGIN_CLI = 'cli';

    /**
     * Log windows offered in the generation dialog: [max bytes, max days].
     * "standard" is the default — the last 10 MB or 7 days of log data,
     * whichever is smaller.
     */
    public const LOG_WINDOWS = [
        'standard' => [10485760, 7],
        'extended' => [52428800, 30],
        'maximum' => [209715200, 90],
    ];

    public const DEFAULT_LOG_WINDOW = 'standard';

    /**
     * Surrounding log lines kept around each order-correlated line.
     */
    public const DEFAULT_CONTEXT_LINES = 5;

    /**
     * @var string
     */
    private $scopeType;

    /**
     * @var int|null
     */
    private $scopeId;

    /**
     * @var bool
     */
    private $redactPii;

    /**
     * @var string
     */
    private $logWindow;

    /**
     * @var bool
     */
    private $runProbe;

    /**
     * @var string
     */
    private $generatedBy;

    /**
     * @var string
     */
    private $origin;

    /**
     * @var int|null
     */
    private $orderId;

    /**
     * @var string|null
     */
    private $orderIncrementId;

    /**
     * @var int
     */
    private $contextLines;

    /**
     * @param string      $scopeType        One of the SCOPE_* constants; ignored for a per-order bundle
     * @param int|null    $scopeId          Website or store id; null for the default scope
     * @param bool        $redactPii        Mask customer names, street lines, emails and phones
     * @param string      $logWindow        A key of LOG_WINDOWS; unknown keys fall back to the default
     * @param bool        $runProbe         Make live TaxCloud API calls
     * @param string      $generatedBy      Admin username, or the CLI's system user
     * @param string      $origin           ORIGIN_ADMIN or ORIGIN_CLI
     * @param int|null    $orderId          Order entity id, for a per-order bundle
     * @param string|null $orderIncrementId Order increment id, for a per-order bundle (CLI)
     * @param int         $contextLines     Surrounding lines kept around correlated order lines
     */
    public function __construct(
        string $scopeType = self::SCOPE_DEFAULT,
        ?int $scopeId = null,
        bool $redactPii = false,
        string $logWindow = self::DEFAULT_LOG_WINDOW,
        bool $runProbe = true,
        string $generatedBy = '',
        string $origin = self::ORIGIN_ADMIN,
        ?int $orderId = null,
        ?string $orderIncrementId = null,
        int $contextLines = self::DEFAULT_CONTEXT_LINES
    ) {
        if (!in_array($scopeType, [self::SCOPE_DEFAULT, self::SCOPE_WEBSITE, self::SCOPE_STORE], true)) {
            $scopeType = self::SCOPE_DEFAULT;
        }
        $this->scopeType = $scopeType;
        $this->scopeId = $scopeType === self::SCOPE_DEFAULT ? null : $scopeId;
        $this->redactPii = $redactPii;
        $this->logWindow = isset(self::LOG_WINDOWS[$logWindow]) ? $logWindow : self::DEFAULT_LOG_WINDOW;
        $this->runProbe = $runProbe;
        $this->generatedBy = $generatedBy;
        $this->origin = $origin;
        $this->orderId = $orderId;
        $this->orderIncrementId = $orderIncrementId !== null && $orderIncrementId !== '' ? $orderIncrementId : null;
        $this->contextLines = max(0, $contextLines);
    }

    /**
     * @return string
     */
    public function getScopeType(): string
    {
        return $this->scopeType;
    }

    /**
     * @return int|null
     */
    public function getScopeId(): ?int
    {
        return $this->scopeId;
    }

    /**
     * @return bool
     */
    public function isRedactPii(): bool
    {
        return $this->redactPii;
    }

    /**
     * @return string
     */
    public function getLogWindow(): string
    {
        return $this->logWindow;
    }

    /**
     * @return int
     */
    public function getLogMaxBytes(): int
    {
        return self::LOG_WINDOWS[$this->logWindow][0];
    }

    /**
     * @return int
     */
    public function getLogMaxDays(): int
    {
        return self::LOG_WINDOWS[$this->logWindow][1];
    }

    /**
     * @return bool
     */
    public function isRunProbe(): bool
    {
        return $this->runProbe;
    }

    /**
     * @return string
     */
    public function getGeneratedBy(): string
    {
        return $this->generatedBy;
    }

    /**
     * @return string
     */
    public function getOrigin(): string
    {
        return $this->origin;
    }

    /**
     * @return int|null
     */
    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    /**
     * @return string|null
     */
    public function getOrderIncrementId(): ?string
    {
        return $this->orderIncrementId;
    }

    /**
     * Whether this is a per-order bundle.
     *
     * @return bool
     */
    public function isForOrder(): bool
    {
        return $this->orderId !== null || $this->orderIncrementId !== null;
    }

    /**
     * @return int
     */
    public function getContextLines(): int
    {
        return $this->contextLines;
    }
}
