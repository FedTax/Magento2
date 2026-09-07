<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Observer\Sales;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Model\Tax;
use Taxcloud\Magento2\Observer\Sales\VerifyTaxCollector;
use Taxcloud\Magento2\Test\Unit\Double\QuoteDouble;

/**
 * The runtime half of collector diagnostics.
 *
 * Tax::collect() marks the quote when it runs; this observer turns a missing
 * mark at order placement into a log line. That is the only signal that exists
 * on a store where another module has taken the collector — no exception is
 * raised, tax is simply absent — so the conditions under which it does and does
 * not fire are the contract worth pinning.
 */
#[AllowMockObjectsWithoutExpectations]
class VerifyTaxCollectorTest extends TestCase
{
    private const QUOTE_STORE_ID = 3;

    /**
     * Store the ambient request would resolve to in admin and API contexts.
     * TaxCloud is disabled there, so any read that forgets to pass the quote's
     * store resolves to "disabled" and the warning silently stops firing.
     */
    private const AMBIENT_STORE_ID = 1;

    private function buildConfig(bool $enabledForQuoteStore): TaxcloudConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [
                'tax/taxcloud_settings/enabled',
                ScopeInterface::SCOPE_STORE,
                self::QUOTE_STORE_ID,
                $enabledForQuoteStore ? '1' : '0',
            ],
            ['tax/taxcloud_settings/enabled', ScopeInterface::SCOPE_STORE, self::AMBIENT_STORE_ID, '0'],
            ['tax/taxcloud_settings/enabled', ScopeInterface::SCOPE_STORE, null, '0'],
            ['tax/taxcloud_settings/logging', ScopeInterface::SCOPE_STORE, self::QUOTE_STORE_ID, '1'],
            ['tax/taxcloud_settings/logging', ScopeInterface::SCOPE_STORE, self::AMBIENT_STORE_ID, '1'],
            ['tax/taxcloud_settings/logging', ScopeInterface::SCOPE_STORE, null, '1'],
        ]);

        return new TaxcloudConfig($scopeConfig);
    }

    /**
     * @return array{0: VerifyTaxCollector, 1: Logger}
     */
    private function build(bool $enabled, bool $rateLimited = false): array
    {
        $inner = $this->createMock(Logger::class);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('test')->willReturn($rateLimited ? 12345 : false);
        $cache->method('save')->willReturn(true);

        $observer = new VerifyTaxCollector(
            $this->buildConfig($enabled),
            new GatewayLogger($inner, $this->buildConfig($enabled)),
            $cache
        );

        return [$observer, $inner];
    }

    private function buildQuote(bool $totalsCollected, bool $taxcloudRan): QuoteDouble
    {
        $quote = $this->getMockBuilder(QuoteDouble::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotalsCollectedFlag', 'getStoreId', 'getData', 'getId'])
            ->getMock();
        $quote->method('getTotalsCollectedFlag')->willReturn($totalsCollected);
        $quote->method('getStoreId')->willReturn(self::QUOTE_STORE_ID);
        $quote->method('getId')->willReturn(99);
        $quote->method('getData')->willReturnCallback(
            static function ($key = '') use ($taxcloudRan) {
                return $key === Tax::COLLECTED_FLAG ? $taxcloudRan : null;
            }
        );

        return $quote;
    }

    private function buildObserver($quote): Observer
    {
        $event = new Event(['quote' => $quote]);

        return new Observer(['event' => $event]);
    }

    public function testWarnsWhenTaxcloudIsEnabledButItsCollectorDidNotRun()
    {
        [$observer, $inner] = $this->build(true);
        $inner->expects($this->once())
            ->method('log')
            ->with(
                LogLevel::WARNING,
                $this->stringContains('not run'),
                $this->arrayHasKey('store_id')
            );

        $observer->execute($this->buildObserver($this->buildQuote(true, false)));
    }

    public function testSilentWhenTaxcloudCollectorRan()
    {
        [$observer, $inner] = $this->build(true);
        $inner->expects($this->never())->method('log');

        $observer->execute($this->buildObserver($this->buildQuote(true, true)));
    }

    /**
     * A store deliberately running another tax provider is not a fault.
     */
    public function testSilentWhenTaxcloudIsDisabledForTheQuoteStore()
    {
        [$observer, $inner] = $this->build(false);
        $inner->expects($this->never())->method('log');

        $observer->execute($this->buildObserver($this->buildQuote(true, false)));
    }

    /**
     * Without totals collected on this object in this request, a missing mark
     * says nothing about who owns the collector — so it must not be reported.
     */
    public function testSilentWhenTotalsWereNeverCollected()
    {
        [$observer, $inner] = $this->build(true);
        $inner->expects($this->never())->method('log');

        $observer->execute($this->buildObserver($this->buildQuote(false, false)));
    }

    /**
     * Enablement must resolve against the quote's store. Admin order creation
     * and API checkouts run with the default store view ambient, where TaxCloud
     * is disabled in this fixture — reading that scope would lose the warning.
     */
    public function testEnablementResolvesAgainstTheQuoteStoreNotTheAmbientOne()
    {
        [$observer, $inner] = $this->build(true);
        $inner->expects($this->once())
            ->method('log')
            ->with(LogLevel::WARNING, $this->anything(), $this->callback(
                static function ($context) {
                    return isset($context['store_id']) && $context['store_id'] === self::QUOTE_STORE_ID;
                }
            ));

        $observer->execute($this->buildObserver($this->buildQuote(true, false)));
    }

    /**
     * A store that has lost the collector loses it on every order; the log
     * needs the signal, not one line per checkout.
     */
    public function testRateLimitSuppressesRepeatWarnings()
    {
        [$observer, $inner] = $this->build(true, true);
        $inner->expects($this->never())->method('log');

        $observer->execute($this->buildObserver($this->buildQuote(true, false)));
    }

    public function testIgnoresEventsWithoutAQuote()
    {
        [$observer, $inner] = $this->build(true);
        $inner->expects($this->never())->method('log');

        $observer->execute($this->buildObserver(null));
    }
}
