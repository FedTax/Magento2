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

namespace Taxcloud\Magento2\Test\Integration\Model\Canada;

use Magento\Config\Model\Config as AdminConfig;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Message\Manager as MessageManager;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Taxcloud\Magento2\Test\Integration\Doubles\RecordingMessageManager;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Test\Integration\CanadianQuoteTrait;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Saving the tax configuration with Canadian tax on tells the merchant whether
 * their TaxCloud account actually has Canada.
 *
 * Unit tests cover the observer's own logic. What they cannot cover is the part
 * that lives in Magento: that saving the section really dispatches the event the
 * observer listens for, and that the paths Magento reports as changed are the
 * `config_path` values the observer matches on — a field's id and its
 * config_path differ here (`canada_tax_enabled` vs
 * `tax/taxcloud_settings/canada_tax_enabled`), and matching the wrong one would
 * make the check silently never run.
 *
 * The save itself goes through Magento's own admin config model, the same class
 * the Save Config button uses.
 */
class CanadaAccessOnSaveTest extends IntegrationTestCase
{
    use CanadianQuoteTrait;

    /** Ontario HST, as TaxCloud would answer for the sample sale. */
    private const SAMPLE_RATE = 0.13;

    /**
     * Magento's adminhtml-pinned config structure (a di.xml virtual type), the
     * one the admin itself saves against.
     */
    private const ADMINHTML_CONFIG_STRUCTURE = 'adminhtmlConfigStructure';

    private RecordingMessageManager $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRestMock($this->restRespondersWith([
            'POST /carts' => $this->flatRateCartResponder(self::SAMPLE_RATE),
        ]));
        $this->useRestTransport();
        // The transport is mocked, so any connection id will do — but one must
        // be configured, or the check declines to run before any call.
        $this->setScopedConfig('tax/taxcloud_settings/rest_connection_id', 'integration-connection');
        // Snapshot the setting so tearDown restores it after the save below
        // writes it.
        $this->setCanadaTax(false);

        // Messages go to a recorder rather than the real manager: the genuine
        // one writes to the session, which a test running outside a request has
        // no business starting. The observer is seeded fresh here (it is in
        // REST_DEPENDENT_TYPES, so installRestMock() has just evicted it), which
        // is what lets the substitution take.
        $this->messages = new RecordingMessageManager();
        // Seeded under the CONCRETE class as well as the interface: the
        // ObjectManager resolves the preference first and keys its shared
        // instances by the resolved class, so seeding only the interface is
        // silently ignored and the observer keeps the real manager.
        $this->mutateSharedInstances(
            [\Taxcloud\Magento2\Observer\Adminhtml\CheckCanadaAccessOnSave::class],
            [
                MessageManagerInterface::class => $this->messages,
                MessageManager::class => $this->messages,
            ]
        );
    }

    protected function tearDown(): void
    {
        // Leave the real message manager for whatever runs next in this process.
        $this->mutateSharedInstances([MessageManagerInterface::class, MessageManager::class]);
        parent::tearDown();
    }

    /**
     * Turning the setting on runs the check, and a confirming account is
     * reported as a success alongside the save.
     */
    public function testSavingTheSettingOnChecksTheAccountAndReportsSuccess(): void
    {
        $this->saveTaxSection(['canada_tax_enabled' => '1']);

        $sample = $this->sampleCart();
        $this->assertNotNull(
            $sample,
            'Saving with Canadian tax on must run the Canada access check. No sample cart means the '
            . 'observer never fired — or matched the wrong changed path.'
        );
        $this->assertSame('CA', $sample['destination']['countryCode']);
        $this->assertSame('ON', $sample['destination']['state']);

        $this->assertSame(
            '1',
            $this->get(ScopeConfigInterface::class)->getValue('tax/taxcloud_settings/canada_tax_enabled'),
            'The setting is saved whatever the check says.'
        );
        $this->assertMessage(
            MessageInterface::TYPE_SUCCESS,
            'Canada access confirmed',
            'A confirming account must be reported as a success.'
        );
    }

    /**
     * An account that refuses the sample is reported as a warning — and the
     * save still stands, so a merchant can switch the setting on before
     * TaxCloud support has finished enabling Canada.
     */
    public function testAnAccountWithoutCanadaWarnsWithoutBlockingTheSave(): void
    {
        $this->restMock()->respondTo('POST', '/carts', static function (): RestResponse {
            return new RestResponse(422, (string) json_encode([
                'title' => 'Unprocessable Entity',
                'detail' => 'country not enabled',
            ]));
        });

        $this->saveTaxSection(['canada_tax_enabled' => '1']);

        $this->assertSame(
            '1',
            $this->get(ScopeConfigInterface::class)->getValue('tax/taxcloud_settings/canada_tax_enabled'),
            'A failed check must never block the save.'
        );
        $this->assertMessage(
            MessageInterface::TYPE_WARNING,
            'Contact TaxCloud support',
            'A refusing account must be reported as a warning naming TaxCloud support.'
        );
    }

    /**
     * A save that changes nothing bearing on Canadian access costs no API call,
     * even with the setting already on.
     */
    public function testAnUnrelatedSaveDoesNotCallTaxCloud(): void
    {
        $this->setCanadaTax(true);
        $this->setScopedConfig('tax/taxcloud_settings/cache_lifetime', '86400');
        $this->restMock()->resetCalls();

        $this->saveTaxSection(['cache_lifetime' => '1200']);

        $this->assertNull($this->sampleCart(), 'Only a change that bears on Canadian access re-checks it.');
    }

    /**
     * The sample cart of the Canada access check, if one was sent.
     *
     * Identified by its fixed cart id: the responder also serves ordinary
     * lookups, and this test must not mistake one for the other.
     *
     * @return array<string, mixed>|null
     */
    private function sampleCart(): ?array
    {
        foreach ($this->restMock()->callsTo('POST', '/carts') as $call) {
            $cart = $call['body']['items'][0] ?? null;
            if (is_array($cart) && ($cart['cartId'] ?? null) === CanadaAccessChecker::SAMPLE_CART_ID) {
                return $cart;
            }
        }

        return null;
    }

    /**
     * Save fields of the TaxCloud group at default scope through Magento's own
     * admin config model — the path the Save Config button takes, so the event
     * and its changed paths are Magento's, not this test's.
     *
     * The model is built with Magento's own adminhtml-pinned config structure
     * (`adminhtmlConfigStructure`), because `system.xml` is only read for the
     * adminhtml scope. Built against the ambient structure, these tests run in
     * the frontend scope, where the structure knows no fields: every value then
     * saves under the group path (`tax/taxcloud/<field>`) instead of its
     * `config_path`, the changed paths reported are ones no observer matches,
     * and the save quietly does nothing at all. Pinning the structure is what
     * makes this independent of whatever scope the run happens to be in.
     *
     * @param array<string, string> $fields field id => value
     */
    private function saveTaxSection(array $fields): void
    {
        $fieldData = [];
        foreach ($fields as $id => $value) {
            $fieldData[$id] = ['value' => $value];
        }

        /** @var AdminConfig $config */
        $config = $this->objectManager()->create(AdminConfig::class, [
            'configStructure' => $this->get(self::ADMINHTML_CONFIG_STRUCTURE),
        ]);
        $config->setSection('tax');
        $config->setWebsite(null);
        $config->setStore(null);
        $config->setGroups(['taxcloud' => ['fields' => $fieldData]]);

        $this->inAdminEventScope(static function () use ($config): void {
            $config->save();
        });

        $this->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();

        // The save is this test's premise, not its subject: if Magento wrote
        // nothing, every assertion after it would report a missing observer
        // rather than a save that never happened.
        foreach ($fields as $id => $value) {
            $this->assertSame(
                $value,
                $this->get(ScopeConfigInterface::class)->getValue('tax/taxcloud_settings/' . $id),
                'Magento did not persist ' . $id . ' — the admin config structure resolved no such '
                . 'field, so nothing was saved and no changed path was reported.'
            );
        }
    }

    /**
     * Run a callback with the adminhtml CONFIG SCOPE current.
     *
     * Magento resolves observers per scope, and this observer is declared in
     * `etc/adminhtml/events.xml` — in the frontend scope these tests otherwise
     * run in, `admin_system_config_changed_section_tax` has no observers at
     * all, so the save would dispatch into nothing and the check would look
     * like it had silently declined to run.
     *
     * The area code is deliberately left alone: only the config scope decides
     * which events.xml files are merged.
     */
    private function inAdminEventScope(callable $callback): void
    {
        $scope = $this->get(\Magento\Framework\Config\ScopeInterface::class);
        $previous = $scope->getCurrentScope();

        $scope->setCurrentScope(Area::AREA_ADMINHTML);
        try {
            $callback();
        } finally {
            $scope->setCurrentScope($previous);
        }
    }

    private function assertMessage(string $type, string $contains, string $because): void
    {
        $texts = $this->messages->textsOfType($type);

        $this->assertNotEmpty($texts, $because . ' No ' . $type . ' message was added.');
        $this->assertStringContainsString($contains, implode(' | ', $texts), $because);
    }
}
