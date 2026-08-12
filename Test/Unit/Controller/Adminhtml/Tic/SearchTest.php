<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Controller\Adminhtml\Tic;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Api\TicLookupInterface;
use Taxcloud\Magento2\Controller\Adminhtml\Tic\Search;
use Taxcloud\Magento2\Model\Tic\TicSearchResult;
use Taxcloud\Magento2\Model\Tic\TicSuggestion;

/**
 * The lookup endpoint is new attack surface: it is authenticated, it reaches
 * TaxCloud, and it is called from three different admin screens. What it must
 * get right is who may call it and what it hands back.
 */
#[AllowMockObjectsWithoutExpectations]
class SearchTest extends TestCase
{
    /**
     * @var TicLookupInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $lookup;

    /**
     * @var RequestInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var AuthorizationInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $authorization;

    /**
     * @var array<string, mixed>|null
     */
    private $responseData;

    /**
     * @param array<string, string|null> $params
     * @return Search
     */
    private function controller(array $params = []): Search
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnCallback(
            static function ($name, $default = null) use ($params) {
                return array_key_exists($name, $params) ? $params[$name] : $default;
            }
        );

        $this->authorization = $this->createMock(AuthorizationInterface::class);

        $json = $this->createMock(JsonResult::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->responseData = $data;

            return $json;
        });

        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($json);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getAuthorization')->willReturn($this->authorization);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $this->lookup = $this->createMock(TicLookupInterface::class);

        return new Search($context, $this->lookup);
    }

    /**
     * Invoke the protected authorization hook the framework calls.
     *
     * @param Search $controller
     * @return bool
     */
    private function isAllowed(Search $controller): bool
    {
        $method = new \ReflectionMethod($controller, '_isAllowed');
        $method->setAccessible(true);

        return (bool) $method->invoke($controller);
    }

    /**
     * A TIC can be set from the tax configuration, a product, or a category —
     * each guarded by its own resource. A catalog manager editing a product's
     * TIC holds no tax-config permission, and must not get a search box that
     * silently returns nothing.
     *
     * @return array<string, array{string}>
     */
    public static function ticResourceProvider(): array
    {
        return [
            'tax configuration' => ['Magento_Tax::config_tax'],
            'catalog products' => ['Magento_Catalog::products'],
            'catalog categories' => ['Magento_Catalog::categories'],
        ];
    }

    /**
     * @dataProvider ticResourceProvider
     * @param string $granted
     */
    #[DataProvider('ticResourceProvider')]
    public function testAnyResourceThatReachesATicFieldGrantsAccess(string $granted)
    {
        $controller = $this->controller();
        $this->authorization->method('isAllowed')->willReturnCallback(
            static function ($resource) use ($granted) {
                return $resource === $granted;
            }
        );

        $this->assertTrue($this->isAllowed($controller), $granted . ' should grant access');
    }

    public function testSessionWithNoRelevantPermissionIsRefused()
    {
        $controller = $this->controller();
        $this->authorization->method('isAllowed')->willReturn(false);

        $this->assertFalse($this->isAllowed($controller));
    }

    public function testSearchIsDelegatedWithTheEditedStore()
    {
        $controller = $this->controller(['query' => 'candy', 'store' => '7']);

        $this->lookup->expects($this->once())
            ->method('search')
            ->with('candy', 7)
            ->willReturn(new TicSearchResult(TicSearchResult::AVAILABLE, []));

        $controller->execute();
    }

    /**
     * The globally scoped product attribute sends no store, which must resolve
     * at default scope rather than being coerced to store 0.
     */
    public function testAbsentStoreResolvesAtDefaultScope()
    {
        $controller = $this->controller(['query' => 'candy']);

        $this->lookup->expects($this->once())
            ->method('search')
            ->with('candy', null)
            ->willReturn(new TicSearchResult(TicSearchResult::AVAILABLE, []));

        $controller->execute();
    }

    public function testResolveModeAsksForTheStoredCodesMeaning()
    {
        $controller = $this->controller(['query' => '40010', 'mode' => 'resolve']);

        $this->lookup->expects($this->once())
            ->method('resolve')
            ->willReturn(new TicSearchResult(TicSearchResult::AVAILABLE, []));
        $this->lookup->expects($this->never())->method('search');

        $controller->execute();
    }

    public function testSuggestionsAreReturnedInTheDocumentedShape()
    {
        $controller = $this->controller(['query' => 'candy']);
        $this->lookup->method('search')->willReturn(new TicSearchResult(
            TicSearchResult::AVAILABLE,
            [new TicSuggestion('40010', 'Candy', 'Sweet things', 0.94)]
        ));

        $controller->execute();

        $this->assertTrue($this->responseData['available']);
        $this->assertSame(
            ['code' => '40010', 'label' => 'Candy', 'detail' => 'Sweet things', 'score' => 0.94],
            $this->responseData['suggestions'][0]
        );
    }

    /**
     * "Could not look" must stay distinguishable from "nothing matched", or the
     * field would tell a merchant their valid TIC does not exist.
     */
    public function testUnavailabilityIsReportedWithItsReason()
    {
        $controller = $this->controller(['query' => 'candy']);
        $this->lookup->method('search')->willReturn(
            new TicSearchResult(TicSearchResult::UNAVAILABLE, [], TicSearchResult::REASON_NOT_CONFIGURED)
        );

        $controller->execute();

        $this->assertFalse($this->responseData['available']);
        $this->assertSame(TicSearchResult::REASON_NOT_CONFIGURED, $this->responseData['reason']);
        $this->assertArrayNotHasKey('suggestions', $this->responseData);
    }

    /**
     * TaxCloud is reached server-side; nothing about how we authenticated may
     * travel back to the browser.
     */
    public function testResponseCarriesNoCredentialMaterial()
    {
        $controller = $this->controller(['query' => 'candy']);
        $this->lookup->method('search')->willReturn(new TicSearchResult(
            TicSearchResult::AVAILABLE,
            [new TicSuggestion('40010', 'Candy')]
        ));

        $controller->execute();

        $encoded = json_encode($this->responseData);
        foreach (['apiKey', 'apiLoginID', 'api_key', 'api_id', 'connectionId', 'Bearer', 'X-API-KEY'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $encoded);
        }
    }
}
