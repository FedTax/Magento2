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
 * records reach the TaxCloud channel only when logging is enabled.
 */
#[AllowMockObjectsWithoutExpectations]
class GatewayLoggerTest extends TestCase
{
    private function logger(bool $enabled, Logger $inner): GatewayLogger
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('isLoggingEnabled')->willReturn($enabled);
        return new GatewayLogger($inner, $config);
    }

    public function testForwardsToInnerWhenEnabled()
    {
        $inner = $this->createMock(Logger::class);
        $inner->expects($this->once())
            ->method('log')
            ->with(LogLevel::INFO, 'hello', []);

        $this->logger(true, $inner)->info('hello');
    }

    public function testSuppressesWhenDisabled()
    {
        $inner = $this->createMock(Logger::class);
        $inner->expects($this->never())->method('log');

        $this->logger(false, $inner)->info('hello');
    }
}
