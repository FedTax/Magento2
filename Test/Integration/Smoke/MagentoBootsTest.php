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
use Taxcloud\Magento2\Model\Api;
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

    /**
     * Runtime proof that setup:di:compile produced loadable generated code for
     * this module: the ObjectManager can build Taxcloud\Magento2\Model\Api
     * (and the constructor-injected graph behind it) without error.
     *
     * The install pipeline's `setup:di:compile` step (run under `set -e`)
     * already gates *compilation* — a failure there aborts the install before
     * PHPUnit runs. This adds the complementary runtime signal that the
     * compiled graph is actually resolvable, which is the only part of a
     * standalone DI-compilation test that isn't redundant with the install.
     */
    public function testCompiledDiGraphCanInstantiateApi(): void
    {
        $api = TestEnvironment::get(Api::class);

        $this->assertInstanceOf(
            Api::class,
            $api,
            'ObjectManager could not build Taxcloud\\Magento2\\Model\\Api from the '
            . 'compiled DI graph — check setup:di:compile output for this module.'
        );
    }
}
