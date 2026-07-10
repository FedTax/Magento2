<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Api\AddressGatewayInterface;
use Taxcloud\Magento2\Api\ExemptionGatewayInterface;
use Taxcloud\Magento2\Api\GatewayInterface;
use Taxcloud\Magento2\Api\LookupGatewayInterface;
use Taxcloud\Magento2\Api\OrderGatewayInterface;
use Taxcloud\Magento2\Model\Api;

/**
 * Locks in the gateway service-contract seam introduced for the REST migration:
 *
 *  - the SOAP implementation (Model\Api) satisfies every gateway contract, and
 *  - each finer-grained interface exposes exactly the operation(s) its
 *    consumers depend on, so a call site can type-hint the narrow interface.
 *
 * These are structural assertions on purpose — they guard the seam without
 * exercising any SOAP behavior.
 */
class GatewayContractTest extends TestCase
{
    public function testConcreteGatewayImplementsEverySegregatedContract()
    {
        $this->assertTrue(is_subclass_of(Api::class, LookupGatewayInterface::class));
        $this->assertTrue(is_subclass_of(Api::class, OrderGatewayInterface::class));
        $this->assertTrue(is_subclass_of(Api::class, AddressGatewayInterface::class));
        $this->assertTrue(is_subclass_of(Api::class, ExemptionGatewayInterface::class));
        $this->assertTrue(is_subclass_of(Api::class, GatewayInterface::class));
    }

    public function testAggregateInterfaceExtendsEverySegregatedContract()
    {
        $this->assertTrue(is_subclass_of(GatewayInterface::class, LookupGatewayInterface::class));
        $this->assertTrue(is_subclass_of(GatewayInterface::class, OrderGatewayInterface::class));
        $this->assertTrue(is_subclass_of(GatewayInterface::class, AddressGatewayInterface::class));
        $this->assertTrue(is_subclass_of(GatewayInterface::class, ExemptionGatewayInterface::class));
    }

    /**
     * @param class-string $interface
     * @param string[]     $methods
     * @dataProvider contractMethodsProvider
     */
    #[DataProvider('contractMethodsProvider')]
    public function testInterfaceDeclaresExpectedOperations($interface, array $methods)
    {
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists($interface, $method),
                sprintf('%s must declare %s()', $interface, $method)
            );
        }
    }

    /**
     * @return array<string, array{0: class-string, 1: string[]}>
     */
    public static function contractMethodsProvider(): array
    {
        return [
            'lookup' => [LookupGatewayInterface::class, ['lookupTaxes']],
            'order' => [
                OrderGatewayInterface::class,
                ['authorizeCapture', 'returnOrder', 'getOrderDetails', 'returnOrderCancellation'],
            ],
            'address' => [AddressGatewayInterface::class, ['verifyAddress']],
            'exemption' => [ExemptionGatewayInterface::class, ['getValidatedCertificateID']],
        ];
    }

    /**
     * The transport-specific SOAP helpers must NOT leak into the gateway
     * contract — they are exactly what the REST migration replaces.
     *
     * @dataProvider transportHelperProvider
     */
    #[DataProvider('transportHelperProvider')]
    public function testGatewayContractExcludesTransportHelpers(string $method)
    {
        $this->assertFalse(
            method_exists(GatewayInterface::class, $method),
            sprintf('%s is transport-specific and must not be part of the gateway contract', $method)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function transportHelperProvider(): array
    {
        return [
            'getClient' => ['getClient'],
            'buildSoapOptions' => ['buildSoapOptions'],
            'getSoapTimeout' => ['getSoapTimeout'],
            'isTimeoutError' => ['isTimeoutError'],
            'callSoapWithRetry' => ['callSoapWithRetry'],
        ];
    }
}
