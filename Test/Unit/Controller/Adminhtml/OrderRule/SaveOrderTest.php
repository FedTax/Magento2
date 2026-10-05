<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Controller\Adminhtml\OrderRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;
use Taxcloud\Magento2\Controller\Adminhtml\OrderRule\Delete;
use Taxcloud\Magento2\Controller\Adminhtml\OrderRule\Save;
use Taxcloud\Magento2\Controller\Adminhtml\OrderRule\SaveOrder;

/**
 * The drag-reorder endpoint and the rules controllers' guards.
 */
#[AllowMockObjectsWithoutExpectations]
class SaveOrderTest extends TestCase
{
    private $responseData;
    private $responseCode;

    private function controller($ruleIds, OrderRuleRepositoryInterface $repository): SaveOrder
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['rule_ids', null, $ruleIds]]);

        $json = $this->createMock(JsonResult::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->responseData = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($json) {
            $this->responseCode = $code;
            return $json;
        });
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_JSON)->willReturn($json);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);

        return new SaveOrder($context, $repository);
    }

    public function testControllersAreGuardedByTheRulesPermissionAndWritesArePostOnly()
    {
        foreach ([SaveOrder::class, Save::class, Delete::class] as $class) {
            $this->assertSame('Taxcloud_Magento2::order_rules', $class::ADMIN_RESOURCE);
            $this->assertContains(HttpPostActionInterface::class, class_implements($class), $class);
        }
    }

    public function testThePostedOrderIsStored()
    {
        $repository = $this->createMock(OrderRuleRepositoryInterface::class);
        $repository->expects($this->once())->method('reorder')->with([3, 1, 2]);

        $this->controller(['3', '1', '2'], $repository)->execute();

        $this->assertSame(['success' => true], $this->responseData);
    }

    public function testAnEmptyPostIsRejected()
    {
        $repository = $this->createMock(OrderRuleRepositoryInterface::class);
        $repository->expects($this->never())->method('reorder');

        $this->controller(null, $repository)->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertFalse($this->responseData['success']);
    }

    /**
     * The page puts the rows back when the save fails, so the failure must
     * come back as an error the script can see.
     */
    public function testAFailedSaveIsReportedAsAnError()
    {
        $repository = $this->createMock(OrderRuleRepositoryInterface::class);
        $repository->method('reorder')->willThrowException(new \RuntimeException('deadlock'));

        $this->controller([1, 2], $repository)->execute();

        $this->assertSame(500, $this->responseCode);
        $this->assertFalse($this->responseData['success']);
        $this->assertStringNotContainsString('deadlock', $this->responseData['message']);
    }

    /**
     * @dataProvider prefixTextProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('prefixTextProvider')]
    public function testPrefixTextIsSplitOnLinesAndCommas(string $text, array $expected)
    {
        $this->assertSame($expected, array_values(array_filter(Save::splitPrefixes($text), 'strlen')));
    }

    public static function prefixTextProvider(): array
    {
        return [
            'one per line' => ["AMZ-\nEBAY-", ['AMZ-', 'EBAY-']],
            'windows lines' => ["AMZ-\r\nEBAY-", ['AMZ-', 'EBAY-']],
            'commas' => ['AMZ-,EBAY-', ['AMZ-', 'EBAY-']],
            'empty' => ['', []],
        ];
    }
}
