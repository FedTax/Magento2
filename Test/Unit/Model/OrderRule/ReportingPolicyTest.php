<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\OrderRule\ReportingPolicy;
use Taxcloud\Magento2\Test\Unit\Double\OrderDouble;

#[AllowMockObjectsWithoutExpectations]
class ReportingPolicyTest extends TestCase
{
    private function order(array $data): OrderDouble
    {
        $order = $this->getMockBuilder(OrderDouble::class)->onlyMethods(['getStoreId'])->getMock();
        $order->method('getStoreId')->willReturn(5);
        $order->setData($data);
        return $order;
    }

    /**
     * @dataProvider outcomeProvider
     */
    #[DataProvider('outcomeProvider')]
    public function testAStoredOutcomeDecidesRegardlessOfTheStore(string $outcome, bool $reported)
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->expects($this->never())->method('isReportingByDefault');

        $this->assertSame($reported, (new ReportingPolicy($config))->isReported($this->order(['taxcloud_outcome' => $outcome])));
    }

    public static function outcomeProvider(): array
    {
        return [
            'report' => ['report', true],
            'calculate only' => ['calculate_only', false],
            'skip' => ['skip', false],
        ];
    }

    /**
     * Orders placed before rules existed keep their old behaviour: the order's
     * store setting decides.
     *
     * @dataProvider legacyProvider
     */
    #[DataProvider('legacyProvider')]
    public function testALegacyOrderFollowsItsStoreSetting(bool $storeReports)
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->expects($this->once())->method('isReportingByDefault')->with(5)->willReturn($storeReports);

        $this->assertSame($storeReports, (new ReportingPolicy($config))->isReported($this->order([])));
    }

    public static function legacyProvider(): array
    {
        return ['store reports' => [true], 'store does not report' => [false]];
    }

    public function testDescribeNamesTheOutcomeAndRule()
    {
        $policy = new ReportingPolicy($this->createMock(TaxcloudConfig::class));

        $this->assertSame(
            'outcome: skip, rule "Amazon"',
            $policy->describe($this->order(['taxcloud_outcome' => 'skip', 'taxcloud_outcome_rule_name' => 'Amazon']))
        );
        $this->assertSame(
            'outcome: calculate_only, store default',
            $policy->describe($this->order(['taxcloud_outcome' => 'calculate_only']))
        );
        $this->assertSame('store does not report orders', $policy->describe($this->order([])));
    }
}
