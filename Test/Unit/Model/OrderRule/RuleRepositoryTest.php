<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\OrderRule\ResourceModel\Rule as RuleResource;
use Taxcloud\Magento2\Model\OrderRule\ResourceModel\Rule\Collection;
use Taxcloud\Magento2\Model\OrderRule\ResourceModel\Rule\CollectionFactory;
use Taxcloud\Magento2\Model\OrderRule\Rule;
use Taxcloud\Magento2\Model\OrderRule\RuleFactory;
use Taxcloud\Magento2\Model\OrderRule\RuleRepository;
use Taxcloud\Magento2\Model\OrderRule\RuleValidator;

#[AllowMockObjectsWithoutExpectations]
class RuleRepositoryTest extends TestCase
{
    private $resource;
    private $collectionFactory;
    private $ruleFactory;
    private $rules = [];

    protected function setUp(): void
    {
        $this->resource = $this->createMock(RuleResource::class);
        $this->ruleFactory = $this->createMock(RuleFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(function () {
            $collection = $this->createMock(Collection::class);
            $collection->method('getItems')->willReturnCallback(function () {
                return $this->rules;
            });
            return $collection;
        });
    }

    private function repository(): RuleRepository
    {
        return new RuleRepository($this->ruleFactory, $this->resource, $this->collectionFactory, new RuleValidator());
    }

    /**
     * Totals are collected several times per checkout request; the rules are
     * read once.
     */
    public function testActiveRulesAreReadOncePerRequest()
    {
        $this->rules = [RuleFixture::rule(['id' => 1])];
        $this->collectionFactory->expects($this->once())->method('create');

        $repository = $this->repository();
        $repository->getActiveRules();
        $repository->getActiveRules();
    }

    public function testSavingClearsTheCachedActiveRules()
    {
        $this->rules = [RuleFixture::rule(['id' => 1, 'sort_order' => 10])];
        $repository = $this->repository();
        $repository->getActiveRules();

        $this->rules[] = RuleFixture::rule(['id' => 2, 'sort_order' => 20]);
        $repository->save(RuleFixture::rule(['id' => 2]));

        $this->assertCount(2, $repository->getActiveRules());
    }

    /**
     * New rules go to the end of the list.
     */
    public function testANewRuleIsAppended()
    {
        $this->rules = [
            RuleFixture::rule(['id' => 1, 'sort_order' => 10]),
            RuleFixture::rule(['id' => 2, 'sort_order' => 30]),
        ];
        $rule = RuleFixture::rule(['name' => 'New']);
        $this->resource->expects($this->once())->method('save')->with($rule);

        $this->repository()->save($rule);

        $this->assertSame(40, $rule->getSortOrder());
    }

    public function testAnExistingRuleKeepsItsPosition()
    {
        $this->rules = [RuleFixture::rule(['id' => 1, 'sort_order' => 10])];
        $rule = RuleFixture::rule(['id' => 7, 'sort_order' => 70]);

        $this->repository()->save($rule);

        $this->assertSame(70, $rule->getSortOrder());
    }

    public function testAnInvalidRuleIsNotSaved()
    {
        $this->resource->expects($this->never())->method('save');
        $this->expectException(LocalizedException::class);

        $this->repository()->save(RuleFixture::rule(['name' => '']));
    }

    public function testGetByIdOfAMissingRuleThrows()
    {
        $this->ruleFactory->method('create')->willReturn(RuleFixture::rule());
        $this->expectException(NoSuchEntityException::class);

        $this->repository()->getById(99);
    }

    /**
     * The posted order is written as spaced sort orders; rules missing from
     * the post keep their relative order after the listed ones.
     */
    public function testReorderWritesTheNewOrder()
    {
        $this->rules = [
            RuleFixture::rule(['id' => 1, 'sort_order' => 10]),
            RuleFixture::rule(['id' => 2, 'sort_order' => 20]),
            RuleFixture::rule(['id' => 3, 'sort_order' => 30]),
            RuleFixture::rule(['id' => 4, 'sort_order' => 40]),
        ];

        $written = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(function ($table, $bind, $where) use (&$written) {
            $written[(int) reset($where)] = $bind['sort_order'];
            return 1;
        });
        $connection->expects($this->once())->method('commit');
        $this->resource->method('getConnection')->willReturn($connection);
        $this->resource->method('getMainTable')->willReturn('taxcloud_order_rule');

        $this->repository()->reorder([3, 1]);

        asort($written);
        $this->assertSame([3, 1, 2, 4], array_keys($written));
    }
}
