<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\FrontendInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Cache\Type\Taxcloud;

/**
 * Covers the module's cache type: that it draws its own frontend from the pool,
 * tag-scopes what it writes, and is declared and wired consistently across
 * cache.xml and di.xml.
 */
#[AllowMockObjectsWithoutExpectations]
class TaxcloudTest extends TestCase
{
    private function cacheType(): Taxcloud
    {
        $pool = $this->createMock(FrontendPool::class);
        $pool->method('get')
            ->with(Taxcloud::TYPE_IDENTIFIER)
            ->willReturn($this->createMock(FrontendInterface::class));

        return new Taxcloud($pool);
    }

    /**
     * The tag is what scopes a flush to TaxCloud entries, so it has to be the
     * one the frontend actually carries.
     */
    public function testEntriesAreScopedToTheTaxcloudTag()
    {
        $this->assertSame(Taxcloud::CACHE_TAG, $this->cacheType()->getTag());
    }

    public function testDrawsItsOwnFrontendFromThePool()
    {
        $pool = $this->createMock(FrontendPool::class);
        $pool->expects($this->once())
            ->method('get')
            ->with(Taxcloud::TYPE_IDENTIFIER)
            ->willReturn($this->createMock(FrontendInterface::class));

        new Taxcloud($pool);
    }

    /**
     * cache.xml is what surfaces the row in System → Cache Management; the name
     * must match the pool identifier and the instance must be this class.
     */
    public function testCacheXmlDeclaresTheType()
    {
        $cacheXml = simplexml_load_file(__DIR__ . '/../../../../../etc/cache.xml');
        $this->assertNotFalse($cacheXml, 'etc/cache.xml must be parseable');

        $type = $cacheXml->xpath('/config/type[@name="' . Taxcloud::TYPE_IDENTIFIER . '"]');

        $this->assertCount(1, $type, 'cache.xml must declare the taxcloud type exactly once');
        $this->assertSame(
            Taxcloud::class,
            ltrim((string) $type[0]['instance'], '\\'),
            'declared instance must be this cache type'
        );
        $this->assertNotSame('', trim((string) $type[0]->label), 'the admin row needs a label');
        $this->assertNotSame(
            '',
            trim((string) $type[0]->description),
            'the admin row needs a description'
        );
    }

    /**
     * Every consumer must be bound to this type; one left on another frontend
     * would write entries the admin flush cannot reach.
     *
     * @dataProvider cacheConsumerProvider
     */
    #[DataProvider('cacheConsumerProvider')]
    public function testDiBindsConsumerToTheTaxcloudCacheType(string $consumer)
    {
        $diXml = simplexml_load_file(__DIR__ . '/../../../../../etc/di.xml');
        $this->assertNotFalse($diXml, 'etc/di.xml must be parseable');

        $argument = $diXml->xpath(
            '/config/type[@name="' . $consumer . '"]/arguments/argument[@name="cacheType"]'
        );

        $this->assertCount(1, $argument, $consumer . ' must bind a cacheType argument');
        $this->assertSame(
            Taxcloud::class,
            ltrim((string) $argument[0], '\\'),
            $consumer . ' must be bound to the TaxCloud cache type'
        );
    }

    public static function cacheConsumerProvider(): array
    {
        return [
            'result cache' => ['Taxcloud\Magento2\Model\Cache\ResultCache'],
            'exemption validator' => ['Taxcloud\Magento2\Model\Gateway\ExemptionValidator'],
        ];
    }
}
