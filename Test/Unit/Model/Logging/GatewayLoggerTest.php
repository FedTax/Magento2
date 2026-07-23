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
}
