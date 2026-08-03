<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Api\GatewayInterface;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Router;

/**
 * The transport-dispatch seam: every gateway operation must resolve api_type
 * for the store of the entity in hand (never the ambient store) and — while
 * REST tax operations don't exist — reach the SOAP implementation for both
 * API types, unchanged.
 */
#[AllowMockObjectsWithoutExpectations]
class RouterTest extends TestCase
{
    /**
     * @var Api&\PHPUnit\Framework\MockObject\MockObject
     */
    private $soap;

    private function router(array $configMap = []): Router
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        $this->soap = $this->createMock(Api::class);

        return new Router($this->soap, new TaxcloudConfig($scopeConfig));
    }

    public function testImplementsTheAggregateGatewayContract()
    {
        $this->assertInstanceOf(GatewayInterface::class, $this->router());
    }

    /**
     * All seven operations must forward their exact arguments to the SOAP
     * implementation and hand back its return value.
     */
    public function testEveryOperationDelegatesToSoapWithArgumentsAndResultIntact()
    {
        $router = $this->router();

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $itemsByType = ['product' => []];
        $shippingAssignment = ['shipping' => true];
        $address = ['Address1' => '162 East Ave'];

        $this->soap->expects($this->once())->method('lookupTaxes')
            ->with($itemsByType, $shippingAssignment, $quote)->willReturn(['taxes']);
        $this->soap->expects($this->once())->method('getValidatedCertificateID')
            ->with('cert-1', 'cust-1', 'NY', 7)->willReturn('cert-1');
        $this->soap->expects($this->once())->method('authorizeCapture')
            ->with($order)->willReturn(true);
        $this->soap->expects($this->once())->method('returnOrder')
            ->with($creditmemo)->willReturn(true);
        $this->soap->expects($this->once())->method('getOrderDetails')
            ->with($order)->willReturn(['details']);
        $this->soap->expects($this->once())->method('returnOrderCancellation')
            ->with($order)->willReturn(true);
        $this->soap->expects($this->once())->method('verifyAddress')
            ->with($address, 7)->willReturn(['verified']);

        $this->assertSame(['taxes'], $router->lookupTaxes($itemsByType, $shippingAssignment, $quote));
        $this->assertSame('cert-1', $router->getValidatedCertificateID('cert-1', 'cust-1', 'NY', 7));
        $this->assertTrue($router->authorizeCapture($order));
        $this->assertTrue($router->returnOrder($creditmemo));
        $this->assertSame(['details'], $router->getOrderDetails($order));
        $this->assertTrue($router->returnOrderCancellation($order));
        $this->assertSame(['verified'], $router->verifyAddress($address, 7));
    }

    /**
     * Multi-store acceptance criterion: the api_type read must be scoped by
     * the store of the entity being processed — an order from store 7 under
     * an ambient store A must resolve store 7's setting.
     */
    public function testDispatchResolvesApiTypeForTheEntitysStore()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with(TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('soap');

        $this->soap = $this->createMock(Api::class);
        $router = new Router($this->soap, new TaxcloudConfig($scopeConfig));

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getStoreId')->willReturn(7);

        $this->soap->expects($this->once())->method('authorizeCapture')->with($order);

        $router->authorizeCapture($order);
    }

    /**
     * Transitional rule (design D3): while REST implements no tax operations,
     * a rest-selected store transacts over SOAP exactly like a soap-selected
     * one — selecting "V3 REST" must not change gateway behavior.
     */
    public function testRestSelectedStoreStillReachesSoapForEveryOperation()
    {
        $router = $this->router([
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, 7, 'rest'],
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, null, 'rest'],
        ]);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getStoreId')->willReturn(7);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getStoreId')->willReturn(7);
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getStoreId')->willReturn(7);

        $this->soap->expects($this->once())->method('lookupTaxes');
        $this->soap->expects($this->once())->method('getValidatedCertificateID');
        $this->soap->expects($this->once())->method('authorizeCapture');
        $this->soap->expects($this->once())->method('returnOrder');
        $this->soap->expects($this->once())->method('getOrderDetails');
        $this->soap->expects($this->once())->method('returnOrderCancellation');
        $this->soap->expects($this->once())->method('verifyAddress');

        $router->lookupTaxes([], [], $quote);
        $router->getValidatedCertificateID('c', 'u', 'NY', 7);
        $router->authorizeCapture($order);
        $router->returnOrder($creditmemo);
        $router->getOrderDetails($order);
        $router->returnOrderCancellation($order);
        $router->verifyAddress([], 7);
    }

    /**
     * di.xml must bind all five gateway contracts to the router — a stale
     * preference would silently bypass the dispatch seam.
     */
    public function testDiXmlBindsEveryGatewayContractToTheRouter()
    {
        $diXml = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        $this->assertNotFalse($diXml, 'etc/di.xml must be parseable');

        foreach (
            [
                \Taxcloud\Magento2\Api\GatewayInterface::class,
                \Taxcloud\Magento2\Api\LookupGatewayInterface::class,
                \Taxcloud\Magento2\Api\OrderGatewayInterface::class,
                \Taxcloud\Magento2\Api\AddressGatewayInterface::class,
                \Taxcloud\Magento2\Api\ExemptionGatewayInterface::class,
            ] as $contract
        ) {
            $preference = $diXml->xpath('//preference[@for="' . $contract . '"]');
            $this->assertCount(1, $preference, $contract . ' must have exactly one preference');
            $this->assertSame(
                Router::class,
                (string) $preference[0]['type'],
                $contract . ' must be bound to the router'
            );
        }
    }
}
