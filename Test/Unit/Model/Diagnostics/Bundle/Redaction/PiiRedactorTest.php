<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Redaction;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;

/**
 * Masking is opt-in and must be thorough about what identifies a person while
 * leaving everything that drives the tax calculation readable.
 */
class PiiRedactorTest extends TestCase
{
    public function testMasksNamesStreetsEmailsAndPhonesInStructuredData()
    {
        $masked = (new PiiRedactor())->maskArray([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'customer_firstname' => 'Jane',
            'email' => 'jane@example.com',
            'telephone' => '555-0100',
            'street' => ['1 Main St', 'Apt 2'],
            'city' => 'Seattle',
            'region_code' => 'WA',
            'postcode' => '98101',
            'tic' => '20010',
            'sku' => 'SKU-1',
            'note' => 'contact jane@example.com',
            'empty_name' => null,
            'lastName' => '',
        ]);

        $this->assertSame(PiiRedactor::MARKER, $masked['firstname']);
        $this->assertSame(PiiRedactor::MARKER, $masked['lastname']);
        $this->assertSame(PiiRedactor::MARKER, $masked['customer_firstname']);
        $this->assertSame(PiiRedactor::MARKER, $masked['email']);
        $this->assertSame(PiiRedactor::MARKER, $masked['telephone']);
        $this->assertSame(PiiRedactor::MARKER, $masked['street']);
        $this->assertSame('Seattle', $masked['city']);
        $this->assertSame('WA', $masked['region_code']);
        $this->assertSame('98101', $masked['postcode']);
        $this->assertSame('20010', $masked['tic']);
        $this->assertSame('SKU-1', $masked['sku']);
        $this->assertSame('contact ' . PiiRedactor::MARKER, $masked['note']);
        $this->assertSame('', $masked['lastName'], 'an empty value stays empty: masked must never mean "was set"');
    }

    public function testMasksEveryLogPayloadShape()
    {
        $log = implode("\n", [
            // v3 REST JSON
            '{"destination":{"line1":"1 Main St","line2":"Apt 2","city":"Seattle","state":"WA","zip":"98101"},"tic":20010}',
            // SOAP params print_r
            '    [Address1] => 1 Main St',
            '    [City] => Seattle',
            // SOAP XML
            '<Address1>1 Main St</Address1><Zip5>98101</Zip5>',
            // Magento address JSON with a street array
            '{"street":["1 Main St","Apt 2"],"firstname":"Jane","telephone":"555-0100","postcode":"98101"}',
            'order placed by jane.doe+tax@example.co.uk',
        ]);

        $masked = (new PiiRedactor())->maskText($log);

        foreach (['1 Main St', 'Apt 2', 'Jane', '555-0100', 'jane.doe+tax@example.co.uk'] as $pii) {
            $this->assertStringNotContainsString($pii, $masked);
        }
        foreach (['Seattle', '"state":"WA"', '98101', '20010', '<Zip5>98101</Zip5>'] as $kept) {
            $this->assertStringContainsString($kept, $masked);
        }
        $this->assertStringContainsString('"line1":"' . PiiRedactor::MARKER . '"', $masked);
        $this->assertStringContainsString('[Address1] => ' . PiiRedactor::MARKER, $masked);
        $this->assertStringContainsString('<Address1>' . PiiRedactor::MARKER . '</Address1>', $masked);
        $this->assertNotNull(json_decode(explode("\n", $masked)[0], true), 'masked JSON stays valid JSON');
    }
}
