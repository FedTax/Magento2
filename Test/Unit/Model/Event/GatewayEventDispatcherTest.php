<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Event;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Event\GatewayEventDispatcher;

/**
 * Covers the before/after event handoff extracted from Model\Api: observer
 * edits to the DataObject holder are carried back to the caller, and the
 * per-operation context travels alongside the holder under the `obj` key.
 */
#[AllowMockObjectsWithoutExpectations]
class GatewayEventDispatcherTest extends TestCase
{
    private $eventManager;
    private $objectFactory;

    protected function setUp(): void
    {
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->objectFactory = $this->createMock(DataObjectFactory::class);
        // Real DataObject round-trips setParams/getParams and setResult/getResult.
        $this->objectFactory->method('create')->willReturnCallback(fn () => new DataObject());
    }

    private function dispatcher(): GatewayEventDispatcher
    {
        return new GatewayEventDispatcher($this->eventManager, $this->objectFactory);
    }

    public function testDispatchBeforeReturnsObserverModifiedParams()
    {
        $this->eventManager->method('dispatch')->willReturnCallback(function ($event, $data) {
            $this->assertSame('taxcloud_lookup_before', $event);
            $this->assertArrayHasKey('obj', $data);
            // Observer rewrites the request (e.g. verified destination address).
            $data['obj']->setParams(['destination' => 'verified']);
        });

        $result = $this->dispatcher()->dispatchBefore('taxcloud_lookup_before', ['destination' => 'raw']);

        $this->assertSame(['destination' => 'verified'], $result);
    }

    public function testDispatchBeforePassesContextAlongsideHolder()
    {
        $order = new \stdClass();
        $captured = null;
        $this->eventManager->method('dispatch')->willReturnCallback(function ($event, $data) use (&$captured) {
            $captured = $data;
        });

        $this->dispatcher()->dispatchBefore('evt', ['a' => 1], ['order' => $order]);

        $this->assertArrayHasKey('obj', $captured);
        $this->assertSame($order, $captured['order']);
    }

    public function testDispatchAfterReturnsObserverModifiedResult()
    {
        $this->eventManager->method('dispatch')->willReturnCallback(function ($event, $data) {
            $data['obj']->setResult(['ResponseType' => 'Modified']);
        });

        $result = $this->dispatcher()->dispatchAfter('taxcloud_lookup_after', ['ResponseType' => 'OK']);

        $this->assertSame(['ResponseType' => 'Modified'], $result);
    }

    public function testDispatchAfterReturnsOriginalResultWhenNoObserverModifies()
    {
        $this->eventManager->method('dispatch'); // no-op observers

        $result = $this->dispatcher()->dispatchAfter('evt', ['ResponseType' => 'OK']);

        $this->assertSame(['ResponseType' => 'OK'], $result);
    }
}
