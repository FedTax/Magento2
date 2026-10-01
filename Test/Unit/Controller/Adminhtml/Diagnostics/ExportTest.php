<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Controller\Adminhtml\Diagnostics;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Controller\Adminhtml\Diagnostics\Export;

/**
 * The export carries customer data: its own ACL resource, POST only (so the
 * backend validator enforces the form key), never reachable by GET.
 */
class ExportTest extends TestCase
{
    public function testGuardedByTheDedicatedAclResource()
    {
        $this->assertSame('Taxcloud_Magento2::diagnostics', Export::ADMIN_RESOURCE);
        $this->assertNotSame('Magento_Tax::config_tax', Export::ADMIN_RESOURCE);
    }

    public function testPostOnly()
    {
        $interfaces = class_implements(Export::class);

        $this->assertArrayHasKey(HttpPostActionInterface::class, $interfaces);
        $this->assertArrayNotHasKey(HttpGetActionInterface::class, $interfaces);
    }

    public function testAclResourceIsDeclared()
    {
        $acl = new \DOMDocument();
        $acl->load(__DIR__ . '/../../../../../etc/acl.xml');
        $xpath = new \DOMXPath($acl);

        $this->assertSame(1, $xpath->query('//resource[@id="Taxcloud_Magento2::diagnostics"]')->length);
    }
}
