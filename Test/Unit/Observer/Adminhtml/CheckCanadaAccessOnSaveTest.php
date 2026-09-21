<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Observer\Adminhtml;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Canada\CanadaAccessResult;
use Taxcloud\Magento2\Model\Canada\ConfigScopeStore;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Observer\Adminhtml\CheckCanadaAccessOnSave;

/**
 * Saving the tax configuration with Canadian tax on tells the merchant, right
 * then, whether their TaxCloud account actually has Canada — but only when the
 * save touched something that decides it, and never at the cost of the save.
 */
#[AllowMockObjectsWithoutExpectations]
class CheckCanadaAccessOnSaveTest extends TestCase
{
    private const STORE_ID = '4';

    /**
     * @var TaxcloudConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var CanadaAccessChecker&\PHPUnit\Framework\MockObject\MockObject
     */
    private $checker;

    /**
     * @var ManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $messages;

    /**
     * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->checker = $this->createMock(CanadaAccessChecker::class);
        $this->messages = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function observer(): CheckCanadaAccessOnSave
    {
        $scopeStore = $this->createMock(ConfigScopeStore::class);
        $scopeStore->method('resolve')->with('1', self::STORE_ID)->willReturn(self::STORE_ID);

        return new CheckCanadaAccessOnSave($this->config, $this->checker, $scopeStore, $this->messages, $this->logger);
    }

    /**
     * @param array|null $changedPaths null when the save does not report them
     */
    private function event(?array $changedPaths): Observer
    {
        $data = ['website' => '1', 'store' => self::STORE_ID];
        if ($changedPaths !== null) {
            $data['changed_paths'] = $changedPaths;
        }

        return new Observer(['event' => new Event($data)]);
    }

    /**
     * Enabled and Canadian tax on for the EDITED store only.
     */
    private function canadaOnForEditedStore(): void
    {
        $this->config->method('isEnabled')->willReturnMap([[self::STORE_ID, true], [null, false]]);
        $this->config->method('isCanadaTaxEnabled')->willReturnMap([[self::STORE_ID, true], [null, false]]);
    }

    public function testTurningCanadaOnForAnAccountWithoutItWarns()
    {
        $this->canadaOnForEditedStore();
        $this->checker->expects($this->once())->method('check')->with(self::STORE_ID)->willReturn(
            new CanadaAccessResult(CanadaAccessResult::NOT_ENABLED, 'Contact TaxCloud support to enable it.')
        );
        $this->messages->expects($this->once())
            ->method('addWarningMessage')
            ->with('Contact TaxCloud support to enable it.');
        $this->messages->expects($this->never())->method('addSuccessMessage');

        $this->observer()->execute($this->event([TaxcloudConfig::XML_PATH_CANADA_TAX_ENABLED]));
    }

    public function testConfirmedAccessIsShownAsSuccess()
    {
        $this->canadaOnForEditedStore();
        $this->checker->method('check')->willReturn(
            new CanadaAccessResult(CanadaAccessResult::ENABLED, 'Canada access confirmed', 0.13)
        );
        $this->messages->expects($this->once())->method('addSuccessMessage')->with('Canada access confirmed');
        $this->messages->expects($this->never())->method('addWarningMessage');

        $this->observer()->execute($this->event([TaxcloudConfig::XML_PATH_REST_CONNECTION_ID]));
    }

    /**
     * @dataProvider relevantPathProvider
     */
    #[DataProvider('relevantPathProvider')]
    public function testEveryPathThatDecidesAccessTriggersTheCheck(string $path)
    {
        $this->canadaOnForEditedStore();
        $this->checker->expects($this->once())->method('check')->willReturn(
            new CanadaAccessResult(CanadaAccessResult::ENABLED, 'ok')
        );

        $this->observer()->execute($this->event(['tax/taxcloud_settings/cache_lifetime', $path]));
    }

    public static function relevantPathProvider(): array
    {
        return [
            'canada setting' => [TaxcloudConfig::XML_PATH_CANADA_TAX_ENABLED],
            'api type' => [TaxcloudConfig::XML_PATH_API_TYPE],
            'v3 api key' => [TaxcloudConfig::XML_PATH_REST_API_KEY],
            'connection id' => [TaxcloudConfig::XML_PATH_REST_CONNECTION_ID],
            'v1 api id (bearer exchange)' => [TaxcloudConfig::XML_PATH_API_ID],
            'v1 api key (bearer exchange)' => [TaxcloudConfig::XML_PATH_API_KEY],
        ];
    }

    public function testAnUnrelatedSaveDoesNotCallTaxCloud()
    {
        $this->canadaOnForEditedStore();
        $this->checker->expects($this->never())->method('check');

        $this->observer()->execute($this->event(['tax/taxcloud_settings/cache_lifetime']));
    }

    public function testASaveThatDoesNotReportChangedPathsStillChecks()
    {
        $this->canadaOnForEditedStore();
        $this->checker->expects($this->once())->method('check')->willReturn(
            new CanadaAccessResult(CanadaAccessResult::ENABLED, 'ok')
        );

        $this->observer()->execute($this->event(null));
    }

    public function testNothingRunsWhenCanadianTaxIsOffForTheEditedScope()
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isCanadaTaxEnabled')->willReturnMap([[self::STORE_ID, false], [null, true]]);
        $this->checker->expects($this->never())->method('check');

        $this->observer()->execute($this->event([TaxcloudConfig::XML_PATH_CANADA_TAX_ENABLED]));
    }

    public function testNothingRunsWhenTaxCloudIsDisabledForTheEditedScope()
    {
        $this->config->method('isEnabled')->willReturnMap([[self::STORE_ID, false], [null, true]]);
        $this->config->method('isCanadaTaxEnabled')->willReturn(true);
        $this->checker->expects($this->never())->method('check');

        $this->observer()->execute($this->event([TaxcloudConfig::XML_PATH_CANADA_TAX_ENABLED]));
    }

    public function testAFailingCheckNeverBreaksTheSave()
    {
        $this->canadaOnForEditedStore();
        $this->checker->method('check')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('boom'));

        $this->observer()->execute($this->event([TaxcloudConfig::XML_PATH_CANADA_TAX_ENABLED]));
    }

    public function testTheObserverIsRegisteredOnTheTaxSectionSave()
    {
        $eventsXml = simplexml_load_file(__DIR__ . '/../../../../etc/adminhtml/events.xml');
        $this->assertNotFalse($eventsXml, 'etc/adminhtml/events.xml must be parseable');
        $observer = $eventsXml->xpath('//event[@name="admin_system_config_changed_section_tax"]/observer');
        $this->assertCount(1, $observer);
        $this->assertSame(CheckCanadaAccessOnSave::class, (string) $observer[0]['instance']);
    }
}
