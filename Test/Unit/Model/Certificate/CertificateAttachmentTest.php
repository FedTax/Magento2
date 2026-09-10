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

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Certificate\CertificateAttachment;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * The write path for the attachment the resolver has always preferred.
 *
 * These cover the rules that decide whether a customer is taxed: auto-attach
 * fires only into an empty slot, clearing is a state of its own, and a no-op
 * neither saves nor logs.
 *
 * Doubles are stubs by default, following CertificateResolverTest: the few
 * tests that assert on HOW a collaborator was called swap in a mock.
 */
class CertificateAttachmentTest extends TestCase
{
    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var GatewayLogger
     */
    private $logger;

    /**
     * @var CustomerInterface
     */
    private $customer;

    /**
     * @var string What the resolver reports is currently attached
     */
    private $current = '';

    protected function setUp(): void
    {
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->logger = $this->createStub(GatewayLogger::class);
        $this->customer = $this->createStub(CustomerInterface::class);
        $this->customer->method('getId')->willReturn(7);
    }

    /**
     * @return CustomerRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function expectRepository()
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);

        return $this->customerRepository;
    }

    /**
     * @return GatewayLogger&\PHPUnit\Framework\MockObject\MockObject
     */
    private function expectLogger()
    {
        $this->logger = $this->createMock(GatewayLogger::class);

        return $this->logger;
    }

    /**
     * @return CustomerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function expectCustomer()
    {
        $this->customer = $this->createMock(CustomerInterface::class);
        $this->customer->method('getId')->willReturn(7);

        return $this->customer;
    }

    private function attachment(): CertificateAttachment
    {
        $resolver = $this->createStub(CertificateResolver::class);
        $resolver->method('attachedCertificateId')->willReturn($this->current);

        return new CertificateAttachment($this->customerRepository, $resolver, $this->logger);
    }

    public function testAttachesWhenNothingIsAttached()
    {
        $this->current = '';
        $this->expectCustomer()->expects($this->once())
            ->method('setCustomAttribute')
            ->with(CertificateResolver::ATTACHED_ATTRIBUTE, 'cert-1');

        $this->assertTrue($this->attachment()->setIfUnattached($this->customer, 'cert-1'));
    }

    public function testDoesNotDisplaceAnExistingAttachment()
    {
        $this->current = 'cert-existing';

        // The whole point of the empty-slot rule: adding a second certificate
        // must not silently re-file the customer against it.
        $this->expectRepository()->expects($this->never())->method('save');

        $this->assertFalse($this->attachment()->setIfUnattached($this->customer, 'cert-2'));
    }

    public function testExplicitSetReplacesAnExistingAttachment()
    {
        $this->current = 'cert-old';
        $this->expectCustomer()->expects($this->once())
            ->method('setCustomAttribute')
            ->with(CertificateResolver::ATTACHED_ATTRIBUTE, 'cert-new');

        $this->assertTrue($this->attachment()->set($this->customer, 'cert-new'));
    }

    public function testClearingIsAWriteOfItsOwn()
    {
        $this->current = 'cert-old';
        $this->expectCustomer()->expects($this->once())
            ->method('setCustomAttribute')
            ->with(CertificateResolver::ATTACHED_ATTRIBUTE, '');

        $this->assertTrue($this->attachment()->set($this->customer, ''));
    }

    public function testUnchangedValueNeitherSavesNorLogs()
    {
        $this->current = 'cert-same';
        $this->expectRepository()->expects($this->never())->method('save');

        $this->assertFalse($this->attachment()->set($this->customer, 'cert-same'));
    }

    public function testSavesThroughTheCustomerRepository()
    {
        $this->current = '';
        $this->expectRepository()->expects($this->once())->method('save');

        $this->attachment()->set($this->customer, 'cert-1');
    }

    public function testChangeIsLoggedWithBothValuesAndTheAdministrator()
    {
        $this->current = 'cert-old';
        $this->expectLogger()->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) {
                return strpos($message, 'cert-old') !== false
                    && strpos($message, 'cert-new') !== false
                    && strpos($message, 'alice') !== false
                    && strpos($message, '7') !== false;
            }));

        $this->attachment()->set($this->customer, 'cert-new', 'alice');
    }

    public function testClearingIsLoggedAsNoneRatherThanBlank()
    {
        $this->current = 'cert-old';
        $this->expectLogger()->expects($this->once())
            ->method('info')
            ->with($this->stringContains('(none)'));

        $this->attachment()->set($this->customer, '');
    }

    public function testWhitespaceOnlyIdentifierIsTreatedAsClearing()
    {
        $this->current = 'cert-old';
        $this->expectCustomer()->expects($this->once())
            ->method('setCustomAttribute')
            ->with(CertificateResolver::ATTACHED_ATTRIBUTE, '');

        $this->assertTrue($this->attachment()->set($this->customer, '   '));
    }

    public function testLogIsScopedToTheCustomersStore()
    {
        $this->current = '';

        // Store-aware like everything else here: which TaxCloud account this
        // matters to depends on where the customer sits, not on the ambient
        // store.
        $this->expectLogger()->expects($this->once())->method('setStore')->with(5);

        $this->attachment()->set($this->customer, 'cert-1', 'alice', 5);
    }
}
