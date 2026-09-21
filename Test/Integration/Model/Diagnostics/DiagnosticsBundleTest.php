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

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model\Diagnostics;

use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Controller\Adminhtml\Diagnostics\Export;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleGenerator;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;

/**
 * The diagnostics bundle against a real install: real DI wiring for every
 * section, real configuration and database, real orders placed through
 * checkout, and the real ACL tree.
 *
 * The probe is turned off throughout — these tests must not depend on the
 * network — and is covered by the unit suite.
 */
class DiagnosticsBundleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // State the trigger this class depends on rather than inheriting
        // whatever ran before it: the seeded store captures on payment, and
        // the per-order bundle is built from capture log lines, which only exist once the order is captured.
        $this->setCaptureTrigger(CaptureTrigger::ORDER_CREATION);
        $this->installSoapMock();
        $this->setScopedConfig(TaxcloudConfig::XML_PATH_LOGGING, (string) TaxcloudConfig::LOGGING_BASIC);
        $this->get(TypeListInterface::class)->cleanType('config');
        $this->mutateSharedInstances([TaxCollectorDiagnostics::class]);
    }

    /**
     * @param BundleRequest $request
     * @return array{0: array<string, string>, 1: \Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleResult}
     */
    private function generate(BundleRequest $request): array
    {
        $result = $this->get(BundleGenerator::class)->generate($request);
        $this->assertFileExists($result->getPath());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result->getPath()));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($result->getPath());

        return [$entries, $result];
    }

    public function testGlobalBundleEndToEnd(): void
    {
        [$entries, $result] = $this->generate(
            new BundleRequest(BundleRequest::SCOPE_DEFAULT, null, false, 'standard', false, 'integration')
        );

        foreach (['summary.md', 'manifest.json', 'settings.json', 'magento-tax.json', 'modules.json',
            'environment.json', 'collector-diagnostics.json', 'probe.json'] as $file) {
            $this->assertArrayHasKey($file, $entries, "$file is part of every bundle");
        }
        $this->assertSame([], $result->getFailures(), 'every section collects on a real install');
        $this->assertMatchesRegularExpression('/^taxcloud-diagnostics-default-\d{8}-\d{6}\.zip$/', $result->getFileName());

        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame(BundleGenerator::SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertGreaterThanOrEqual(2, count($manifest['scope']['stores']), 'default scope covers every store');

        $settings = json_decode($entries['settings.json'], true);
        $this->assertSame('1', (string) $settings['settings']['enabled']['effective']['default']['value']);

        $modules = json_decode($entries['modules.json'], true);
        $this->assertContains('Taxcloud_Magento2', array_column($modules['modules'], 'name'));

        $collector = json_decode($entries['collector-diagnostics.json'], true);
        $this->assertTrue($collector['stores']['default']['healthy']);

        $this->assertStringContainsString('## Blockers', $entries['summary.md']);
        $this->assertStringContainsString('Bundle schema ' . BundleGenerator::SCHEMA_VERSION, $entries['summary.md']);

        $config = $this->get(TaxcloudConfig::class);
        $secrets = array_filter([
            (string) $config->getApiId(),
            (string) $config->getApiKey(),
            (string) $config->getRestApiKey(),
        ], static function ($secret) {
            return strlen($secret) >= 6;
        });
        $this->assertNotEmpty($secrets, 'the seeded install has credentials to leak');
        foreach ($entries as $name => $content) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $content, "$name must not contain a credential");
            }
        }
    }

    public function testPerOrderBundleIsNarrowedToThatOrder(): void
    {
        $wanted = $this->placeOrder();
        $other = $this->placeOrder();

        [$entries] = $this->generate(new BundleRequest(
            BundleRequest::SCOPE_DEFAULT,
            null,
            false,
            'standard',
            false,
            'integration',
            BundleRequest::ORIGIN_ADMIN,
            (int) $wanted->getId(),
            null,
            0
        ));

        $order = json_decode($entries['order.json'], true);
        $this->assertSame($wanted->getIncrementId(), $order['increment_id']);
        $this->assertNotEmpty($order['items']['lines']);
        $this->assertNotEmpty($order['items']['lines'][0]['tic_source']);

        $this->assertArrayHasKey('logs/taxcloud.log', $entries, 'checkout and capture of the order were logged');
        $log = $entries['logs/taxcloud.log'];
        $this->assertStringContainsString('"order_increment_id":"' . $wanted->getIncrementId() . '"', $log);
        $this->assertStringNotContainsString('"order_increment_id":"' . $other->getIncrementId() . '"', $log);
        $this->assertMatchesRegularExpression('/"correlation_id":"[0-9a-f]{12}"/', $log);

        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame($wanted->getIncrementId(), $manifest['order_increment_id']);
        $this->assertSame(['default'], array_column($manifest['scope']['stores'], 'code'));
    }

    public function testPerOrderBundleResolvesAgainstTheOrdersStore(): void
    {
        $order = $this->placeOrder(self::SECOND_STORE_CODE);

        // Increment ids are sequenced per store: the same one exists in the
        // default store too, so the CLI passes the store to disambiguate.
        [$entries] = $this->generate(new BundleRequest(
            BundleRequest::SCOPE_STORE,
            (int) $order->getStoreId(),
            false,
            'standard',
            false,
            'integration',
            BundleRequest::ORIGIN_CLI,
            null,
            (string) $order->getIncrementId()
        ));

        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame([self::SECOND_STORE_CODE], array_column($manifest['scope']['stores'], 'code'));
        $settings = json_decode($entries['settings.json'], true);
        $this->assertSame([self::SECOND_STORE_CODE], array_keys($settings['settings']['enabled']['effective']));
    }

    public function testCorrelationContextIsBoundDuringCheckout(): void
    {
        $order = $this->placeOrder();

        $context = $this->get(GatewayLogger::class)->getCorrelationContext();
        $this->assertSame($order->getIncrementId(), $context['order_increment_id'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $context['correlation_id'] ?? '');
    }

    public function testExportRequiresItsOwnAclGrant(): void
    {
        $acl = $this->get(AclBuilder::class)->getAcl();

        $this->assertTrue($acl->hasResource(Export::ADMIN_RESOURCE), 'etc/acl.xml declares the resource');

        $acl->addRole('taxcloud_tax_config_only');
        $acl->allow('taxcloud_tax_config_only', 'Magento_Tax::config_tax');
        $this->assertFalse(
            $acl->isAllowed('taxcloud_tax_config_only', Export::ADMIN_RESOURCE),
            'editing tax settings does not grant exporting customer data'
        );

        $acl->addRole('taxcloud_diagnostics');
        $acl->allow('taxcloud_diagnostics', Export::ADMIN_RESOURCE);
        $this->assertTrue($acl->isAllowed('taxcloud_diagnostics', Export::ADMIN_RESOURCE));
    }
}
