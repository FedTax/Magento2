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

/**
 * Every consumer of the order rules takes them as an optional constructor
 * argument, so a stale compiled DI degrades instead of fataling checkout.
 * Magento never auto-wires optional arguments — the default would win and the
 * rules would silently do nothing — so each binding is asserted here.
 */
#[AllowMockObjectsWithoutExpectations]
class WiringTest extends TestCase
{
    /**
     * @dataProvider bindingProvider
     */
    #[DataProvider('bindingProvider')]
    public function testTheConsumerIsBoundInDiXml(string $type, string $argument, string $expected)
    {
        $diXml = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        $this->assertNotFalse($diXml);

        $nodes = $diXml->xpath(sprintf('//type[@name="%s"]/arguments/argument[@name="%s"]', $type, $argument));

        $this->assertCount(1, $nodes, $type . ' must bind ' . $argument . ' in di.xml');
        $this->assertSame($expected, trim((string) $nodes[0]));
    }

    public static function bindingProvider(): array
    {
        $skip = 'Taxcloud\\Magento2\\Model\\OrderRule\\QuoteSkipResolver';
        $policy = 'Taxcloud\\Magento2\\Model\\OrderRule\\ReportingPolicy';
        return [
            'tax collector' => ['Taxcloud\\Magento2\\Model\\Tax', 'skipResolver', $skip],
            'RDF collector' => ['Taxcloud\\Magento2\\Model\\RetailDeliveryFee\\Total\\Quote\\RetailDeliveryFee', 'skipResolver', $skip],
            'certificate recorder' => ['Taxcloud\\Magento2\\Observer\\Sales\\RecordCertificate', 'skipResolver', $skip],
            'capture policy' => ['Taxcloud\\Magento2\\Observer\\Sales\\Complete', 'reportingPolicy', $policy],
            'capture recorder' => ['Taxcloud\\Magento2\\Observer\\Sales\\Complete', 'outcomeRecorder', 'Taxcloud\\Magento2\\Model\\OrderRule\\OutcomeRecorder'],
            'refund policy' => ['Taxcloud\\Magento2\\Observer\\Sales\\Refund', 'reportingPolicy', $policy],
            'cancel policy' => ['Taxcloud\\Magento2\\Model\\Order\\CancellationProcessor', 'reportingPolicy', $policy],
            'diagnostics rules' => ['Taxcloud\\Magento2\\Model\\Diagnostics\\Bundle\\Section\\SettingsSection', 'ruleRepository', 'Taxcloud\\Magento2\\Api\\OrderRuleRepositoryInterface'],
            'diagnostics summary' => ['Taxcloud\\Magento2\\Model\\Diagnostics\\Bundle\\Section\\SettingsSection', 'ruleSummary', 'Taxcloud\\Magento2\\Model\\OrderRule\\RuleSummary'],
        ];
    }

    public function testTheOutcomeIsRecordedAtQuoteSubmission()
    {
        $events = simplexml_load_file(__DIR__ . '/../../../../etc/events.xml');
        $nodes = $events->xpath(
            '//event[@name="sales_model_service_quote_submit_before"]/observer[@instance="Taxcloud\\Magento2\\Observer\\Sales\\RecordOrderOutcome"]'
        );

        $this->assertCount(1, $nodes);
    }

    public function testTheRulesScreenHasItsOwnPermission()
    {
        $acl = simplexml_load_file(__DIR__ . '/../../../../etc/acl.xml');
        $nodes = $acl->xpath('//resource[@id="Magento_Tax::manage_tax"]/resource[@id="Taxcloud_Magento2::order_rules"]');
        $this->assertCount(1, $nodes);

        $this->assertSame(
            'Taxcloud_Magento2::order_rules',
            \Taxcloud\Magento2\Controller\Adminhtml\OrderRule\AbstractAction::ADMIN_RESOURCE
        );
    }
}
