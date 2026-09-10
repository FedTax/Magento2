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

use Magento\Framework\Cache\FrontendInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Taxcloud\Magento2\Api\GatewayInterface;
use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\CertificateRepository;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;

/**
 * The caching rules here are correctness rules, not performance ones.
 *
 * A cached "this customer has no certificates" and a failed read look identical
 * to a caller unless the repository keeps them apart — and they are opposite
 * answers to whether an order should be taxed. Likewise a set cached under one
 * TaxCloud account must never answer a store configured with another.
 */
class CertificateRepositoryTest extends TestCase
{
    /**
     * @var array<string, string> Fake cache backing store
     */
    private $store = [];

    /**
     * @var FrontendInterface
     */
    private $cacheType;

    protected function setUp(): void
    {
        $this->store = [];

        $this->cacheType = $this->createStub(FrontendInterface::class);
        $this->cacheType->method('load')->willReturnCallback(function ($key) {
            return $this->store[$key] ?? false;
        });
        $this->cacheType->method('save')->willReturnCallback(function ($data, $key) {
            $this->store[$key] = $data;
            return true;
        });
        $this->cacheType->method('remove')->willReturnCallback(function ($key) {
            unset($this->store[$key]);
            return true;
        });
    }

    /**
     * @param string $connectionId Account discriminator
     */
    private function config(string $connectionId = 'conn-a'): TaxcloudConfig
    {
        $config = $this->createStub(TaxcloudConfig::class);
        $config->method('getRestConnectionId')->willReturn($connectionId);
        $config->method('getApiId')->willReturn('api-a');

        return $config;
    }

    private function certificate(string $id = 'cert-tx', array $states = ['TX']): Certificate
    {
        return new Certificate($id, '42', $states, false, false, ['reason' => 'Resale']);
    }

    private function repository($gateway, string $connectionId = 'conn-a'): CertificateRepository
    {
        return new CertificateRepository(
            $gateway,
            $this->config($connectionId),
            new CacheKeyBuilder(),
            $this->cacheType
        );
    }

    public function testSecondReadIsServedFromCache()
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects($this->once())
            ->method('listCertificates')
            ->willReturn([$this->certificate()]);

        $repository = $this->repository($gateway);

        $first = $repository->forCustomer('42');
        $second = $repository->forCustomer('42');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame('cert-tx', $second[0]->getCertificateId());
        $this->assertSame(['TX'], $second[0]->getStates());
        $this->assertSame('Resale', $second[0]->getDetailValue('reason'));
    }

    /**
     * Two customers pointed at one identity resolve the same certificates, so
     * they share one entry rather than each caching a copy that can drift.
     */
    public function testOneIdentityIsOneCacheEntry()
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects($this->once())->method('listCertificates')->willReturn([$this->certificate()]);

        $repository = $this->repository($gateway);
        $repository->forCustomer('acme-corp');
        $repository->forCustomer('acme-corp');

        $this->assertCount(1, $this->store);
    }

    /**
     * A set fetched under one TaxCloud account must never answer a store
     * configured with another.
     */
    public function testDifferentAccountsDoNotShareEntries()
    {
        $gatewayA = $this->createStub(GatewayInterface::class);
        $gatewayA->method('listCertificates')->willReturn([$this->certificate('cert-a')]);
        $this->repository($gatewayA, 'conn-a')->forCustomer('42');

        $gatewayB = $this->createStub(GatewayInterface::class);
        $gatewayB->method('listCertificates')->willReturn([$this->certificate('cert-b')]);
        $fromB = $this->repository($gatewayB, 'conn-b')->forCustomer('42');

        $this->assertCount(2, $this->store, 'each account keys its own entry');
        $this->assertSame('cert-b', $fromB[0]->getCertificateId());
    }

    public function testDifferentIdentitiesDoNotShareEntries()
    {
        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('listCertificates')->willReturn([$this->certificate()]);

        $repository = $this->repository($gateway);
        $repository->forCustomer('42');
        $repository->forCustomer('acme-corp');

        $this->assertCount(2, $this->store);
    }

    /**
     * The rule that matters most: a transient outage must not pin "no
     * certificates" for the TTL, silently taxing an exempt customer long after
     * TaxCloud recovered.
     */
    public function testFailedReadIsNotCachedAndPropagates()
    {
        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('listCertificates')->willThrowException(new RuntimeException('taxcloud down'));

        $repository = $this->repository($gateway);

        $this->expectException(RuntimeException::class);
        try {
            $repository->forCustomer('42');
        } finally {
            $this->assertSame([], $this->store, 'a failure must leave nothing cached');
        }
    }

    /**
     * A genuine empty set IS cached — it is an answer, unlike a failure.
     */
    public function testGenuinelyEmptySetIsCached()
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects($this->once())->method('listCertificates')->willReturn([]);

        $repository = $this->repository($gateway);
        $this->assertSame([], $repository->forCustomer('42'));
        $this->assertSame([], $repository->forCustomer('42'));
        $this->assertCount(1, $this->store);
    }

    public function testCreateInvalidatesTheCustomersSet()
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('listCertificates')->willReturn([$this->certificate()]);
        $gateway->expects($this->once())->method('createCertificate')->willReturn('new-cert');

        $repository = $this->repository($gateway);
        $repository->forCustomer('42');
        $this->assertCount(1, $this->store);

        $this->assertSame('new-cert', $repository->create('42', ['states' => ['TX']]));
        $this->assertSame([], $this->store, 'the next read must go to TaxCloud, not the stale set');
    }

    public function testDeleteInvalidatesTheCustomersSet()
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('listCertificates')->willReturn([$this->certificate()]);
        $gateway->expects($this->once())->method('deleteCertificate');

        $repository = $this->repository($gateway);
        $repository->forCustomer('42');

        $repository->delete('cert-tx', '42');

        $this->assertSame([], $this->store);
    }

    public function testInvalidateOnlyDropsThatIdentity()
    {
        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('listCertificates')->willReturn([$this->certificate()]);

        $repository = $this->repository($gateway);
        $repository->forCustomer('42');
        $repository->forCustomer('acme-corp');
        $this->assertCount(2, $this->store);

        $repository->invalidate('42');

        $this->assertCount(1, $this->store, 'one customer\'s change must not evict everyone else');
    }
}
