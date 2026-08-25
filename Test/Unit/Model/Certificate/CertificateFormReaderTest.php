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
            'taxId' => '12-3456789',
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
     * v1 tolerates an empty description and v3 requires one. Requiring it on
     * both keeps a certificate's meaning independent of which transport the
     * store happened to be using when it was created.
     */
    public function testReasonDescriptionIsRequiredOnBothTransports()
    {
        $this->assertNotNull($this->reader->firstProblem($this->form(['reasonDescription' => ''])));
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

    public function testTaxTypeDefaultsRatherThanBeingRequired()
    {
        $this->assertSame('FEIN', $this->form(['taxType' => ''])['taxType']);
        $this->assertSame('SSN', $this->form(['taxType' => 'SSN'])['taxType']);
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
