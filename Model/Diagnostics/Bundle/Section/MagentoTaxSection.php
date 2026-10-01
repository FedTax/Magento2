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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Section;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Taxcloud\Magento2\Model\Cache\Type\Taxcloud as TaxcloudCacheType;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;

/**
 * magento-tax.json: Magento's own tax configuration.
 *
 * Native tax settings, rules, rates and classes silently interact with
 * TaxCloud's output — a leftover rule taxing on top of TaxCloud, display
 * settings that double-count, a fallback that quietly applies native rates —
 * and are a recurring bug class. Also records whether the TaxCloud cache type
 * is enabled.
 */
class MagentoTaxSection implements SectionInterface
{
    public const FILE = 'magento-tax.json';

    /**
     * Rates emitted before the list is truncated. Some stores import full
     * ZIP-level rate tables; the count still reports the true total.
     */
    private const MAX_RATES = 2000;

    /**
     * Subgroups of tax/* that belong to TaxCloud and are reported in settings.json.
     */
    private const EXCLUDED_GROUPS = ['taxcloud_settings', 'taxcloud'];

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var StateInterface
     */
    private $cacheState;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ResourceConnection   $resource
     * @param StateInterface       $cacheState
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ResourceConnection $resource,
        StateInterface $cacheState
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->resource = $resource;
        $this->cacheState = $cacheState;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'magento_tax';
    }

    /**
     * @inheritDoc
     */
    public function isApplicable(BundleContext $context): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function collect(BundleContext $context, BundleArchive $archive): array
    {
        $default = $this->flatten(
            (array) $this->scopeConfig->getValue('tax', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
        );

        $perStore = [];
        foreach ($context->getScope()->getStores() as $store) {
            $resolved = $this->flatten(
                (array) $this->scopeConfig->getValue('tax', ScopeInterface::SCOPE_STORE, $store->getId())
            );
            $diff = [];
            foreach ($resolved as $key => $value) {
                if (!array_key_exists($key, $default) || $default[$key] !== $value) {
                    $diff[$key] = $value;
                }
            }
            $perStore[(string) $store->getCode()] = $diff;
        }

        $connection = $this->resource->getConnection();
        $rateCount = (int) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('tax_calculation_rate'), ['COUNT(*)'])
        );

        $data = [
            'taxcloud_cache_type_enabled' => $this->cacheState->isEnabled(TaxcloudCacheType::TYPE_IDENTIFIER),
            'config' => [
                'default' => $default,
                'store_overrides' => $perStore,
            ],
            'tax_classes' => $this->taxClasses(),
            'tax_rules' => $this->taxRules(),
            'tax_rates' => [
                'count' => $rateCount,
                'truncated' => $rateCount > self::MAX_RATES,
                'rates' => $this->taxRates(),
            ],
        ];

        $archive->addJson(self::FILE, $data);

        return $data;
    }

    /**
     * @param array  $tree
     * @param string $prefix
     * @return array<string, mixed>
     */
    private function flatten(array $tree, string $prefix = 'tax/'): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            if ($prefix === 'tax/' && in_array($key, self::EXCLUDED_GROUPS, true)) {
                continue;
            }
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix . $key . '/');
            } else {
                $flat[$prefix . $key] = $value;
            }
        }
        ksort($flat);

        return $flat;
    }

    /**
     * @return array
     */
    private function taxClasses(): array
    {
        $connection = $this->resource->getConnection();

        return array_map(static function ($row) {
            return ['id' => (int) $row['class_id'], 'name' => $row['class_name'], 'type' => $row['class_type']];
        }, $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('tax_class'), ['class_id', 'class_name', 'class_type'])
                ->order('class_id')
        ));
    }

    /**
     * @return array
     */
    private function taxRules(): array
    {
        $connection = $this->resource->getConnection();
        $rules = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('tax_calculation_rule'))
                ->order('tax_calculation_rule_id')
        ) as $row) {
            $rules[(int) $row['tax_calculation_rule_id']] = [
                'id' => (int) $row['tax_calculation_rule_id'],
                'code' => $row['code'],
                'priority' => (int) $row['priority'],
                'position' => (int) $row['position'],
                'calculate_subtotal' => (bool) $row['calculate_subtotal'],
                'customer_tax_class_ids' => [],
                'product_tax_class_ids' => [],
                'rate_codes' => [],
            ];
        }

        $links = $connection->fetchAll(
            $connection->select()
                ->from(['tc' => $this->resource->getTableName('tax_calculation')], [
                    'tax_calculation_rule_id', 'customer_tax_class_id', 'product_tax_class_id',
                ])
                ->joinLeft(
                    ['r' => $this->resource->getTableName('tax_calculation_rate')],
                    'r.tax_calculation_rate_id = tc.tax_calculation_rate_id',
                    ['code']
                )
        );
        foreach ($links as $link) {
            $id = (int) $link['tax_calculation_rule_id'];
            if (!isset($rules[$id])) {
                continue;
            }
            $rules[$id]['customer_tax_class_ids'][(int) $link['customer_tax_class_id']] = true;
            $rules[$id]['product_tax_class_ids'][(int) $link['product_tax_class_id']] = true;
            $rules[$id]['rate_codes'][(string) $link['code']] = true;
        }

        foreach ($rules as &$rule) {
            $rule['customer_tax_class_ids'] = array_keys($rule['customer_tax_class_ids']);
            $rule['product_tax_class_ids'] = array_keys($rule['product_tax_class_ids']);
            $rule['rate_codes'] = array_map('strval', array_keys($rule['rate_codes']));
        }
        unset($rule);

        return array_values($rules);
    }

    /**
     * @return array
     */
    private function taxRates(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['r' => $this->resource->getTableName('tax_calculation_rate')], [
                    'tax_calculation_rate_id', 'code', 'tax_country_id', 'tax_postcode', 'rate', 'zip_is_range',
                    'zip_from', 'zip_to',
                ])
                ->joinLeft(
                    ['reg' => $this->resource->getTableName('directory_country_region')],
                    'reg.region_id = r.tax_region_id',
                    ['region_code' => 'code']
                )
                ->order('r.tax_calculation_rate_id')
                ->limit(self::MAX_RATES)
        );

        return array_map(static function ($row) {
            return [
                'id' => (int) $row['tax_calculation_rate_id'],
                'code' => $row['code'],
                'country' => $row['tax_country_id'],
                'region' => $row['region_code'],
                'postcode' => $row['zip_is_range'] ? $row['zip_from'] . '-' . $row['zip_to'] : $row['tax_postcode'],
                'rate' => (float) $row['rate'],
            ];
        }, $rows);
    }
}
