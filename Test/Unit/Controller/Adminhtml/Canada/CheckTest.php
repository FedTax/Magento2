<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Controller\Adminhtml\Canada;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Controller\Adminhtml\Canada\Check;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Canada\CanadaAccessResult;
use Taxcloud\Magento2\Model\Canada\ConfigScopeStore;

/**
 * The endpoint behind Check Canada Access: tax-configuration permission,
 * POST only, the edited scope handed to the checker, and the outcome returned
 * so the button can tell "not enabled" from "could not check".
 */
#[AllowMockObjectsWithoutExpectations]
class CheckTest extends TestCase
{
    public function testGuardsWithTheTaxConfigurationResourceAndPostOnly()
    {
        $this->assertSame('Magento_Tax::config_tax', Check::ADMIN_RESOURCE);
        $this->assertContains(HttpPostActionInterface::class, class_implements(Check::class));
    }

    public function testRunsTheCheckForTheEditedScopeAndReturnsTheOutcome()
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['website', null, '1'], ['store', null, '4']]);

        $responseData = null;
        $json = $this->createMock(JsonResult::class);
        $json->method('setData')->willReturnCallback(function ($data) use (&$responseData, $json) {
            $responseData = $data;
            return $json;
        });
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_JSON)->willReturn($json);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $scopeStore = $this->createMock(ConfigScopeStore::class);
        $scopeStore->method('resolve')->with('1', '4')->willReturn('4');

        $checker = $this->createMock(CanadaAccessChecker::class);
        $checker->expects($this->once())->method('check')->with('4')->willReturn(
            new CanadaAccessResult(CanadaAccessResult::NOT_ENABLED, 'Contact TaxCloud support to enable it.')
        );

        (new Check($context, $checker, $scopeStore))->execute();

        $this->assertSame(
            [
                'success' => false,
                'outcome' => 'not_enabled',
                'message' => 'Contact TaxCloud support to enable it.',
            ],
            $responseData
        );
    }
}
