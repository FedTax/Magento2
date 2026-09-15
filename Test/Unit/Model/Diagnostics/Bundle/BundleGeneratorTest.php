<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Logger\Handler;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleGenerator;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleWorkspace;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\ConfigSourceReader;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogFileReader;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Log\LogPathResolver;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Probe\ApiProbe;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialFingerprint;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialInventory;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\PiiRedactor;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\ScopeResolver;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\LogsSection;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\ProbeSection;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\SectionInterface;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Section\SettingsSection;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Summary\SummaryRenderer;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;

/**
 * End-to-end generation over real sections, redaction and ZIP writing.
 *
 * The first test is the most important in the diagnostics feature: with
 * credentials configured at several scopes, locked in a deployment file,
 * cached as a Bearer token, and written into the logs in every shape any
 * version could have produced — and with a section that tries to leak them
 * outright — no credential value appears anywhere in the bundle.
 */
#[AllowMockObjectsWithoutExpectations]
class BundleGeneratorTest extends TestCase
{
    use DiagnosticsFixture;

    private const DEFAULT_API_ID = 'SECRET-API-ID-DEFAULT-7788';
    private const DEFAULT_API_KEY = 'SECRET-API-KEY-DEFAULT-0f9e8d7c6b5a';
    private const CA_API_KEY = 'SECRET-API-KEY-CANADA-1a2b3c4d5e6f';
    private const US_REST_KEY = 'SECRET-REST-KEY-US-WEBSITE-99aa88bb';
    private const LOCKED_API_KEY = 'SECRET-LOCKED-ENVPHP-KEY-5544';
    private const BEARER_TOKEN = 'eyJSECRETBEARERTOKENvalue.payload.signature';
    private const CONNECTION_ID = '25eb9b97-5acb-492d-b720-c03e79cf715a';

    /**
     * @var string
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tc-bundle-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/log', 0777, true);
        mkdir($this->root . '/work');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    /**
     * Every credential value, and the variants a careless writer might emit.
     *
     * @return string[]
     */
    private function secretVariants(): array
    {
        $variants = [];
        foreach ([self::DEFAULT_API_ID, self::DEFAULT_API_KEY, self::CA_API_KEY, self::US_REST_KEY,
            self::LOCKED_API_KEY, self::BEARER_TOKEN] as $secret) {
            $variants[] = $secret;
            $variants[] = substr((string) json_encode($secret), 1, -1);
            $variants[] = '0:3:' . $secret;
        }

        return $variants;
    }

    private function configureStore(): void
    {
        $this->setConfig(
            [
                'tax/taxcloud_settings/enabled' => '1',
                'tax/taxcloud_settings/logging' => '2',
                'tax/taxcloud_settings/api_type' => 'soap',
                'tax/taxcloud_settings/api_id' => self::DEFAULT_API_ID,
                'tax/taxcloud_settings/api_key' => self::DEFAULT_API_KEY,
                'tax/taxcloud_settings/rest_connection_id' => self::CONNECTION_ID,
            ],
            ['us' => ['tax/taxcloud_settings/rest_api_key' => '0:3:' . self::US_REST_KEY,
                'tax/taxcloud_settings/api_type' => 'rest']],
            ['ca_en' => ['tax/taxcloud_settings/api_key' => self::CA_API_KEY . "\n"]]
        );
    }

    private function writeLogs(): void
    {
        $now = time();
        $ts = function ($offset) use ($now) {
            return gmdate('Y-m-d\TH:i:s.000000+00:00', $now - $offset);
        };
        $lines = [
            '[' . $ts(300) . '] tclogger.DEBUG: lookupTaxes PARAMS: Array' . "\n(\n    [apiLoginID] => "
                . self::DEFAULT_API_ID . "\n    [apiKey] => " . self::DEFAULT_API_KEY . "\n)\n",
            '[' . $ts(250) . '] tclogger.DEBUG: lookup SOAP request XML: <apiLoginID>' . self::DEFAULT_API_ID
                . '</apiLoginID><apiKey>' . self::CA_API_KEY . "</apiKey> [] []\n",
            '[' . $ts(200) . '] tclogger.DEBUG: HTTP request headers: X-API-KEY: ' . self::US_REST_KEY
                . ' Authorization: Bearer ' . self::BEARER_TOKEN . " [] []\n",
            '[' . $ts(150) . '] tclogger.ERROR: legacy line from before a redaction gap closed: key was '
                . self::LOCKED_API_KEY . " and token " . self::BEARER_TOKEN . " [] []\n",
            '[' . $ts(100) . '] tclogger.INFO: Calling lookupTaxes LIVE API {"correlation_id":"abc123abc123","operation":"lookup","quote_id":"5"} []' . "\n",
        ];
        file_put_contents($this->root . '/var/log/taxcloud.log', implode('', $lines));
        file_put_contents(
            $this->root . '/var/log/system.log',
            '[' . $ts(90) . '] main.CRITICAL: TaxCloud SoapFault apiKey=' . self::DEFAULT_API_KEY . " [] []\n"
            . '[' . $ts(80) . "] main.INFO: unrelated cache message [] []\n"
        );
        file_put_contents(
            $this->root . '/var/log/exception.log',
            '[' . $ts(70) . '] main.CRITICAL: Exception in Taxcloud\\Magento2\\Model\\Api: ' . self::CA_API_KEY
            . "\n#0 /var/www/html/app/code/Taxcloud/Magento2/Model/Api.php(1): lookup('" . self::DEFAULT_API_KEY
            . "')\n"
        );
    }

    /**
     * @param SectionInterface[] $extraSections
     * @return BundleGenerator
     */
    private function generator(array $extraSections = [], ?BundleWorkspace $workspace = null): BundleGenerator
    {
        $scopeConfig = $this->scopeConfig();
        $storeManager = $this->storeManager();

        $sourceReader = $this->createMock(ConfigSourceReader::class);
        $sourceReader->method('getDatabaseRows')->willReturn($this->databaseRows());
        $sourceReader->method('getLockedPaths')->willReturn(['tax/taxcloud_settings/api_key']);
        $sourceReader->method('getLockedValue')->willReturnCallback(function ($path, $scope, $code = null) {
            return $path === 'tax/taxcloud_settings/api_key' && $scope === 'stores' && $code === 'us_es'
                ? ['source' => ConfigSourceReader::SOURCE_ENV_PHP, 'value' => self::LOCKED_API_KEY]
                : null;
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(function ($value) {
            return substr((string) $value, 4);
        });
        $config = new TaxcloudConfig($scopeConfig, $encryptor);

        $tokenCache = $this->createMock(TokenCache::class);
        $tokenCache->method('get')->willReturn(new BearerToken(self::BEARER_TOKEN, time() + 3600));

        $inventory = new CredentialInventory($sourceReader, $scopeConfig, $storeManager, $encryptor, $config, $tokenCache);

        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn($this->root);
        $directoryList->method('getPath')->willReturn($this->root . '/var/log');
        $logger = new Logger('tclogger', [
            new Handler($this->createMock(DriverInterface::class), $this->root . '/', 'var/log/taxcloud.log'),
        ]);
        $driver = new File();

        // The probe echoes credentials into error messages, as a careless
        // transport exception might.
        $probe = $this->createMock(ApiProbe::class);
        $probe->method('probe')->willReturn(['configurations' => [[
            'stores' => ['us_en'],
            'api_type' => 'soap',
            'calls' => ['lookup' => ['success' => false,
                'error_message' => 'SoapFault: invalid apiKey ' . self::DEFAULT_API_KEY]],
        ]]]);

        $sections = array_merge([
            new SettingsSection($scopeConfig, $sourceReader, $inventory, new CredentialFingerprint()),
            new ProbeSection($probe),
            new LogsSection(new LogPathResolver($logger, $directoryList), new LogFileReader($driver), $driver, $config),
        ], $extraSections);

        if ($workspace === null) {
            $workspace = $this->createMock(BundleWorkspace::class);
            $workspace->method('createWorkDir')->willReturnCallback(function () {
                $dir = $this->root . '/work/' . bin2hex(random_bytes(4));
                mkdir($dir);
                return $dir;
            });
            $workspace->method('remove')->willReturnCallback(function ($path) {
                self::removeTree($path);
            });
        }

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('America/Chicago');
        $packageInfo = $this->createMock(PackageInfo::class);
        $packageInfo->method('getVersion')->willReturn('1.5.0');

        return new BundleGenerator(
            new ScopeResolver($storeManager),
            $workspace,
            $inventory,
            new PiiRedactor(),
            new SummaryRenderer(new CredentialFingerprint()),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $timezone,
            $packageInfo,
            $sections
        );
    }

    /**
     * @param string $zip
     * @return array<string, string> entry => content
     */
    private function unzip(string $zip): array
    {
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($zip));
        $entries = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = $archive->getNameIndex($i);
            $entries[$name] = (string) $archive->getFromIndex($i);
        }
        $archive->close();

        return $entries;
    }

    public function testNoCredentialValueAppearsAnywhereInTheBundle()
    {
        $this->configureStore();
        $this->writeLogs();

        $leaky = new class implements SectionInterface {
            public function getCode(): string
            {
                return 'leaky';
            }

            public function isApplicable(BundleContext $context): bool
            {
                return true;
            }

            public function collect(BundleContext $context, BundleArchive $archive): array
            {
                $archive->addJson('leaky.json', [
                    'raw' => BundleGeneratorTest::leakPayload(),
                    'nested' => ['apiKey' => 'anything', 'note' => BundleGeneratorTest::leakPayload()],
                ]);
                $archive->addString('leaky.txt', BundleGeneratorTest::leakPayload());
                return ['raw' => BundleGeneratorTest::leakPayload()];
            }
        };

        $result = $this->generator([$leaky])->generate(new BundleRequest());
        $entries = $this->unzip($result->getPath());

        foreach (['summary.md', 'manifest.json', 'settings.json', 'probe.json', 'logs/taxcloud.log',
            'logs/system-taxcloud.log', 'logs/exception-taxcloud.log', 'leaky.json', 'leaky.txt'] as $expected) {
            $this->assertArrayHasKey($expected, $entries);
        }

        foreach ($entries as $name => $content) {
            foreach ($this->secretVariants() as $secret) {
                $this->assertStringNotContainsString($secret, $content, sprintf('%s leaks a credential', $name));
            }
        }

        // Redaction must not have destroyed the content around the secrets.
        $this->assertStringContainsString('"correlation_id":"abc123abc123"', $entries['logs/taxcloud.log']);
        $this->assertStringContainsString('<apiKey>***REDACTED***</apiKey>', $entries['logs/taxcloud.log']);
        $this->assertStringContainsString('TaxCloud SoapFault', $entries['logs/system-taxcloud.log']);
        $this->assertStringNotContainsString('unrelated cache message', $entries['logs/system-taxcloud.log']);
        $this->assertStringContainsString(self::CONNECTION_ID, $entries['settings.json'], 'the Connection ID is not a secret');

        unlink($result->getPath());
    }

    /**
     * @return string
     */
    public static function leakPayload(): string
    {
        return 'api_id=' . self::DEFAULT_API_ID . ' key ' . self::DEFAULT_API_KEY . ' ca ' . self::CA_API_KEY
            . ' rest ' . self::US_REST_KEY . ' locked ' . self::LOCKED_API_KEY . ' bearer ' . self::BEARER_TOKEN;
    }

    public function testAFailingCollectorYieldsAPartialBundleThatSaysSo()
    {
        $this->configureStore();
        $this->writeLogs();

        $broken = new class implements SectionInterface {
            public function getCode(): string
            {
                return 'magento_tax';
            }

            public function isApplicable(BundleContext $context): bool
            {
                return true;
            }

            public function collect(BundleContext $context, BundleArchive $archive): array
            {
                throw new \RuntimeException('Table tax_calculation_rule is missing (key ' . BundleGeneratorTest::leakPayload() . ')');
            }
        };

        $result = $this->generator([$broken])->generate(new BundleRequest());
        $entries = $this->unzip($result->getPath());

        $this->assertCount(1, $result->getFailures());
        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame('magento_tax', $manifest['failures'][0]['section']);
        $this->assertStringContainsString('Table tax_calculation_rule is missing', $manifest['failures'][0]['message']);
        $this->assertSame(\RuntimeException::class, $manifest['failures'][0]['exception']);
        $this->assertStringContainsString('The `magento_tax` collector failed', $entries['summary.md']);
        $this->assertStringContainsString('Table tax_calculation_rule is missing', $entries['summary.md']);
        $this->assertArrayHasKey('settings.json', $entries, 'the other sections still ran');
        $this->assertStringNotContainsString(self::DEFAULT_API_KEY, $entries['manifest.json']);

        unlink($result->getPath());
    }

    public function testManifestRecordsFormatFilesAndMode()
    {
        $this->configureStore();
        $this->writeLogs();

        $result = $this->generator()->generate(new BundleRequest(
            BundleRequest::SCOPE_WEBSITE,
            1,
            true,
            'extended',
            true,
            'jane.admin'
        ));
        $entries = $this->unzip($result->getPath());
        $manifest = json_decode($entries['manifest.json'], true);

        $this->assertMatchesRegularExpression('/^taxcloud-diagnostics-us-\d{8}-\d{6}\.zip$/', $result->getFileName());
        $this->assertSame(BundleGenerator::SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertSame('1.5.0', $manifest['module_version']);
        $this->assertSame('jane.admin', $manifest['generated_by']);
        $this->assertSame('masked', $manifest['redaction']['customer_details']);
        $this->assertSame('extended', $manifest['log_window']['name']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $manifest['generated_at_utc']);
        $this->assertStringContainsString('-05:00', (string) $manifest['generated_at_store']);
        $this->assertSame(['us_en', 'us_es'], array_column($manifest['scope']['stores'], 'code'));

        $listed = array_column($manifest['files'], 'bytes', 'path');
        $this->assertSame(strlen($entries['settings.json']), $listed['settings.json']);
        $this->assertSame(strlen($entries['summary.md']), $listed['summary.md']);
        $this->assertArrayHasKey('manifest.json', $listed);
        $this->assertStringContainsString('customer details: **masked**', $entries['summary.md']);
        $this->assertStringContainsString('Customer details were masked', $entries['summary.md']);

        unlink($result->getPath());
    }

    public function testScratchFilesAreRemovedOnSuccessAndOnFailure()
    {
        $this->configureStore();
        $this->writeLogs();

        $result = $this->generator()->generate(new BundleRequest());
        $this->assertSame([], glob($this->root . '/work/*', GLOB_ONLYDIR), 'work directory removed after success');
        $this->assertFileExists($result->getPath(), 'the finished ZIP is left for the caller');
        unlink($result->getPath());

        $workspace = $this->createMock(BundleWorkspace::class);
        $workspace->method('createWorkDir')->willReturnCallback(function () {
            $dir = $this->root . '/work/unwritable';
            mkdir($dir);
            // A directory where the ZIP should be makes the archive unwritable.
            mkdir($dir . '.zip');
            return $dir;
        });
        $removed = [];
        $workspace->method('remove')->willReturnCallback(function ($path) use (&$removed) {
            $removed[] = $path;
            self::removeTree($path);
        });

        try {
            $this->generator([], $workspace)->generate(new BundleRequest());
            $this->fail('An unwritable archive must fail generation');
        } catch (LocalizedException $e) {
            $this->assertContains($this->root . '/work/unwritable', $removed);
            $this->assertContains($this->root . '/work/unwritable.zip', $removed);
            $this->assertSame([], glob($this->root . '/work/*'));
        }
    }

    public function testAnIncrementIdSharedByTwoStoresMustBeDisambiguated()
    {
        $this->configureStore();

        $orderA = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderA->method('getStoreId')->willReturn(1);
        $orderB = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderB->method('getStoreId')->willReturn(3);

        $filters = [];
        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnCallback(
            function ($field, $value) use (&$filters, &$criteriaBuilder) {
                $filters[$field] = $value;
                return $criteriaBuilder;
            }
        );
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn(
            $this->createMock(\Magento\Framework\Api\SearchCriteriaInterface::class)
        );

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(function () use (&$filters, $orderA, $orderB) {
            $results = $this->createMock(\Magento\Sales\Api\Data\OrderSearchResultInterface::class);
            $results->method('getItems')->willReturn(
                isset($filters['store_id']) ? [$orderB] : [$orderA, $orderB]
            );
            return $results;
        });

        $generator = $this->generator();
        $reflection = new \ReflectionClass($generator);
        foreach (['orderRepository' => $repository, 'searchCriteriaBuilder' => $criteriaBuilder] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($generator, $value);
        }

        try {
            $generator->generate(new BundleRequest('default', null, false, 'standard', false, '', 'cli', null, '100000001'));
            $this->fail('An ambiguous increment id must not silently pick a store');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('more than one store (store ids 1, 3)', $e->getMessage());
        }

        $result = $generator->generate(new BundleRequest('stores', 3, false, 'standard', false, '', 'cli', null, '100000001'));
        $this->assertSame(3, $filters['store_id']);
        unlink($result->getPath());
    }
}
