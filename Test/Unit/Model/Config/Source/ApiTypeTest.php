<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Covers the ApiType source model and the admin/config wiring around it: the
 * select must offer exactly the two API generations, sit under the TaxCloud
 * group on the path TaxcloudConfig reads, and default fresh installs to REST.
 */
class ApiTypeTest extends TestCase
{
    public function testToOptionArrayOffersExactlySoapAndRest()
    {
        $options = (new ApiType())->toOptionArray();

        $this->assertCount(2, $options);

        $this->assertSame(ApiType::SOAP, $options[0]['value']);
        $this->assertSame(ApiType::REST, $options[1]['value']);

        // Labels are Phrase objects from __() — cast to compare rendered text.
        $this->assertSame('V1 SOAP (legacy)', (string) $options[0]['label']);
        $this->assertSame('V3 REST', (string) $options[1]['label']);
    }

    /**
     * The admin field must use this source model on the path
     * TaxcloudConfig::getApiType() reads, and hide with the module disabled.
     */
    public function testAdminFieldUsesThisSourceModelOnTheApiTypePath()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $this->assertNotFalse($systemXml, 'etc/adminhtml/system.xml must be parseable');

        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="api_type"]');

        $this->assertCount(1, $field, 'api_type must appear once under the TaxCloud settings group');
        $this->assertSame(ApiType::class, (string) $field[0]->source_model);
        $this->assertSame(TaxcloudConfig::XML_PATH_API_TYPE, (string) $field[0]->config_path);

        $depends = [];
        foreach ($field[0]->depends->field as $dependency) {
            $depends[(string) $dependency['id']] = (string) $dependency;
        }
        $this->assertSame(['enabled' => '1'], $depends);
    }

    /**
     * The field must be overridable per website and store view: which API
     * generation (and so which credential set) applies is a per-store decision.
     */
    public function testAdminFieldIsVisibleInAllThreeScopes()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="api_type"]');

        $this->assertSame('1', (string) $field[0]['showInDefault']);
        $this->assertSame('1', (string) $field[0]['showInWebsite']);
        $this->assertSame('1', (string) $field[0]['showInStore']);
    }

    /**
     * Each API generation must only show its own credential fields: the legacy
     * pair when soap is selected, the v3 pair when rest is. Magento's depends
     * also lifts required-entry on hidden fields, so the hidden set can't block
     * saving. All four keep the enabled gate. rest_api_key is deliberately NOT
     * required: a migrated (Bearer-mode) scope saves with the field empty and
     * authenticates by exchanging its V1 credentials.
     */
    public function testCredentialFieldsAreGatedByApiType()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');

        $expected = [
            'api_id' => [ApiType::SOAP, 'required-entry'],
            'api_key' => [ApiType::SOAP, 'required-entry'],
            'rest_api_key' => [ApiType::REST, ''],
            'rest_connection_id' => [ApiType::REST, 'required-entry'],
        ];

        foreach ($expected as $fieldId => [$apiType, $validate]) {
            $field = $systemXml->xpath(
                '//section[@id="tax"]/group[@id="taxcloud"]/field[@id="' . $fieldId . '"]'
            );
            $this->assertCount(1, $field, $fieldId . ' must appear once under the TaxCloud settings group');

            $depends = [];
            foreach ($field[0]->depends->field as $dependency) {
                $depends[(string) $dependency['id']] = (string) $dependency;
            }
            $this->assertSame(
                ['enabled' => '1', 'api_type' => $apiType],
                $depends,
                $fieldId . ' must be visible only when its API generation is selected'
            );
            $this->assertSame(
                $validate,
                (string) $field[0]->validate,
                $validate === ''
                    ? $fieldId . ' must not be required (Bearer mode saves it empty)'
                    : $fieldId . ' must be required'
            );
        }
    }

    /**
     * The optional-key promise only holds if the comment tells admins why the
     * field may stay empty — otherwise a migrated install looks broken.
     */
    public function testRestApiKeyCommentExplainsTheBearerFallback()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="rest_api_key"]');

        $this->assertStringContainsString('Optional if V1 SOAP credentials', (string) $field[0]->comment);
    }

    /**
     * The v3 key is a secret: it must be stored encrypted and rendered
     * obscured, unlike the legacy plain-text api_key.
     */
    public function testRestApiKeyIsObscuredAndEncrypted()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="rest_api_key"]');

        $this->assertSame('obscure', (string) $field[0]['type']);
        $this->assertSame(
            \Magento\Config\Model\Config\Backend\Encrypted::class,
            (string) $field[0]->backend_model
        );
    }

    /**
     * The REST base URL is config-only (like a wsdl_url staging override): a
     * shipped default in config.xml, no admin field.
     */
    public function testRestEndpointHasConfigDefaultButNoAdminField()
    {
        $configXml = simplexml_load_file(__DIR__ . '/../../../../../etc/config.xml');
        $node = $configXml->xpath('/config/default/tax/taxcloud_settings/rest_endpoint');
        $this->assertCount(1, $node, 'etc/config.xml must declare a rest_endpoint default');
        $this->assertSame(TaxcloudConfig::DEFAULT_REST_ENDPOINT, (string) $node[0]);

        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="rest_endpoint"]');
        $this->assertCount(0, $field, 'rest_endpoint must not be exposed in the admin form');
    }

    /**
     * Fresh installs must land on the current API. Existing installs are pinned
     * to SOAP by the PinSoapApiTypeForExistingInstalls data patch, not here.
     */
    public function testConfigXmlDefaultIsRest()
    {
        $configXml = simplexml_load_file(__DIR__ . '/../../../../../etc/config.xml');
        $this->assertNotFalse($configXml, 'etc/config.xml must be parseable');

        $node = $configXml->xpath('/config/default/tax/taxcloud_settings/api_type');

        $this->assertCount(1, $node, 'etc/config.xml must declare an api_type default');
        $this->assertSame(ApiType::REST, (string) $node[0]);
    }
}
