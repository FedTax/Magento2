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

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem;
use Symfony\Component\Console\Tester\CommandTester;
use Taxcloud\Magento2\Console\Command\DiagnosticsExportCommand;
use Taxcloud\Magento2\Controller\Adminhtml\Diagnostics\Export;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleWorkspace;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The two delivery surfaces over the real install: the admin controller's
 * response (streamed from disk, deleted once sent) and the CLI command, plus
 * masking end to end and scratch-space cleanup.
 *
 * Form-key and ACL enforcement on the live route are covered by the E2E suite,
 * which is the only layer that goes through Magento's backend front controller.
 */
class DiagnosticsSurfacesTest extends IntegrationTestCase
{
    /**
     * @var string[]
     */
    private $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * @return string Absolute path of var/tmp/taxcloud-diagnostics
     */
    private function workspaceDir(): string
    {
        return $this->get(BundleWorkspace::class)->getBaseDir();
    }

    /**
     * Anything left in the workspace besides what a test deliberately keeps.
     *
     * @return string[]
     */
    private function workspaceLeftovers(): array
    {
        return array_values(array_diff(scandir($this->workspaceDir()) ?: [], ['.', '..']));
    }

    /**
     * @param string $zip
     * @return array<string, string>
     */
    private function unzip(string $zip): array
    {
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zip), 'a valid ZIP was produced');
        $entries = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entries[(string) $archive->getNameIndex($i)] = (string) $archive->getFromIndex($i);
        }
        $archive->close();

        return $entries;
    }

    public function testControllerStreamsTheZipAndDeletesItOnceSent(): void
    {
        $request = $this->get(RequestInterface::class);
        $request->setParams(['redact_pii' => '0', 'log_window' => 'standard', 'probe' => '0']);

        /** @var Export $controller */
        $controller = $this->objectManager()->create(Export::class);
        $response = $controller->execute();

        $this->assertInstanceOf(\Magento\Framework\App\Response\Http::class, $response);

        ob_start();
        try {
            $response->sendResponse();
        } finally {
            $body = (string) ob_get_clean();
        }

        $this->assertStringStartsWith("PK\x03\x04", $body, 'the body is the ZIP itself');
        $path = $this->workspaceDir() . '/body.zip';
        file_put_contents($path, $body);
        $this->cleanup[] = $path;
        $entries = $this->unzip($path);
        unlink($path);

        $this->assertArrayHasKey('summary.md', $entries);
        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame('admin', $manifest['origin']);
        $this->assertFalse($manifest['probe_enabled']);

        $header = $response->getHeader('Content-Disposition');
        $this->assertNotFalse($header);
        $this->assertMatchesRegularExpression(
            '/attachment; filename="taxcloud-diagnostics-default-\d{8}-\d{6}\.zip"/',
            $header->getFieldValue()
        );

        $this->assertSame([], $this->workspaceLeftovers(), 'the ZIP and its work directory are gone once sent');
    }

    public function testCliWritesAMaskedPerOrderBundleForTheOrdersStore(): void
    {
        $order = $this->placeOrder(self::SECOND_STORE_CODE);
        $output = $this->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR)
            ->getAbsolutePath('taxcloud-diagnostics-integration.zip');
        $this->cleanup[] = $output;

        $tester = new CommandTester($this->get(DiagnosticsExportCommand::class));
        $exit = $tester->execute([
            '--order' => (string) $order->getIncrementId(),
            '--store' => (string) $order->getStoreId(),
            '--redact' => true,
            '--no-probe' => true,
            '--output' => $output,
        ]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertStringContainsString($output, $tester->getDisplay());
        $entries = $this->unzip($output);

        $orderData = json_decode($entries['order.json'], true);
        $this->assertSame($order->getIncrementId(), $orderData['increment_id']);

        $billing = $orderData['billing_address'];
        $this->assertSame(PiiRedactor::MARKER, $billing['firstname']);
        $this->assertSame(PiiRedactor::MARKER, $billing['telephone']);
        $this->assertSame(PiiRedactor::MARKER, $orderData['customer']['email']);
        $this->assertSame(
            (string) $order->getBillingAddress()->getPostcode(),
            (string) $billing['postcode'],
            'ZIP drives the tax and is never masked'
        );
        $this->assertSame((string) $order->getBillingAddress()->getCity(), (string) $billing['city']);

        // Values distinctive enough to search for; the seeded first name
        // ("Test") also occurs in unrelated text.
        $street = $order->getBillingAddress()->getStreet();
        $identifying = array_filter([
            (string) $order->getCustomerEmail(),
            (string) (is_array($street) ? reset($street) : $street),
        ], static function ($value) {
            return strlen($value) >= 8;
        });
        $this->assertCount(2, $identifying, 'the fixture order carries an email and a street line');
        foreach ($entries as $name => $content) {
            foreach ($identifying as $pii) {
                $this->assertStringNotContainsString($pii, $content, "$name must not contain masked customer data");
            }
        }

        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame('masked', $manifest['redaction']['customer_details']);
        $this->assertSame('cli', $manifest['origin']);
        $this->assertSame([self::SECOND_STORE_CODE], array_column($manifest['scope']['stores'], 'code'));

        $this->assertSame([], $this->workspaceLeftovers(), 'the CLI moves the ZIP out and leaves no scratch files');
    }

    public function testCliFailsWithoutWritingAnythingForAnUnknownOrder(): void
    {
        $output = $this->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR)
            ->getAbsolutePath('taxcloud-diagnostics-missing.zip');
        $this->cleanup[] = $output;

        $tester = new CommandTester($this->get(DiagnosticsExportCommand::class));
        $exit = $tester->execute(['--order' => 'NO-SUCH-ORDER', '--no-probe' => true, '--output' => $output]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
        $this->assertFileDoesNotExist($output);
        $this->assertSame([], $this->workspaceLeftovers());
    }
}
