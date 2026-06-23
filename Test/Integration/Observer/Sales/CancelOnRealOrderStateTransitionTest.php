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

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Observer\Sales;

use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that cancelling a captured-but-uninvoiced order through the real
 * Magento flow reverses the sale in TaxCloud — exactly once, even though the
 * cancellation fires two events the observer listens to.
 *
 * Cancelling fires both order_cancel_after and sales_order_save_after (the
 * state transition to "canceled"). Observer\Sales\Cancel listens to both but
 * dedupes per order, so Api::returnOrderCancellation() -> the Returned SOAP
 * operation runs once. Exercising the real OrderManagementInterface::cancel()
 * transition — rather than synthetic event objects — is what makes this cover
 * gap #6.1 across both event paths.
 */
class CancelOnRealOrderStateTransitionTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
        // Capture on order creation so the order is "captured in TaxCloud"
        // before we cancel it (the OrderDetails mock reports a CapturedDate).
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
    }

    public function testCancelObserverFiresWhenCapturedOrderIsCanceled(): void
    {
        $soap = $this->soapClient();

        $order = $this->placeOrder();
        $this->assertSame(
            1,
            $soap->callCount('authorizedWithCapture'),
            'Sanity: the order should have been captured on placement.'
        );

        // Don't count the lookup/verify chatter from placement against the
        // cancellation assertion.
        $soap->resetCalls();

        $this->cancelOrder($order);

        $this->assertSame(
            1,
            $soap->callCount('Returned'),
            'Cancelling a captured, uninvoiced order should call Returned exactly once, '
            . 'even though order_cancel_after and sales_order_save_after both fire.'
        );

        $args = $soap->firstCallArgs('Returned');
        $this->assertSame(
            $order->getIncrementId(),
            $args['orderID'] ?? null,
            'The returned orderID should match the cancelled order.'
        );
    }
}
