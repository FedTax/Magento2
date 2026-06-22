<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Taxcloud\Magento2\Model\Api;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Webapi\Soap\ClientFactory;
use Magento\Framework\DataObjectFactory;
use Magento\Catalog\Model\ProductFactory;
use Magento\Directory\Model\RegionFactory;
use Taxcloud\Magento2\Logger\Logger;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\DataObject;
use Taxcloud\Magento2\Model\CartItemResponseHandler;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Taxcloud\Magento2\Test\Unit\Double\SoapClientDouble;

/**
 * Verifies that TaxCloud API credentials (apiLoginID, apiKey) are never
 * written to the log file, either by the redaction helper directly or by
 * the SOAP-calling code paths that invoke it.
 */
#[AllowMockObjectsWithoutExpectations]
class ApiRedactionTest extends TestCase
{
    private const SENTINEL_API_ID  = 'SENTINEL_LOGIN_ID_DO_NOT_LEAK';
    private const SENTINEL_API_KEY = 'SENTINEL_KEY_DO_NOT_LEAK';

    /**
     * Helper #1: assert the redaction helper strips both credential keys
     * and that neither raw value appears in the formatted print_r output.
     */
    public function testRedactParamsForLogReplacesCredentialsWithPlaceholder()
    {
        $api = $this->newApi(new CapturingLogger());

        $params = [
            'apiLoginID' => self::SENTINEL_API_ID,
            'apiKey'     => self::SENTINEL_API_KEY,
            'orderID'    => 'TEST_ORDER_123',
            'cartItems'  => [],
        ];

        $redacted = $api->redactParamsForLog($params);

        // Keys preserved so operators can still confirm the fields were sent
        $this->assertArrayHasKey('apiLoginID', $redacted);
        $this->assertArrayHasKey('apiKey', $redacted);

        // Values replaced with the placeholder
        $this->assertSame(Api::REDACTED_PLACEHOLDER, $redacted['apiLoginID']);
        $this->assertSame(Api::REDACTED_PLACEHOLDER, $redacted['apiKey']);

        // Non-credential fields untouched
        $this->assertSame('TEST_ORDER_123', $redacted['orderID']);

        // Original input is not mutated by the redactor
        $this->assertSame(self::SENTINEL_API_ID, $params['apiLoginID']);
        $this->assertSame(self::SENTINEL_API_KEY, $params['apiKey']);

        // The print_r output that actually reaches the log file must not
        // contain either raw credential value.
        $formatted = print_r($redacted, true);
        $this->assertStringNotContainsString(self::SENTINEL_API_ID, $formatted);
        $this->assertStringNotContainsString(self::SENTINEL_API_KEY, $formatted);
        $this->assertStringContainsString(Api::REDACTED_PLACEHOLDER, $formatted);
    }

    /**
     * Redaction is a no-op for params that don't carry credentials
     * (defensive — keeps log shape stable for non-SOAP callers).
     */
    public function testRedactParamsForLogLeavesNonCredentialParamsAlone()
    {
        $api = $this->newApi(new CapturingLogger());

        $params = ['orderID' => 'X', 'cartItems' => []];

        $this->assertSame($params, $api->redactParamsForLog($params));
    }

    /**
     * Helper #2: drive a full SOAP path (authorizeCapture, error branch)
     * with a real capturing logger and assert no recorded log message
     * ever contains the raw apiLoginID or apiKey values.
     */
    public function testAuthorizeCaptureSoapPathDoesNotLeakCredentialsToLogger()
    {
        $logger = new CapturingLogger();

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, self::SENTINEL_API_ID],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, self::SENTINEL_API_KEY],
                ['tax/taxcloud_settings/guest_customer_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '-1'],
            ]);

        $eventManager = $this->createMock(ManagerInterface::class);
        $objectFactory = $this->createMock(DataObjectFactory::class);

        // The observer-pattern handoff: setParams seeds, getParams returns
        // the same (credential-bearing) array back to the caller. The test
        // proves redaction happens AFTER this point, before logging.
        // Real DataObject: authorizeCapture seeds it via setParams() (with the
        // SENTINEL credentials pulled from scopeConfig) and reads them back via
        // getParams() — so the credential-bearing params flow through naturally.
        $mockDataObject = new \Magento\Framework\DataObject();
        $objectFactory->method('create')->willReturn($mockDataObject);

        // Force the SOAP client to throw on every attempt so we hit the
        // error branch (which is the noisiest logger path).
        $mockSoapClient = $this->getMockBuilder(SoapClientDouble::class)
            ->onlyMethods(['authorizedWithCapture'])
            ->getMock();
        $mockSoapClient->method('authorizedWithCapture')
            ->willThrowException(new \RuntimeException('forced soap failure'));
        $soapClientFactory = $this->createMock(ClientFactory::class);
        $soapClientFactory->method('create')->willReturn($mockSoapClient);

        $api = $this->newApi(
            $logger,
            $scopeConfig,
            $eventManager,
            $objectFactory,
            $soapClientFactory
        );

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getCustomerId')->willReturn(null);
        $order->method('getQuoteId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('TEST_ORDER_REDACT');

        $result = $api->authorizeCapture($order);
        $this->assertFalse($result, 'authorizeCapture should report failure when SOAP keeps throwing');

        // The actual security assertion: nothing the logger received,
        // anywhere in the call, contained the raw credentials.
        $this->assertNotEmpty($logger->messages, 'expected at least one log entry on the error path');
        foreach ($logger->messages as $message) {
            $text = is_string($message) ? $message : print_r($message, true);
            $this->assertStringNotContainsString(
                self::SENTINEL_API_ID,
                $text,
                'apiLoginID leaked to logger in message: ' . $text
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_API_KEY,
                $text,
                'apiKey leaked to logger in message: ' . $text
            );
        }

        // And the redacted PARAMS line still appears so operators can
        // confirm both fields were sent.
        $haystack = implode("\n", array_map(
            static fn ($m) => is_string($m) ? $m : print_r($m, true),
            $logger->messages
        ));
        $this->assertStringContainsString('authorizedWithCapture PARAMS:', $haystack);
        $this->assertStringContainsString(Api::REDACTED_PLACEHOLDER, $haystack);
    }

    /**
     * Build an Api instance wired with mocks. Only scopeConfig, eventManager
     * and objectFactory are exposed for overriding; everything else is a
     * harmless stub for these tests.
     */
    private function newApi(
        Logger $logger,
        $scopeConfig = null,
        $eventManager = null,
        $objectFactory = null,
        $soapClientFactory = null
    ): Api {
        return new Api(
            $scopeConfig   ?: $this->createMock(ScopeConfigInterface::class),
            $this->createMock(CacheInterface::class),
            $eventManager  ?: $this->createMock(ManagerInterface::class),
            $soapClientFactory ?: $this->createMock(ClientFactory::class),
            $objectFactory ?: $this->createMock(DataObjectFactory::class),
            $this->createMock(ProductFactory::class),
            $this->createMock(RegionFactory::class),
            $logger,
            $this->createMock(SerializerInterface::class),
            $this->createMock(CartItemResponseHandler::class),
            $this->createMock(ProductTicService::class),
            $this->createMock(TaxCalculationInterface::class),
            $this->createMock(QuoteDetailsInterfaceFactory::class),
            $this->createMock(QuoteDetailsItemInterfaceFactory::class),
            $this->createMock(TaxClassKeyInterfaceFactory::class),
            $this->createMock(AddressInterfaceFactory::class),
            $this->createMock(RegionInterfaceFactory::class),
            $this->createMock(RefundDistributor::class)
        );
    }
}

/**
 * Logger stand-in that records every message routed through info() / error()
 * / debug(). The SUT calls $this->tclogger->info(...) on a
 * Taxcloud\Magento2\Logger\Logger instance (a Monolog\Logger subclass), so
 * overriding with Monolog-compatible signatures gives a truthful capture of
 * exactly what would have been written to disk.
 */
class CapturingLogger extends Logger
{
    /** @var array<int,mixed> */
    public array $messages = [];

    public function __construct()
    {
        parent::__construct('taxcloud-test');
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = $message;
    }
}
