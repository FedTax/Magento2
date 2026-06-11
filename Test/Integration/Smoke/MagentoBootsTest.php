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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Smoke;

use Magento\Framework\Module\ModuleList;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Test\Integration\TestEnvironment;

/**
 * Smoke test: Magento bootstrapped successfully and Taxcloud_Magento2 is in
 * the registered-module list.
 *
 * Exists to prove the integration test pipeline works end-to-end. Real
 * behavioural tests for observers, captures, refunds, etc. land in follow-up
 * tickets per the missing-tests spec.
 */
class MagentoBootsTest extends TestCase
{
    public function testObjectManagerIsReachable(): void
    {
        $this->assertNotNull(
            TestEnvironment::getObjectManager(),
            'Magento ObjectManager not reachable — Test/Integration/bootstrap.php failed.'
        );
    }

    public function testTaxcloudModuleIsRegistered(): void
    {
        /** @var ModuleList $moduleList */
        $moduleList = TestEnvironment::get(ModuleList::class);

        $this->assertTrue(
            $moduleList->has('Taxcloud_Magento2'),
            'Taxcloud_Magento2 is not in the registered module list. Verify the '
            . 'install script symlinked this repo into app/code/Taxcloud/Magento2 '
            . 'and that setup:upgrade ran cleanly.'
        );
    }
}
