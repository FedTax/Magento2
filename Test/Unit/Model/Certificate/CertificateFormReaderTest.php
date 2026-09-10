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

namespace Taxcloud\Magento2\Test\Unit\Model\Certificate;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\CertificateFormReader;

/**
 * Shape checking for a submitted certificate form.
 *
 * Worth being clear about what this is NOT: it does not check that the
 * purchaser qualifies for the exemption they are claiming. Nothing can —
 * TaxCloud accepts a certificate carrying an invented tax id, confirmed against
 * the live API. These rules exist so the APIs do not reject a form the customer
 * has already filled in, and so a certificate cannot record a value neither
 * transport understands.
 */
class CertificateFormReaderTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    /**
     * @var CertificateFormReader
     */
    private $reader;

    protected function setUp(): void
    {
        $this->reader = new CertificateFormReader();
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $this->reader->read(array_merge([
            'states' => ['TX'],
            'firstName' => 'Exempt',
            'lastName' => 'Customer',
            'address1' => '1100 Congress Ave',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'businessType' => 'WholesaleTrade',
            'reason' => 'Resale',
            'reasonDescription' => 'Resale',
        ], $overrides));
    }

    public function testAWellFormedSubmissionHasNoProblem()
    {
        $this->assertNull($this->reader->firstProblem($this->form()));
    }

    public function testStatesAreNormalizedAndDeduplicated()
    {
        $data = $this->form(['states' => [' tx ', 'TX', 'ny', 'TEXAS', '']]);

        $this->assertSame(['TX', 'NY'], $data['states']);
    }

    public function testStatesMayArriveAsACommaSeparatedString()
    {
        $data = $this->form(['states' => 'TX,NY']);

        $this->assertSame(['TX', 'NY'], $data['states']);
    }

    public function testAtLeastOneStateIsRequired()
    {
        $this->assertNotNull($this->reader->firstProblem($this->form(['states' => []])));
        $this->assertNotNull($this->reader->firstProblem($this->form(['states' => ['TEXAS']])));
    }

    public function testPurchaserNameAndAddressAreRequired()
    {
        foreach (['firstName', 'lastName', 'address1', 'city', 'state', 'zip'] as $field) {
            $this->assertNotNull(
                $this->reader->firstProblem($this->form([$field => ''])),
                $field . ' must be required'
            );
        }
    }

    /**
     * Both APIs reject an unrecognised enum outright, so a typo here would
     * surface as an opaque API error after the customer had filled the form in.
     */
    public function testReasonAndBusinessTypeMustBeRecognised()
    {
        $this->assertNotNull($this->reader->firstProblem($this->form(['reason' => 'BecauseISaidSo'])));
        $this->assertNotNull($this->reader->firstProblem($this->form(['businessType' => 'Spaceship'])));
    }

    /**
     * Optional, not required — checked against the live v3 API, which accepts an
     * empty description (201) and only rejects the key being absent altogether.
     * Demanding content here would impose a rule TaxCloud does not have.
     */
    public function testReasonDescriptionIsOptional()
    {
        $this->assertNull($this->reader->firstProblem($this->form(['reasonDescription' => ''])));
    }

    /**
     * v3 caps this at 20 characters and rejects anything longer.
     */
    public function testReasonDescriptionLengthIsCapped()
    {
        $this->assertNull($this->reader->firstProblem($this->form(['reasonDescription' => str_repeat('a', 20)])));
        $this->assertNotNull($this->reader->firstProblem($this->form(['reasonDescription' => str_repeat('a', 21)])));
    }

    /**
     * Identity is what ownership is decided by, so a form must not be able to
     * choose it — a submitted one would let an administrator file a certificate
     * under another customer's identifier.
     */
    public function testNoCustomerIdentityCanBeSubmitted()
    {
        $data = $this->reader->read([
            'states' => ['TX'],
            'customerId' => 'someone-else',
            'customerIdentity' => 'someone-else',
        ]);

        $this->assertArrayNotHasKey('customerId', $data);
        $this->assertArrayNotHasKey('customerIdentity', $data);
    }

    /**
     * v3 has no field for a tax id, so collecting one would mean accepting a
     * value a REST store silently discards and can never show back. It lives on
     * the signed certificate the merchant keeps instead.
     */
    public function testNoTaxIdIsCollected()
    {
        $data = $this->reader->read([
            'states' => ['TX'],
            'taxId' => '12-3456789',
            'taxType' => 'FEIN',
        ]);

        $this->assertArrayNotHasKey('taxId', $data);
        $this->assertArrayNotHasKey('taxType', $data);
    }

    /**
     * Every value both APIs accept must be offerable with a name a merchant
     * recognises — a gap here is a dropdown entry rendering as an identifier.
     */
    public function testEveryEnumValueHasAReadableLabel()
    {
        foreach ([CertificateFormReader::REASONS, CertificateFormReader::BUSINESS_TYPES] as $map) {
            $this->assertNotEmpty($map);

            foreach ($map as $value => $label) {
                $this->assertIsString($value);
                $this->assertNotSame('', $label, $value . ' must have a label');

                // The property is "reads as words", not "differs from the
                // value" — Resale, Mining and Utilities are already fine as
                // they stand. What must never survive is a CamelCase run,
                // which is what an unlabelled API value looks like.
                $this->assertDoesNotMatchRegularExpression(
                    '/[a-z][A-Z]/',
                    $label,
                    $value . ' still reads as an API identifier'
                );
            }
        }
    }

    /**
     * The labels match the WooCommerce plugin's, so a merchant running both —
     * or a support agent reading a ticket — is not left working out that two
     * different words describe one certificate.
     */
    public function testLabelsMatchTheSiblingProduct()
    {
        $this->assertSame('Wholesale Trade', CertificateFormReader::BUSINESS_TYPES['WholesaleTrade']);
        $this->assertSame(
            'Industrial Production or Manufacturing',
            CertificateFormReader::REASONS['IndustrialProductionOrManufacturing']
        );
        $this->assertSame('Direct Pay Permit', CertificateFormReader::REASONS['DirectPayPermit']);
    }

    public function testNonScalarInputIsIgnoredRatherThanCrashing()
    {
        $form = $this->reader->read([
            'states' => ['TX'],
            'firstName' => ['array', 'value'],
        ]);

        $this->assertNotNull($this->reader->firstProblem($form), 'an unusable name is a missing name');
    }
}
