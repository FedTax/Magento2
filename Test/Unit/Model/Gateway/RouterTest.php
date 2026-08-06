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
use Taxcloud\Magento2\Model\Gateway\Rest\RestGateway;
use Taxcloud\Magento2\Model\Gateway\Router;

/**
 * The transport-dispatch seam: every gateway operation must resolve api_type
 * for the store of the entity in hand (never the ambient store), dispatching
 * soap-selected stores to the SOAP gateway and rest-selected stores to the
 * REST gateway.
 */
#[AllowMockObjectsWithoutExpectations]
class RouterTest extends TestCase
{
    /**
     * @var Api&\PHPUnit\Framework\MockObject\MockObject
     */
    private $soap;

    /**
     * @var RestGateway&\PHPUnit\Framework\MockObject\MockObject
     */
    private $rest;

    private function router(array $configMap = []): Router
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        $this->soap = $this->createMock(Api::class);
        $this->rest = $this->createMock(RestGateway::class);

        return new Router($this->soap, $this->rest, new TaxcloudConfig($scopeConfig));
    }

    public function testImplementsTheAggregateGatewayContract()
    {
        $this->assertInstanceOf(GatewayInterface::class, $this->router());
    }

    /**
     * All seven operations must forward their exact arguments to the SOAP
     * implementation and hand back its return value, with the REST gateway
     * untouched. (Unset api_type resolves to rest by design — fresh installs
     * are REST; upgrades with V1 credentials are pinned to soap by the setup
     * patch — so soap is configured explicitly here.)
     */
    public function testEveryOperationDelegatesToSoapWithArgumentsAndResultIntact()
    {
        $router = $this->router([
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, null, 'soap'],
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, 7, 'soap'],
        ]);

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

        $this->rest->expects($this->never())->method($this->anything());

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
        $this->rest = $this->createMock(RestGateway::class);
        $router = new Router($this->soap, $this->rest, new TaxcloudConfig($scopeConfig));

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getStoreId')->willReturn(7);

        $this->soap->expects($this->once())->method('authorizeCapture')->with($order);

        $router->authorizeCapture($order);
    }

    /**
     * A rest-selected store dispatches every gateway operation to the REST
     * implementation; the SOAP gateway is never touched.
     */
    public function testRestSelectedStoreDispatchesEveryOperationToRest()
    {
        $router = $this->router([
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, 7, 'rest'],
        ]);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getStoreId')->willReturn(7);
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getStoreId')->willReturn(7);
        $creditmemo = $this->createMock(\Magento\Sales\Model\Order\Creditmemo::class);
        $creditmemo->method('getStoreId')->willReturn(7);

        $this->rest->expects($this->once())->method('lookupTaxes')->willReturn(['taxes']);
        $this->rest->expects($this->once())->method('getValidatedCertificateID')->willReturn('cert-1');
        $this->rest->expects($this->once())->method('authorizeCapture')->willReturn(true);
        $this->rest->expects($this->once())->method('returnOrder')->willReturn(true);
        $this->rest->expects($this->once())->method('getOrderDetails')->willReturn(['details']);
        $this->rest->expects($this->once())->method('returnOrderCancellation')->willReturn(true);
        $this->rest->expects($this->once())->method('verifyAddress')->willReturn(['verified']);

        $this->soap->expects($this->never())->method($this->anything());

        $this->assertSame(['taxes'], $router->lookupTaxes([], [], $quote));
        $this->assertSame('cert-1', $router->getValidatedCertificateID('c', 'u', 'NY', 7));
        $this->assertTrue($router->authorizeCapture($order));
        $this->assertTrue($router->returnOrder($creditmemo));
        $this->assertSame(['details'], $router->getOrderDetails($order));
        $this->assertTrue($router->returnOrderCancellation($order));
        $this->assertSame(['verified'], $router->verifyAddress([], 7));
    }

    /**
     * Mixed fleet: store 7 selects rest, store 8 selects soap — operations for
     * entities of each store reach their own transport in one process.
     */
    public function testMixedFleetRoutesPerEntityStore()
    {
        $router = $this->router([
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, 7, 'rest'],
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, 8, 'soap'],
        ]);

        $restOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $restOrder->method('getStoreId')->willReturn(7);
        $soapOrder = $this->createMock(\Magento\Sales\Model\Order::class);
        $soapOrder->method('getStoreId')->willReturn(8);

        $this->rest->expects($this->once())->method('authorizeCapture')->with($restOrder)->willReturn(true);
        $this->soap->expects($this->once())->method('authorizeCapture')->with($soapOrder)->willReturn(true);

        $this->assertTrue($router->authorizeCapture($restOrder));
        $this->assertTrue($router->authorizeCapture($soapOrder));
    }

    /**
     * di.xml must bind all five gateway contracts to the router — a stale
     * preference would silently bypass the dispatch seam — and must hand the
     * router its REST gateway as a proxy so SOAP-only fleets never build the
     * REST stack.
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

        $restArg = $diXml->xpath(
            '//type[@name="Taxcloud\Magento2\Model\Gateway\Router"]/arguments/argument[@name="rest"]'
        );
        $this->assertCount(1, $restArg, 'the router must receive an explicit rest argument');
        $this->assertSame(
            RestGateway::class . '\Proxy',
            trim((string) $restArg[0]),
            'the REST gateway must be injected as a proxy'
        );
    }
}
