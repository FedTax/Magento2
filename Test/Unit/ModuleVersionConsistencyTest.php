<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The module declares its version in three places and reports one of them to
 * TaxCloud on every request. Before this test they had already drifted — the
 * 1.4.0 branch still declared 1.3.0 — which would have shipped a User-Agent
 * confidently naming a version that was not the running code.
 *
 * The values are read, never hardcoded, so a release bump does not have to
 * remember to edit this file: only a disagreement fails.
 */
class ModuleVersionConsistencyTest extends TestCase
{
    /**
     * @return string
     */
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The version reported in the User-Agent, via PackageInfo.
     *
     * @return string
     */
    private function composerVersion(): string
    {
        $composer = json_decode((string) file_get_contents($this->moduleRoot() . '/composer.json'), true);

        $this->assertIsArray($composer, 'composer.json must be valid JSON');
        $this->assertArrayHasKey(
            'version',
            $composer,
            'composer.json must declare a version — PackageInfo reports it in the User-Agent, '
            . 'and returns an empty string when it is absent.'
        );

        return (string) $composer['version'];
    }

    /**
     * @return string
     */
    private function moduleXmlVersion(): string
    {
        $moduleXml = simplexml_load_string(
            (string) file_get_contents($this->moduleRoot() . '/etc/module.xml')
        );
        $this->assertNotFalse($moduleXml, 'etc/module.xml must be valid XML');

        $nodes = $moduleXml->xpath('//module[@name="Taxcloud_Magento2"]/@setup_version');
        $this->assertNotEmpty($nodes, 'etc/module.xml must declare setup_version for Taxcloud_Magento2');

        return (string) $nodes[0];
    }

    /**
     * The newest released heading in the changelog.
     *
     * @return string
     */
    private function changelogVersion(): string
    {
        $changelog = (string) file_get_contents($this->moduleRoot() . '/CHANGELOG.md');

        $this->assertSame(
            1,
            preg_match('/^## (?!Unreleased)(\S+)/m', $changelog, $matches),
            'CHANGELOG.md must have a released version heading'
        );

        return $matches[1];
    }

    public function testComposerAndModuleXmlDeclareTheSameVersion()
    {
        $this->assertSame(
            $this->composerVersion(),
            $this->moduleXmlVersion(),
            'composer.json version and etc/module.xml setup_version disagree. The User-Agent reports the '
            . 'composer.json value, so a mismatch means the module identifies itself as a version it is not.'
        );
    }

    public function testChangelogDocumentsTheDeclaredVersion()
    {
        $this->assertSame(
            $this->composerVersion(),
            $this->changelogVersion(),
            'The newest CHANGELOG.md heading does not match the declared version. Either the release notes '
            . 'were not promoted from "Unreleased", or the version bump was missed.'
        );
    }

    /**
     * A blank or placeholder version would reach TaxCloud as the extension
     * token and identify nothing.
     */
    public function testDeclaredVersionLooksLikeAVersion()
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $this->composerVersion());
    }
}
