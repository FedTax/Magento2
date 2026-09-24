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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Certificate;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\AttachmentWriteScope;

class AttachmentWriteScopeTest extends TestCase
{
    public function testClosedUntilAWriteRuns(): void
    {
        $scope = new AttachmentWriteScope();

        $this->assertFalse($scope->isOpen());
        $this->assertSame('saved', $scope->run(function () use ($scope) {
            $this->assertTrue($scope->isOpen());

            return 'saved';
        }));
        $this->assertFalse($scope->isOpen());
    }

    public function testANestedWriteDoesNotCloseTheOuterOne(): void
    {
        $scope = new AttachmentWriteScope();

        $scope->run(function () use ($scope) {
            $scope->run(function () {
                return null;
            });

            $this->assertTrue($scope->isOpen(), 'still inside the outer write');
        });

        $this->assertFalse($scope->isOpen());
    }

    public function testAFailedWriteClosesTheScope(): void
    {
        $scope = new AttachmentWriteScope();

        try {
            $scope->run(function () {
                throw new \RuntimeException('save failed');
            });
            $this->fail('the failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('save failed', $e->getMessage());
        }

        $this->assertFalse(
            $scope->isOpen(),
            'a scope left open would let every later customer save through the guard'
        );
    }
}
