<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Plugin\Adminhtml;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Plugin\Adminhtml\OrderViewDiagnosticsButton;

/**
 * The order-view button is the per-order entry point; it must exist only for
 * admins holding the diagnostics grant.
 */
#[AllowMockObjectsWithoutExpectations]
class OrderViewDiagnosticsButtonTest extends TestCase
{
    private function view(): View
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(65);
        $view = $this->createMock(View::class);
        $view->method('getOrder')->willReturn($order);

        return $view;
    }

    public function testAddsTheButtonBoundToTheOrder()
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('Taxcloud_Magento2::diagnostics')->willReturn(true);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->with('taxcloud/diagnostics/export')->willReturn('https://admin/taxcloud/diagnostics/export');

        $view = $this->view();
        $view->expects($this->once())->method('addButton')->with(
            'taxcloud_diagnostics',
            $this->callback(function ($button) {
                $config = $button['data_attribute']['mage-init']['Taxcloud_Magento2/js/diagnostics/export'];
                return (string) $button['label'] === 'TaxCloud Diagnostics'
                    && $config['orderId'] === '65'
                    && $config['url'] === 'https://admin/taxcloud/diagnostics/export';
            })
        );

        (new OrderViewDiagnosticsButton($authorization, $url))->beforeSetLayout($view);
    }

    public function testNoButtonWithoutTheDiagnosticsGrant()
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(false);

        $view = $this->view();
        $view->expects($this->never())->method('addButton');

        (new OrderViewDiagnosticsButton($authorization, $this->createMock(UrlInterface::class)))->beforeSetLayout($view);
    }
}
