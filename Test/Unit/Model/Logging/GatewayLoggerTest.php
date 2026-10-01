<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Logging;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * Covers the config gate that replaces the per-class null-logger pattern:
 * records reach the TaxCloud channel only when logging is enabled, and
 * debug-level records (payload dumps, wire traces) only in Advanced mode.
 */
#[AllowMockObjectsWithoutExpectations]
class GatewayLoggerTest extends TestCase
{
    private function logger(int $mode, Logger $inner): GatewayLogger
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('getLoggingMode')->willReturn($mode);
        return new GatewayLogger($inner, $config);
    }

    public function testBasicForwardsInfoAndAbove()
    {
        $inner = $this->createMock(Logger::class);
        $forwarded = [];
        $inner->method('log')->willReturnCallback(function ($level, $message) use (&$forwarded) {
            $forwarded[] = [$level, $message];
        });

        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $inner);
        $logger->info('lifecycle');
        $logger->warning('odd');
        $logger->error('broken');

        $this->assertSame(
            [
                [LogLevel::INFO, 'lifecycle'],
                [LogLevel::WARNING, 'odd'],
                [LogLevel::ERROR, 'broken'],
            ],
            $forwarded
        );
    }

    public function testBasicSuppressesDebugRecords()
    {
        $inner = $this->createMock(Logger::class);
        $inner->expects($this->never())->method('log');

        $this->logger(TaxcloudConfig::LOGGING_BASIC, $inner)->debug('PARAMS dump');
    }

    public function testAdvancedForwardsDebugRecords()
    {
        $inner = $this->createMock(Logger::class);
        $inner->expects($this->once())
            ->method('log')
            ->with(LogLevel::DEBUG, 'PARAMS dump', []);

        $this->logger(TaxcloudConfig::LOGGING_ADVANCED, $inner)->debug('PARAMS dump');
    }

    public function testDisabledSuppressesEverything()
    {
        $inner = $this->createMock(Logger::class);
        $inner->expects($this->never())->method('log');

        $logger = $this->logger(TaxcloudConfig::LOGGING_DISABLED, $inner);
        $logger->debug('dump');
        $logger->info('lifecycle');
        $logger->error('broken');
    }

    /**
     * The mode is read per call, not captured at construction. A singleton
     * built under one store's configuration must not keep applying it after
     * the value changes — the multi-website case, where the mode differs per
     * scope.
     */
    public function testConfigIsReadPerCallSoAFlipTakesEffectImmediately()
    {
        $inner = $this->createMock(Logger::class);
        $config = $this->createMock(TaxcloudConfig::class);

        // off -> basic -> advanced -> off across four calls on one instance.
        $config->method('getLoggingMode')->willReturnOnConsecutiveCalls(
            TaxcloudConfig::LOGGING_DISABLED,
            TaxcloudConfig::LOGGING_BASIC,
            TaxcloudConfig::LOGGING_ADVANCED,
            TaxcloudConfig::LOGGING_DISABLED
        );

        $forwarded = [];
        $inner->method('log')->willReturnCallback(function ($level, $message) use (&$forwarded) {
            $forwarded[] = $message;
        });

        $logger = new GatewayLogger($inner, $config);
        $logger->info('while off');
        $logger->info('while basic');
        $logger->debug('while advanced');
        $logger->info('after flipping back off');

        $this->assertSame(
            ['while basic', 'while advanced'],
            $forwarded,
            'only messages logged while the mode allowed them may reach the channel'
        );
    }

    /**
     * The mode resolves against the store bound via setStore(): an operation
     * for a store with logging on must log even when the ambient (null-store)
     * scope has logging off, and vice versa. This is what lets admin-context
     * operations honor the ORDER's store logging setting.
     */
    public function testModeResolvesAgainstTheStoreSetViaSetStore()
    {
        $inner = $this->createMock(Logger::class);

        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [TaxcloudConfig::XML_PATH_LOGGING, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            [TaxcloudConfig::XML_PATH_LOGGING, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, 2, '1'],
        ]);
        $config = new TaxcloudConfig($scopeConfig);

        $forwarded = [];
        $inner->method('log')->willReturnCallback(function ($level, $message) use (&$forwarded) {
            $forwarded[] = $message;
        });

        $logger = new GatewayLogger($inner, $config);

        $logger->info('ambient store, logging off');

        $logger->setStore(2);
        $logger->info('store 2, logging on');

        $logger->setStore(null);
        $logger->info('back to ambient, logging off again');

        $this->assertSame(['store 2, logging on'], $forwarded);
    }

    public function testUnboundContextLeavesRecordsUnchanged()
    {
        $inner = $this->createMock(Logger::class);
        $inner->expects($this->once())->method('log')->with(LogLevel::INFO, 'plain', ['k' => 'v']);

        $this->logger(TaxcloudConfig::LOGGING_BASIC, $inner)->info('plain', ['k' => 'v']);
    }

    public function testBoundContextIsAddedToEveryRecord()
    {
        $inner = $this->createMock(Logger::class);
        $contexts = [];
        $inner->method('log')->willReturnCallback(function ($level, $message, $context) use (&$contexts) {
            $contexts[] = $context;
        });

        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $inner);
        $id = $logger->beginOperation('capture', 42, '100000123');
        $logger->info('one');
        $logger->warning('two', ['extra' => 'x']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $id);
        $this->assertSame(
            ['correlation_id' => $id, 'operation' => 'capture', 'quote_id' => '42', 'order_increment_id' => '100000123'],
            $contexts[0]
        );
        $this->assertSame('x', $contexts[1]['extra']);
        $this->assertSame('100000123', $contexts[1]['order_increment_id']);
    }

    public function testCallerSuppliedKeysWinOverTheBinding()
    {
        $inner = $this->createMock(Logger::class);
        $inner->expects($this->once())->method('log')->with(
            LogLevel::INFO,
            'other order',
            $this->callback(function ($context) {
                return $context['order_increment_id'] === '999';
            })
        );

        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $inner);
        $logger->beginOperation('capture', 1, '100000123');
        $logger->info('other order', ['order_increment_id' => '999']);
    }

    public function testNewOperationForAnotherOrderReplacesTheContext()
    {
        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $this->createMock(Logger::class));

        $first = $logger->beginOperation('capture', 1, '100000001');
        $second = $logger->beginOperation('capture', 2, '100000002');

        $this->assertNotSame($first, $second);
        $this->assertSame('100000002', $logger->getCorrelationContext()['order_increment_id']);
        $this->assertSame('2', $logger->getCorrelationContext()['quote_id']);
    }

    public function testOperationOnTheSameOrderKeepsTheCorrelationId()
    {
        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $this->createMock(Logger::class));

        // The capture observer binds, then the gateway it calls binds again.
        $observer = $logger->beginOperation('capture', 7, '100000123');
        $gateway = $logger->beginOperation('capture', null, '100000123');

        $this->assertSame($observer, $gateway);
        $this->assertSame('7', $logger->getCorrelationContext()['quote_id'], 'a known quote id is not lost');
    }

    public function testQuoteLookupsWithoutOrderShareAnIdPerQuote()
    {
        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $this->createMock(Logger::class));

        $a = $logger->beginOperation('lookup', 5);
        $b = $logger->beginOperation('lookup', 5);
        $c = $logger->beginOperation('lookup', 6);

        $this->assertSame($a, $b);
        $this->assertNotSame($b, $c);
    }

    public function testContinueOperationKeepsABoundContextAndBeginsOneOtherwise()
    {
        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $this->createMock(Logger::class));

        $fresh = $logger->continueOperation('verify_address');
        $this->assertSame('verify_address', $logger->getCorrelationContext()['operation']);

        $lookup = $logger->beginOperation('lookup', 9);
        $this->assertNotSame($fresh, $lookup);
        $this->assertSame($lookup, $logger->continueOperation('verify_address'));
        $this->assertSame('9', $logger->getCorrelationContext()['quote_id']);

        $logger->clearCorrelation();
        $this->assertSame([], $logger->getCorrelationContext());
    }

    public function testFormattedLineIsGreppableAndParseable()
    {
        $stream = fopen('php://memory', 'w+');
        $handler = new \Monolog\Handler\StreamHandler($stream);
        $inner = new Logger('tclogger', [$handler]);

        $logger = $this->logger(TaxcloudConfig::LOGGING_BASIC, $inner);
        $logger->beginOperation('capture', 42, '100000123');
        $logger->info('Calling authorizeCapture (v3 REST) for order 100000123');

        rewind($stream);
        $line = (string) stream_get_contents($stream);

        $this->assertStringContainsString('"order_increment_id":"100000123"', $line);
        $this->assertStringContainsString('"quote_id":"42"', $line);
        $this->assertSame(1, preg_match('/(\{"correlation_id":.*\})\s*\[\]\s*$/', $line, $m));
        $this->assertSame('capture', json_decode($m[1], true)['operation']);
    }

    public function testTrailingLineBreaksAreTrimmedSoTheContextStaysOnTheSameLine()
    {
        $stream = fopen('php://memory', 'w+');
        $inner = new Logger('tclogger', [new \Monolog\Handler\StreamHandler($stream)]);

        $logger = $this->logger(TaxcloudConfig::LOGGING_ADVANCED, $inner);
        $logger->beginOperation('capture', 42, '100000123');
        $logger->debug("authorizeCapture response body: {\"ok\":true}\n");

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('{"ok":true} {"correlation_id":', $lines[0]);
    }
}
