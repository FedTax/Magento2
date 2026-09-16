<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Log;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Logger\Handler;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogPathResolver;

/**
 * The bundle must find the log where the DI-configured handler writes it — a
 * merchant who relocated it per the docs still gets their log.
 */
#[AllowMockObjectsWithoutExpectations]
class LogPathResolverTest extends TestCase
{
    private function directoryList(): DirectoryList
    {
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn('/srv/magento');
        $directoryList->method('getPath')->with(DirectoryList::LOG)->willReturn('/srv/magento/var/log');

        return $directoryList;
    }

    public function testReadsTheRelocatedPathFromTheConfiguredHandler()
    {
        // As built by the object manager from a di.xml override of fileName.
        $handler = new Handler($this->createMock(DriverInterface::class), null, '/var/log/custom/tax-audit.log');
        $logger = new Logger('tclogger', [$handler]);

        $path = (new LogPathResolver($logger, $this->directoryList()))->getTaxcloudLogPath();

        $this->assertSame($handler->getUrl(), $path);
        $this->assertStringEndsWith('/var/log/custom/tax-audit.log', $path);
        $this->assertStringNotContainsString('taxcloud.log', $path);
    }

    public function testReadsAnExplicitFilePathPrefix()
    {
        $handler = new Handler($this->createMock(DriverInterface::class), '/mnt/logs/', 'taxcloud-prod.log');
        $logger = new Logger('tclogger', [$handler]);

        $this->assertSame(
            '/mnt/logs/taxcloud-prod.log',
            (new LogPathResolver($logger, $this->directoryList()))->getTaxcloudLogPath()
        );
    }

    public function testFallsBackToTheDefaultOnlyWhenNoFileHandlerIsConfigured()
    {
        $logger = new Logger('tclogger', [new \Monolog\Handler\NullHandler()]);

        $this->assertSame(
            '/srv/magento' . Handler::DEFAULT_FILE_NAME,
            (new LogPathResolver($logger, $this->directoryList()))->getTaxcloudLogPath()
        );
    }

    public function testMagentoLogsLiveInTheLogDirectory()
    {
        $resolver = new LogPathResolver(new Logger('tclogger'), $this->directoryList());

        $this->assertSame('/srv/magento/var/log/system.log', $resolver->getMagentoLogPath('system.log'));
    }
}
