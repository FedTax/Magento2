<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\ConnectionTester;
use Taxcloud\Magento2\Model\Gateway\PingResult;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestCredentials;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapPing;

/**
 * The admin Test Connection workflow: entered values win, blanks and obscured
 * placeholders fall back to the saved config of the edited scope, the selected
 * API type picks the transport, and every outcome maps to a message that names
 * the credential to fix without echoing its value.
 */
#[AllowMockObjectsWithoutExpectations]
class ConnectionTesterTest extends TestCase
{
    /**
     * @var RestClient&\PHPUnit\Framework\MockObject\MockObject
     */
    private $restClient;

    /**
     * @var SoapPing&\PHPUnit\Framework\MockObject\MockObject
     */
    private $soapPing;

    /**
     * @var StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManager;

    private function tester(array $configMap = []): ConnectionTester
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        $this->restClient = $this->createMock(RestClient::class);
        $this->soapPing = $this->createMock(SoapPing::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        return new ConnectionTester(
            new TaxcloudConfig($scopeConfig),
            $this->restClient,
            $this->soapPing,
            $this->storeManager
        );
    }

    // ─── REST dispatch ───────────────────────────────────────────────────────

    public function testRestSuccessUsesEnteredCredentials()
    {
        $tester = $this->tester();

        $this->restClient->expects($this->once())
            ->method('ping')
            ->with($this->callback(
                static fn (RestCredentials $c) =>
                    $c->getApiKey() === 'entered-key' && $c->getConnectionId() === 'entered-conn'
            ))
            ->willReturn(new PingResult(PingResult::OK));
        $this->soapPing->expects($this->never())->method('ping');

        $outcome = $tester->test([
            'api_type' => 'rest',
            'rest_api_key' => 'entered-key',
            'rest_connection_id' => 'entered-conn',
        ]);

        $this->assertTrue($outcome['success']);
    }

    /**
     * The Encrypted backend renders a saved key as asterisks; the tester must
     * treat that placeholder as "use the saved key for the edited scope".
     */
    public function testObscuredRestKeyFallsBackToSavedValueForTheEditedScope()
    {
        $tester = $this->tester([
            [TaxcloudConfig::XML_PATH_REST_API_KEY, ScopeInterface::SCOPE_STORE, '7', 'saved-store-key'],
        ]);

        $this->restClient->expects($this->once())
            ->method('ping')
            ->with(
                $this->callback(static fn (RestCredentials $c) => $c->getApiKey() === 'saved-store-key'),
                '7'
            )
            ->willReturn(new PingResult(PingResult::OK));

        $outcome = $tester->test(
            ['api_type' => 'rest', 'rest_api_key' => '******', 'rest_connection_id' => 'conn'],
            null,
            '7'
        );

        $this->assertTrue($outcome['success']);
    }

    public function testRestAuthFailurePointsAtTheApiKeyWithoutEchoingIt()
    {
        $tester = $this->tester();
        $this->restClient->method('ping')->willReturn(new PingResult(PingResult::AUTH_FAILED));

        $outcome = $tester->test([
            'api_type' => 'rest',
            'rest_api_key' => 'secret-key-value',
            'rest_connection_id' => 'conn',
        ]);

        $this->assertFalse($outcome['success']);
        $message = (string) $outcome['message'];
        $this->assertStringContainsString('API Key', $message);
        $this->assertStringContainsString('Developer', $message);
        $this->assertStringNotContainsString('secret-key-value', $message);
    }

    public function testRestUnknownConnectionPointsAtTheConnectionId()
    {
        $tester = $this->tester();
        $this->restClient->method('ping')->willReturn(new PingResult(PingResult::UNKNOWN_CONNECTION));

        $outcome = $tester->test([
            'api_type' => 'rest',
            'rest_api_key' => 'key',
            'rest_connection_id' => 'wrong-conn-id',
        ]);

        $this->assertFalse($outcome['success']);
        $message = (string) $outcome['message'];
        $this->assertStringContainsString('Connection ID', $message);
        $this->assertStringNotContainsString('wrong-conn-id', $message);
    }

    public function testRestTransportErrorCarriesTheReason()
    {
        $tester = $this->tester();
        $this->restClient->method('ping')
            ->willReturn(new PingResult(PingResult::TRANSPORT_ERROR, 'HTTP 500'));

        $outcome = $tester->test([
            'api_type' => 'rest', 'rest_api_key' => 'key', 'rest_connection_id' => 'conn',
        ]);

        $this->assertFalse($outcome['success']);
        $this->assertStringContainsString('HTTP 500', (string) $outcome['message']);
    }

    /**
     * Missing input must short-circuit with a validation message: no HTTP
     * request may leave the box.
     */
    public function testMissingRestCredentialsShortCircuitWithoutAnyOutboundCall()
    {
        $tester = $this->tester();
        $this->restClient->expects($this->never())->method('ping');
        $this->soapPing->expects($this->never())->method('ping');

        $noKey = $tester->test(['api_type' => 'rest', 'rest_connection_id' => 'conn']);
        $this->assertFalse($noKey['success']);
        $this->assertStringContainsString('API Key', (string) $noKey['message']);

        $noConn = $tester->test(['api_type' => 'rest', 'rest_api_key' => 'key']);
        $this->assertFalse($noConn['success']);
        $this->assertStringContainsString('Connection ID', (string) $noConn['message']);
    }

    // ─── SOAP dispatch ───────────────────────────────────────────────────────

    public function testSoapSuccessUsesEnteredCredentials()
    {
        $tester = $this->tester();

        $this->soapPing->expects($this->once())
            ->method('ping')
            ->with('entered-id', 'entered-key', null)
            ->willReturn(new PingResult(PingResult::OK));
        $this->restClient->expects($this->never())->method('ping');

        $outcome = $tester->test([
            'api_type' => 'soap', 'api_id' => 'entered-id', 'api_key' => 'entered-key',
        ]);

        $this->assertTrue($outcome['success']);
    }

    public function testBlankSoapCredentialsFallBackToSavedValues()
    {
        $tester = $this->tester([
            [TaxcloudConfig::XML_PATH_API_ID, ScopeInterface::SCOPE_STORE, null, 'saved-id'],
            [TaxcloudConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, null, 'saved-key'],
        ]);

        $this->soapPing->expects($this->once())
            ->method('ping')
            ->with('saved-id', 'saved-key', null)
            ->willReturn(new PingResult(PingResult::OK));

        $outcome = $tester->test(['api_type' => 'soap', 'api_id' => '', 'api_key' => '']);

        $this->assertTrue($outcome['success']);
    }

    public function testSoapAuthFailurePointsAtThePairWithoutEchoingValues()
    {
        $tester = $this->tester();
        $this->soapPing->method('ping')->willReturn(new PingResult(PingResult::AUTH_FAILED));

        $outcome = $tester->test([
            'api_type' => 'soap', 'api_id' => 'my-login', 'api_key' => 'my-secret',
        ]);

        $this->assertFalse($outcome['success']);
        $message = (string) $outcome['message'];
        $this->assertStringContainsString('API ID / API Key', $message);
        $this->assertStringNotContainsString('my-login', $message);
        $this->assertStringNotContainsString('my-secret', $message);
    }

    public function testMissingSoapCredentialsShortCircuitWithoutAnyOutboundCall()
    {
        $tester = $this->tester();
        $this->soapPing->expects($this->never())->method('ping');

        $outcome = $tester->test(['api_type' => 'soap', 'api_id' => 'id-only']);

        $this->assertFalse($outcome['success']);
    }

    // ─── Type + scope resolution ─────────────────────────────────────────────

    /**
     * No usable api_type in the request → the saved setting of the edited
     * scope decides which transport is tested.
     */
    public function testMissingApiTypeFallsBackToTheSavedSettingOfTheEditedScope()
    {
        $tester = $this->tester([
            [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, '7', 'soap'],
            [TaxcloudConfig::XML_PATH_API_ID, ScopeInterface::SCOPE_STORE, '7', 'saved-id'],
            [TaxcloudConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, '7', 'saved-key'],
        ]);

        $this->soapPing->expects($this->once())
            ->method('ping')
            ->with('saved-id', 'saved-key', '7')
            ->willReturn(new PingResult(PingResult::OK));

        $outcome = $tester->test([], null, '7');

        $this->assertTrue($outcome['success']);
    }

    /**
     * Editing a website scope resolves through that website's default store so
     * website-level values (and default-scope inheritance) apply.
     */
    public function testWebsiteScopeResolvesThroughTheWebsitesDefaultStore()
    {
        $tester = $this->tester();

        $defaultStore = $this->createMock(\Magento\Store\Model\Store::class);
        $defaultStore->method('getId')->willReturn(3);
        $website = $this->createMock(\Magento\Store\Model\Website::class);
        $website->method('getDefaultStore')->willReturn($defaultStore);
        $this->storeManager->method('getWebsite')->with('2')->willReturn($website);

        $this->restClient->expects($this->once())
            ->method('ping')
            ->with($this->anything(), $defaultStore)
            ->willReturn(new PingResult(PingResult::OK));

        $outcome = $tester->test(
            ['api_type' => 'rest', 'rest_api_key' => 'key', 'rest_connection_id' => 'conn'],
            '2',
            null
        );

        $this->assertTrue($outcome['success']);
    }
}
