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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Tic;

/**
 * The outcome of a TIC lookup.
 *
 * Three outcomes the admin field renders differently, and the distinction
 * matters: "no matches" means the query found nothing and the merchant should
 * try other words; "unavailable" means we could not look at all, and the
 * merchant should be left alone to type a code by hand. Collapsing the two
 * would tell someone their valid TIC does not exist because their credentials
 * are not saved yet.
 *
 * Unavailability carries a reason so the UI can say something specific when the
 * cause is simply that the store is not configured yet — the ordinary state
 * while an admin is first filling in the TaxCloud section.
 */
class TicSearchResult
{
    /**
     * The lookup ran; suggestions may be empty because nothing matched.
     */
    public const AVAILABLE = 'available';

    /**
     * The lookup could not run at all.
     */
    public const UNAVAILABLE = 'unavailable';

    /**
     * Unavailable because no usable credentials are configured for this scope.
     */
    public const REASON_NOT_CONFIGURED = 'not_configured';

    /**
     * Unavailable because TaxCloud rejected the configured credentials.
     */
    public const REASON_AUTH_FAILED = 'auth_failed';

    /**
     * Unavailable because TaxCloud could not be reached, errored, or throttled.
     */
    public const REASON_TRANSPORT = 'transport';

    /**
     * @var string
     */
    private $status;

    /**
     * @var TicSuggestion[]
     */
    private $suggestions;

    /**
     * @var string|null
     */
    private $reason;

    /**
     * @param string $status One of AVAILABLE / UNAVAILABLE
     * @param TicSuggestion[] $suggestions Empty when unavailable
     * @param string|null $reason One of the REASON_* constants when unavailable
     */
    public function __construct(string $status, array $suggestions = [], ?string $reason = null)
    {
        $this->status = $status;
        $this->suggestions = array_values($suggestions);
        $this->reason = $reason;
    }

    /**
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->status === self::AVAILABLE;
    }

    /**
     * @return TicSuggestion[]
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * @return string|null
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Shape handed to the admin UI.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false, 'reason' => $this->reason];
        }

        return [
            'available' => true,
            'suggestions' => array_map(
                static function (TicSuggestion $suggestion) {
                    return $suggestion->toArray();
                },
                $this->suggestions
            ),
        ];
    }
}
