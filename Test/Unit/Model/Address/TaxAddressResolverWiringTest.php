<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Address;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every consumer of the sourcing rule must be wired to it in di.xml.
 *
 * The constructors default the resolver, deliberately: a store whose compiled
 * DI is stale after an upgrade should still source an address rather than fatal
 * on a missing argument. But a defaulted argument the object manager never
 * supplies is also an argument no `<preference>` can redirect — the default
 * would win silently, and a store that had substituted the resolver would be
 * quietly running the stock one. The di.xml binding is what closes that, so it
 * is asserted here rather than trusted.
 */
#[AllowMockObjectsWithoutExpectations]
class TaxAddressResolverWiringTest extends TestCase
{
    private const RESOLVER = 'Taxcloud\\Magento2\\Model\\Address\\TaxAddressResolver';

    /**
     * @dataProvider consumerProvider
     */
    #[DataProvider('consumerProvider')]
    public function testTheConsumerIsBoundToTheResolverInDiXml(string $type)
    {
        $diXml = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        $this->assertNotFalse($diXml, 'etc/di.xml must be parseable');

        $argument = $diXml->xpath(
            sprintf('//type[@name="%s"]/arguments/argument[@name="addressResolver"]', $type)
        );

        $this->assertCount(
            1,
            $argument,
            $type . ' must bind addressResolver in di.xml, or a preference for the resolver '
            . 'would be bypassed by the constructor default.'
        );
        $this->assertSame(self::RESOLVER, trim((string) $argument[0]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function consumerProvider(): array
    {
        return [
            'request builder' => ['Taxcloud\\Magento2\\Model\\Gateway\\RequestBuilder'],
            'certificate recorder' => ['Taxcloud\\Magento2\\Observer\\Sales\\RecordCertificate'],
            'retail delivery fee' => ['Taxcloud\\Magento2\\Observer\\Sales\\PersistRetailDeliveryFee'],
        ];
    }
}
