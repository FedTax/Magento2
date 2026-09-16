<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Logging;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Logging\LogRedactor;

/**
 * Covers credential masking for both payload shapes that reach the log: the
 * SoapClient params arrays and (Advanced mode) the raw SOAP XML wire traces.
 */
class LogRedactorTest extends TestCase
{
    private const SENTINEL_API_ID  = 'SENTINEL_LOGIN_ID_DO_NOT_LEAK';
    private const SENTINEL_API_KEY = 'SENTINEL_KEY_DO_NOT_LEAK';

    public function testRedactArrayMasksCredentialValuesAndKeepsKeys()
    {
        $params = [
            'apiLoginID' => self::SENTINEL_API_ID,
            'apiKey'     => self::SENTINEL_API_KEY,
            'orderID'    => 'ORDER-1',
        ];

        $redacted = LogRedactor::redactArray($params);

        $this->assertSame(LogRedactor::PLACEHOLDER, $redacted['apiLoginID']);
        $this->assertSame(LogRedactor::PLACEHOLDER, $redacted['apiKey']);
        $this->assertSame('ORDER-1', $redacted['orderID']);

        // Copy, not in-place mutation.
        $this->assertSame(self::SENTINEL_API_ID, $params['apiLoginID']);
    }

    public function testRedactArrayIsANoOpWithoutCredentialKeys()
    {
        $params = ['orderID' => 'X', 'cartItems' => []];

        $this->assertSame($params, LogRedactor::redactArray($params));
    }

    public function testRedactXmlMasksPlainElements()
    {
        $xml = '<Lookup><apiLoginID>' . self::SENTINEL_API_ID . '</apiLoginID>'
            . '<apiKey>' . self::SENTINEL_API_KEY . '</apiKey>'
            . '<customerID>42</customerID></Lookup>';

        $redacted = LogRedactor::redactXml($xml);

        $this->assertStringNotContainsString(self::SENTINEL_API_ID, $redacted);
        $this->assertStringNotContainsString(self::SENTINEL_API_KEY, $redacted);
        $this->assertStringContainsString(
            '<apiLoginID>' . LogRedactor::PLACEHOLDER . '</apiLoginID>',
            $redacted
        );
        $this->assertStringContainsString('<customerID>42</customerID>', $redacted);
    }

    /**
     * Real TaxCloud SOAP envelopes carry namespace prefixes and attributes on
     * the credential elements; the mask must survive both, and element-name
     * casing differences between WSDL versions.
     */
    public function testRedactXmlMasksNamespacedAndAttributedElements()
    {
        $xml = '<SOAP-ENV:Envelope><ns1:Lookup>'
            . '<ns1:apiLoginID xsi:type="xsd:string">' . self::SENTINEL_API_ID . '</ns1:apiLoginID>'
            . '<ns1:APIKEY>' . self::SENTINEL_API_KEY . '</ns1:APIKEY>'
            . '</ns1:Lookup></SOAP-ENV:Envelope>';

        $redacted = LogRedactor::redactXml($xml);

        $this->assertStringNotContainsString(self::SENTINEL_API_ID, $redacted);
        $this->assertStringNotContainsString(self::SENTINEL_API_KEY, $redacted);
        $this->assertStringContainsString('<ns1:Lookup>', $redacted);
    }

    public function testRedactXmlLeavesCredentialFreePayloadsAlone()
    {
        $xml = '<VerifyAddressResponse><City>Seattle</City></VerifyAddressResponse>';

        $this->assertSame($xml, LogRedactor::redactXml($xml));
    }

    public function testRedactTextMasksEveryCredentialShapeAnyVersionCouldHaveLogged()
    {
        $text = implode("\n", [
            '{"apiLoginID":"' . self::SENTINEL_API_ID . '","apiKey":"' . self::SENTINEL_API_KEY . '","orderID":"1"}',
            '    [apiKey] => ' . self::SENTINEL_API_KEY,
            "    'apiLoginID' => '" . self::SENTINEL_API_ID . "',",
            '<apiKey>' . self::SENTINEL_API_KEY . '</apiKey>',
            'X-API-KEY: ' . self::SENTINEL_API_KEY,
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
            '{"access_token":"eyJhbGciOiJIUzI1NiJ9.payload.sig","access_token_validTo":"2026-09-15"}',
            'GET /x?apiKey=' . self::SENTINEL_API_KEY . '&connection=abc',
        ]);

        $redacted = LogRedactor::redactText($text);

        $this->assertStringNotContainsString(self::SENTINEL_API_ID, $redacted);
        $this->assertStringNotContainsString(self::SENTINEL_API_KEY, $redacted);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $redacted);
        $this->assertStringContainsString('"orderID":"1"', $redacted);
        $this->assertStringContainsString('"access_token_validTo":"2026-09-15"', $redacted);
        $this->assertStringContainsString('connection=abc', $redacted);
    }

    public function testRedactTextMasksKnownSecretValuesWherever()
    {
        $secret = 'a1b2c3d4-e5f6-7788-99aa-bbccddeeff00';
        $text = 'SoapFault: auth failed for ' . $secret . ' (retrying with ' . strtoupper($secret) . ')';

        $redacted = LogRedactor::redactText($text, [$secret, "  $secret  "]);

        $this->assertStringNotContainsString($secret, $redacted);
        // Exact match only: a differently-cased value is a different string.
        $this->assertStringContainsString(strtoupper($secret), $redacted);
    }

    public function testRedactTextIgnoresTooShortKnownValues()
    {
        $this->assertSame('tax 1 of 12', LogRedactor::redactText('tax 1 of 12', ['1', '']));
    }
}
