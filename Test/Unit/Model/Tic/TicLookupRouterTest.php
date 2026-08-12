<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Tic;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Tic\RestTicLookup;
use Taxcloud\Magento2\Model\Tic\SoapTicLookup;
use Taxcloud\Magento2\Model\Tic\TicLookupRouter;
use Taxcloud\Magento2\Model\Tic\TicSearchResult;
use Taxcloud\Magento2\Model\Tic\TicSuggestion;

/**
 * TIC lookup obeys the same api_type that routes tax operations. A picker that
 * quietly searched the other API would be the exception that erodes the rule
 * the switcher established — and on a mixed fleet it would show one store's
 * admin the wrong catalogue entirely.
 */
#[AllowMockObjectsWithoutExpectations]
class TicLookupRouterTest extends TestCase
{
    /**
     * @var SoapTicLookup&\PHPUnit\Framework\MockObject\MockObject
     */
    private $soap;

    /**
     * @var RestTicLookup&\PHPUnit\Framework\MockObject\MockObject
     */
    private $rest;

    /**
     * @param array $configMap
     * @return TicLookupRouter
     */
    private function router(array $configMap): TicLookupRouter
    {
        $this->soap = $this->createMock(SoapTicLookup::class);
        $this->rest = $this->createMock(RestTicLookup::class);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        return new TicLookupRouter($this->soap, $this->rest, new TaxcloudConfig($scopeConfig));
    }

    /**
     * @param string|null $apiType
     * @param int|null $store
     * @return array
     */
    private static function apiType($apiType, $store = null): array
    {
        return [TaxcloudConfig::XML_PATH_API_TYPE, ScopeInterface::SCOPE_STORE, $store, $apiType];
    }

    /**
     * @param string $code
     * @return TicSearchResult
     */
    private static function found(string $code): TicSearchResult
    {
        return new TicSearchResult(TicSearchResult::AVAILABLE, [new TicSuggestion($code, 'label')]);
    }

    public function testRestStoreSearchesTheRestBackend()
    {
        $router = $this->router([self::apiType(ApiType::REST)]);

        $this->rest->expects($this->once())->method('search')->willReturn(self::found('1'));
        $this->soap->expects($this->never())->method('search');

        $router->search('candy');
    }

    public function testSoapStoreSearchesTheSoapBackend()
    {
        $router = $this->router([self::apiType(ApiType::SOAP)]);

        $this->soap->expects($this->once())->method('search')->willReturn(self::found('1'));
        $this->rest->expects($this->never())->method('search');

        $router->search('candy');
    }

    public function testResolveIsRoutedToo()
    {
        $router = $this->router([self::apiType(ApiType::REST)]);

        $this->rest->expects($this->once())->method('resolve')->willReturn(self::found('1'));
        $this->soap->expects($this->never())->method('resolve');

        $router->resolve('40010');
    }

    /**
     * The store being edited decides, not whichever store happens to be active.
     */
    public function testDispatchResolvesApiTypeForThePassedStore()
    {
        $router = $this->router([
            self::apiType(ApiType::SOAP, null),
            self::apiType(ApiType::REST, 7),
        ]);

        $this->rest->expects($this->once())->method('search')->with('candy', 7)->willReturn(self::found('1'));
        $this->soap->expects($this->never())->method('search');

        $router->search('candy', 7);
    }

    public function testMixedFleetRoutesPerStore()
    {
        $router = $this->router([
            self::apiType(ApiType::SOAP, 1),
            self::apiType(ApiType::REST, 2),
        ]);

        $this->soap->expects($this->once())->method('search')->with('candy', 1)->willReturn(self::found('a'));
        $this->rest->expects($this->once())->method('search')->with('candy', 2)->willReturn(self::found('b'));

        $this->assertSame('a', $router->search('candy', 1)->getSuggestions()[0]->getCode());
        $this->assertSame('b', $router->search('candy', 2)->getSuggestions()[0]->getCode());
    }

    /**
     * Mirrors TaxcloudConfig::getApiType()'s own default, so the picker and the
     * transport can never disagree about an unset value.
     */
    public function testUnsetApiTypeFollowsTheConfigDefault()
    {
        $router = $this->router([]);

        $this->rest->expects($this->once())->method('search')->willReturn(self::found('1'));

        $router->search('candy');
    }
}
