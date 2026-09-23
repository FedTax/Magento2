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

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Doubles;

use Magento\Framework\Message\Manager;
use Magento\Framework\Message\MessageInterface;

/**
 * Records the admin messages code under test adds, without a session.
 *
 * The real message manager stores messages in the session, which an integration
 * test running outside a request has no business starting. This double records
 * instead, so a test can assert that a save reported success or a warning — and
 * which — as the merchant would see it.
 *
 * It extends Magento's Manager so it satisfies ManagerInterface consumers, but
 * never calls the parent constructor: that would pull in the session, message
 * factory and event manager this double exists to avoid. Only the two methods
 * the Canada access check uses are recorded; anything else inherited would reach
 * uninitialised parent state, so tests must not exercise other paths through it.
 */
class RecordingMessageManager extends Manager
{
    /** @var array<int, array{type: string, text: string}> */
    private array $recorded = [];

    // phpcs:disable Magento2.Functions.StaticFunction.StaticFunction
    public function __construct()
    {
        // Intentionally no parent::__construct().
    }
    // phpcs:enable

    /**
     * @param string|\Magento\Framework\Phrase $message
     * @param string|null $group
     * @return $this
     */
    public function addSuccessMessage($message, $group = null)
    {
        $this->recorded[] = ['type' => MessageInterface::TYPE_SUCCESS, 'text' => (string) $message];

        return $this;
    }

    /**
     * @param string|\Magento\Framework\Phrase $message
     * @param string|null $group
     * @return $this
     */
    public function addWarningMessage($message, $group = null)
    {
        $this->recorded[] = ['type' => MessageInterface::TYPE_WARNING, 'text' => (string) $message];

        return $this;
    }

    /**
     * Every recorded message, in order.
     *
     * @return array<int, array{type: string, text: string}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Recorded texts of one message type.
     *
     * @return string[]
     */
    public function textsOfType(string $type): array
    {
        $out = [];
        foreach ($this->recorded as $message) {
            if ($message['type'] === $type) {
                $out[] = $message['text'];
            }
        }

        return $out;
    }

    public function forget(): void
    {
        $this->recorded = [];
    }
}
