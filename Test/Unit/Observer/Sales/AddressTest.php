<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Observer\Sales;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Observer\Sales\Address;

#[AllowMockObjectsWithoutExpectations]
class AddressTest extends TestCase
{
    /**
     * Store id carried by the quote in the taxcloud_lookup_before payload. The
     * config map is keyed on it, so a regression back to ambient-store
     * (null-scope) config reads resolves enabled to null and the tests fail.
     */
    private const QUOTE_STORE_ID = 2;

    /**
     * A TaxcloudConfig whose store-2-scoped flags are as given.
     */
    private function buildConfig(string $enabled, string $verifyAddress): TaxcloudConfig
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::QUOTE_STORE_ID, $enabled],
                ['tax/taxcloud_settings/verify_address', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, self::QUOTE_STORE_ID, $verifyAddress],
            ]);
        return new TaxcloudConfig($scopeConfig);
    }

    /**
     * A quote on store QUOTE_STORE_ID, as carried by the lookup-before event.
     */
    private function buildQuote(): \Magento\Quote\Model\Quote
    {
        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getStoreId')->willReturn(self::QUOTE_STORE_ID);
        return $quote;
    }

    /**
     * Build the observer argument: a taxcloud_lookup_before event carrying the
     * params holder and the quote.
     */
    private function buildObserverArg(?\Magento\Framework\DataObject $obj): \Magento\Framework\Event\Observer
    {
        $data = ['quote' => $this->buildQuote()];
        if ($obj !== null) {
            $data['obj'] = $obj;
        }
        $event = new \Magento\Framework\Event($data);
        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);
        return $observerObj;
    }

    /**
     * When verify_address is disabled, observer returns early and does not call verifyAddress.
     */
    public function testExecuteDoesNothingWhenVerifyAddressDisabled()
    {
        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('verifyAddress');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = new Address($this->buildConfig('1', '0'), $tcapi, $logger);

        $observer->execute($this->buildObserverArg(null));
    }

    /**
     * When verifyAddress returns a result with empty Address1, observer preserves original Address1/Address2.
     */
    public function testExecutePreservesStreetWhenVerifiedResultHasEmptyAddress1()
    {
        $originalDestination = [
            'Address1' => '405 Victorian Ln',
            'Address2' => 'Apt 2',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $verifiedResult = [
            'Address1' => '',
            'Address2' => '',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        // The quote's store must be forwarded so verifyAddress resolves the
        // right credentials and cache scope.
        $tcapi->method('verifyAddress')
            ->with($originalDestination, self::QUOTE_STORE_ID)
            ->willReturn($verifiedResult);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $params = [
            'destination' => $originalDestination,
            'origin' => [],
        ];

        $obj = new \Magento\Framework\DataObject(['params' => $params]);

        $observer = new Address($this->buildConfig('1', '1'), $tcapi, $logger);
        $observer->execute($this->buildObserverArg($obj));

        // Empty verified Address1/Address2 must not overwrite the originals.
        $updated = $obj->getParams();
        $this->assertSame($originalDestination['Address1'], $updated['destination']['Address1']);
        $this->assertSame($originalDestination['Address2'], $updated['destination']['Address2']);
    }

    /**
     * When verifyAddress returns a result with non-empty Address1, observer uses verified result as-is.
     */
    public function testExecuteUsesVerifiedDestinationWhenAddress1IsPresent()
    {
        $originalDestination = [
            'Address1' => '405 Victorian Ln',
            'Address2' => '',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $verifiedResult = [
            'Address1' => '405 Victorian Ln',
            'Address2' => 'Unit B',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '1234',
        ];

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('verifyAddress')
            ->with($originalDestination, self::QUOTE_STORE_ID)
            ->willReturn($verifiedResult);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $params = ['destination' => $originalDestination];

        $obj = new \Magento\Framework\DataObject(['params' => $params]);

        $observer = new Address($this->buildConfig('1', '1'), $tcapi, $logger);
        $observer->execute($this->buildObserverArg($obj));

        // A verified result with a non-empty Address1 is used as-is.
        $updated = $obj->getParams();
        $this->assertSame($verifiedResult, $updated['destination']);
    }

    /**
     * Section 8.1: verifyAddress returns null/false — params must remain untouched and
     * the observer must not throw. The if-result branch is the guard.
     */
    public function testVerificationFailureLeavesParamsUnchangedAndDoesNotBlockCheckout()
    {
        $originalDestination = [
            'Address1' => '405 Victorian Ln',
            'Address2' => 'Apt 2',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        // Failure mode: TaxCloud could not verify the address.
        $tcapi->method('verifyAddress')
            ->with($originalDestination, self::QUOTE_STORE_ID)
            ->willReturn(null);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $obj = new \Magento\Framework\DataObject(['params' => ['destination' => $originalDestination]]);

        $observer = new Address($this->buildConfig('1', '1'), $tcapi, $logger);
        $observer->execute($this->buildObserverArg($obj));

        // Critical: when verifyAddress returns null the params must be left untouched.
        $this->assertSame(['destination' => $originalDestination], $obj->getParams());
    }

    /**
     * Section 8.2: verifyAddress throws SoapFault — observer must not let it escape.
     */
    public function testVerificationSoapExceptionDoesNotBlockCheckout()
    {
        $originalDestination = [
            'Address1' => '405 Victorian Ln',
            'Address2' => '',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('verifyAddress')->willThrowException(
            new \SoapFault('SOAP-ERROR', 'Service unavailable')
        );

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $obj = new \Magento\Framework\DataObject(['params' => ['destination' => $originalDestination]]);

        $observer = new Address($this->buildConfig('1', '1'), $tcapi, $logger);

        // Must not throw — checkout cannot be blocked by a TaxCloud address-verification SOAP error.
        $observer->execute($this->buildObserverArg($obj));
        // params must be left untouched when verification errors out.
        $this->assertSame(['destination' => $originalDestination], $obj->getParams());
    }
}
