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

namespace Taxcloud\Magento2\Test\Integration\Model\Tic;

use Taxcloud\Magento2\Api\TicLookupInterface;
use Taxcloud\Magento2\Controller\Adminhtml\Tic\Search;
use Taxcloud\Magento2\Model\Tic\RestTicLookup;
use Taxcloud\Magento2\Model\Tic\TicLookupRouter;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;
use Taxcloud\Magento2\Ui\DataProvider\Product\Form\Modifier\TicField;

/**
 * Everything about TIC lookup that only breaks once a real container is
 * involved.
 *
 * During implementation this layer failed twice in ways the unit suite passed
 * straight through: the lookup endpoint 500'd with "Cannot instantiate
 * interface TicLookupInterface" against a container compiled before the new
 * preference existed, and the product form crashed with a ReflectionException
 * because the module's new top-level directory was not linked into the
 * install. Both are invisible to a test that constructs its own objects, and
 * both present to a merchant as a dead search box or a broken admin page.
 */
class TicLookupWiringTest extends IntegrationTestCase
{
    /**
     * The DI preference is what the admin endpoint depends on; without it the
     * controller cannot be built at all.
     */
    public function testLookupInterfaceResolvesToTheStoreAwareRouter(): void
    {
        $this->assertInstanceOf(TicLookupRouter::class, $this->get(TicLookupInterface::class));
    }

    /**
     * The exact failure seen in the browser: this controller could not be
     * instantiated, so every search returned a 500 that the field reported as
     * "unavailable".
     */
    public function testLookupControllerCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Search::class, $this->objectManager()->create(Search::class));
    }

    /**
     * The product form modifier lives in a directory added by this change. A
     * running install that links module directories individually will not see
     * it, and the product edit page dies rather than degrading.
     */
    public function testProductFormModifierIsAutoloadableAndRegistered(): void
    {
        // The failure that actually happened: the class was unreachable and the
        // product edit page died with a ReflectionException.
        $this->assertInstanceOf(TicField::class, $this->objectManager()->create(TicField::class));

        // The modifier pool is an adminhtml-area virtualType and this bootstrap
        // does not load adminhtml di.xml, so registration is asserted against
        // the declaration rather than a resolved instance. Asserting it here at
        // all is the point: the pool must be extended in the adminhtml file,
        // because adding to it from global di.xml does not merge.
        $di = simplexml_load_string(
            (string) file_get_contents(dirname(__DIR__, 4) . '/etc/adminhtml/di.xml')
        );
        $this->assertNotFalse($di, 'etc/adminhtml/di.xml must be valid XML');

        $entry = $di->xpath(
            '//virtualType[@name="Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Pool"]'
            . '/arguments/argument[@name="modifiers"]/item[@name="taxcloud_tic"]'
            . '/item[@name="class"]'
        );

        $this->assertNotEmpty(
            $entry,
            'The TIC modifier must be registered in the product form modifier pool in the adminhtml '
            . 'area, or the product field silently renders as a plain text box.'
        );
        $this->assertSame(TicField::class, trim((string) $entry[0]));
    }

    /**
     * The modifier rewrites generated meta rather than a static XML file, so
     * what it does can only be observed against real meta.
     */
    public function testModifierAttachesTheComponentToTheTicField(): void
    {
        /** @var TicField $modifier */
        $modifier = $this->objectManager()->create(TicField::class);

        $meta = $modifier->modifyMeta([
            'product-details' => [
                'children' => [
                    'container_taxcloud_tic' => [
                        'children' => [
                            'taxcloud_tic' => [
                                'arguments' => ['data' => ['config' => ['dataType' => 'text']]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $config = $meta['product-details']['children']['container_taxcloud_tic']
            ['children']['taxcloud_tic']['arguments']['data']['config'];

        $this->assertSame('Taxcloud_Magento2/js/form/element/tic', $config['component']);
        $this->assertStringContainsString('taxcloud/tic/search', $config['searchUrl']);
        $this->assertNotEmpty($config['fallbackHint']);
        $this->assertSame('text', $config['dataType'], 'existing field config must survive untouched');
    }

    /**
     * A form whose TIC field is absent — an attribute set that excludes it —
     * must not be corrupted by the modifier looking for it.
     */
    public function testModifierLeavesMetaAloneWhenTheFieldIsAbsent(): void
    {
        /** @var TicField $modifier */
        $modifier = $this->objectManager()->create(TicField::class);
        $meta = ['product-details' => ['children' => ['sku' => ['arguments' => []]]]];

        $this->assertSame($meta, $modifier->modifyMeta($meta));
    }

    /**
     * Live lookups are asserted against the REST backend specifically, not
     * through the router.
     *
     * The install seeds api_type=soap, so the router would pick the SOAP
     * backend — and this suite mocks SOAP process-wide. `installSoapMock()`
     * replaces the shared ClientFactory, that instance outlives the test that
     * installed it, and RecordingSoapClient throws for any method without a
     * canned response, GetTICs included. Routing here would therefore assert
     * against a mock that always fails, which is exactly what it did: green in
     * isolation, red in the full suite, on every version.
     *
     * Live SOAP TIC lookup is covered where it can be exercised honestly —
     * Test/E2e/specs/admin/admin-tic-search.spec.ts drives a real store whose
     * api_type is soap.
     *
     * @return RestTicLookup
     */
    private function restLookup(): RestTicLookup
    {
        return $this->objectManager()->create(RestTicLookup::class);
    }

    /**
     * End to end against the live API with the install's seeded credentials:
     * the backend authenticates and real TICs come back.
     */
    public function testLiveLookupReturnsRealSuggestions(): void
    {
        $result = $this->restLookup()->search('clothing');

        $this->assertTrue(
            $result->isAvailable(),
            'Lookup was unavailable (' . $result->getReason() . ') — check the seeded V3 credentials.'
        );
        $this->assertNotEmpty($result->getSuggestions());

        // Asserted loosely on purpose: v3 search is semantic and its exact
        // ranking is TaxCloud's to change. What must hold is that a plain
        // English query comes back with usable, labelled TICs.
        $labels = array_map(
            static function ($suggestion) {
                return strtolower($suggestion->getLabel());
            },
            $result->getSuggestions()
        );
        $matching = array_filter($labels, static function ($label) {
            return strpos($label, 'clothing') !== false;
        });
        $this->assertNotEmpty($matching, 'searching "clothing" should surface at least one clothing TIC');

        foreach ($result->getSuggestions() as $suggestion) {
            $this->assertMatchesRegularExpression('/^\d+$/', $suggestion->getCode());
            $this->assertNotEmpty($suggestion->getLabel(), 'a suggestion with no label renders as a bare number');
        }
    }

    /**
     * A code TaxCloud does not know must come back as a ran-but-matched-nothing
     * result, never as unavailable — the field's "kept as entered" notice
     * depends on telling those apart.
     */
    public function testLiveResolveOfAnUnknownCodeIsEmptyNotUnavailable(): void
    {
        $result = $this->restLookup()->resolve('99999999');

        $this->assertTrue($result->isAvailable());
        $this->assertSame([], $result->getSuggestions());
    }
}
