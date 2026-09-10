<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\PackageInfo;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Gateway\UserAgent;

/**
 * The identity every TaxCloud request carries: exact format, per-component
 * degradation, and the containment rule that assembling it can never fail a
 * request.
 */
#[AllowMockObjectsWithoutExpectations]
class UserAgentTest extends TestCase
{
    /**
     * @param string|null $extension Null makes getVersion() throw
     * @param string|null $magento Null makes getVersion() throw
     * @param string|null $edition Null makes getEdition() throw
     * @return UserAgent
     */
    private function build($extension = '1.4.0', $magento = '2.4.7-p3', $edition = 'Community'): UserAgent
    {
        $packageInfo = $this->createMock(PackageInfo::class);
        if ($extension === null) {
            $packageInfo->method('getVersion')->willThrowException(new \RuntimeException('composer.json unreadable'));
        } else {
            $packageInfo->method('getVersion')->willReturn($extension);
        }

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        if ($magento === null) {
            $productMetadata->method('getVersion')->willThrowException(new \RuntimeException('no version'));
        } else {
            $productMetadata->method('getVersion')->willReturn($magento);
        }
        if ($edition === null) {
            $productMetadata->method('getEdition')->willThrowException(new \RuntimeException('no edition'));
        } else {
            $productMetadata->method('getEdition')->willReturn($edition);
        }

        return new UserAgent($productMetadata, $packageInfo);
    }

    public function testFormatWithEveryComponentResolved()
    {
        $this->assertSame(
            'TaxCloud-Magento2/1.4.0 Magento/2.4.7-p3 (Community) PHP/' . PHP_VERSION,
            $this->build()->get()
        );
    }

    /**
     * The extension version is read from the module package; PackageInfo
     * returns '' when composer.json declares none.
     */
    public function testMissingExtensionVersionDegradesAlone()
    {
        $this->assertSame(
            'TaxCloud-Magento2/unknown Magento/2.4.7-p3 (Community) PHP/' . PHP_VERSION,
            $this->build('')->get()
        );
    }

    /**
     * ProductMetadata emits the literal string 'UNKNOWN' when it cannot
     * resolve a version. Left alone that would put two spellings of the same
     * condition on the wire.
     */
    public function testMagentoLiteralUnknownIsNormalized()
    {
        $ua = $this->build('1.4.0', 'UNKNOWN')->get();

        $this->assertSame('TaxCloud-Magento2/1.4.0 Magento/unknown (Community) PHP/' . PHP_VERSION, $ua);
        $this->assertStringNotContainsString('UNKNOWN', $ua);
    }

    public function testMissingEditionDegradesAlone()
    {
        $this->assertSame(
            'TaxCloud-Magento2/1.4.0 Magento/2.4.7-p3 (unknown) PHP/' . PHP_VERSION,
            $this->build('1.4.0', '2.4.7-p3', '')->get()
        );
    }

    /**
     * Every component unresolvable at once must still be a well-formed header:
     * no empty tokens, no doubled or missing separators.
     */
    public function testAllComponentsDegradeToAWellFormedHeader()
    {
        $this->assertSame(
            'TaxCloud-Magento2/unknown Magento/unknown (unknown) PHP/' . PHP_VERSION,
            $this->build('', '', '')->get()
        );
    }

    /**
     * Diagnostics must never cost a tax lookup: a throwing collaborator
     * degrades its own component and leaves the rest intact.
     */
    public function testThrowingCollaboratorStillYieldsAValidHeader()
    {
        $this->assertSame(
            'TaxCloud-Magento2/unknown Magento/unknown (unknown) PHP/' . PHP_VERSION,
            $this->build(null, null, null)->get()
        );
    }

    public function testOneThrowingCollaboratorDoesNotDegradeTheOthers()
    {
        $this->assertSame(
            'TaxCloud-Magento2/1.4.0 Magento/unknown (Community) PHP/' . PHP_VERSION,
            $this->build('1.4.0', null, 'Community')->get()
        );
    }

    public function testAdobeCommerceEditionIsReported()
    {
        $this->assertStringContainsString(
            'Magento/2.4.7-p3 (Enterprise)',
            $this->build('1.4.0', '2.4.7-p3', 'Enterprise')->get()
        );
    }

    /**
     * Shared by DI across all three transports, so the sources are consulted
     * once per request — including when the outcome was degraded.
     */
    public function testResultIsMemoized()
    {
        $packageInfo = $this->createMock(PackageInfo::class);
        $packageInfo->expects($this->once())->method('getVersion')->willReturn('1.4.0');

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->expects($this->once())->method('getVersion')->willReturn('2.4.7-p3');
        $productMetadata->expects($this->once())->method('getEdition')->willReturn('Community');

        $userAgent = new UserAgent($productMetadata, $packageInfo);

        $this->assertSame($userAgent->get(), $userAgent->get());
    }

    /**
     * Whitespace in a declared version would otherwise reach the wire and
     * break the single-space token grammar.
     */
    public function testSurroundingWhitespaceIsTrimmed()
    {
        $this->assertSame(
            'TaxCloud-Magento2/1.4.0 Magento/2.4.7-p3 (Community) PHP/' . PHP_VERSION,
            $this->build(" 1.4.0\n", ' 2.4.7-p3 ', ' Community ')->get()
        );
    }

    /**
     * The header is logged in full and crosses to a third party, so it must
     * stay free of anything scope- or credential-shaped.
     */
    public function testCarriesNothingBeyondTheFourComponents()
    {
        $this->assertMatchesRegularExpression(
            '#^TaxCloud-Magento2/\S+ Magento/\S+ \([^)]+\) PHP/\S+$#',
            $this->build()->get()
        );
    }
}
