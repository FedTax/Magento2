<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;
use Taxcloud\Magento2\Model\Gateway\ExemptionValidator;
use Taxcloud\Magento2\Model\Gateway\ResponseMapper;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapClientProviderInterface;
use Taxcloud\Magento2\Test\Unit\Double\SoapClientDouble;

/**
 * Covers the exemption-certificate validation extracted from Model\Api:
 * short-circuit on empty inputs, cached-state matching, live GetExemptCertificates
 * fetch + cache, and the fail-closed behavior when the client or call fails.
 */
#[AllowMockObjectsWithoutExpectations]
class ExemptionValidatorTest extends TestCase
{
    private $provider;
    private $config;
    private $cacheType;
    private $soapClient;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(SoapClientProviderInterface::class);
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('getApiId')->willReturn('login-id');
        $this->config->method('getApiKey')->willReturn('secret-key');
        $this->cacheType = $this->createMock(CacheInterface::class);
        $this->soapClient = $this->getMockBuilder(SoapClientDouble::class)
            ->onlyMethods(['GetExemptCertificates'])
            ->getMock();
    }

    private function validator(): ExemptionValidator
    {
        return new ExemptionValidator(
            $this->provider,
            $this->config,
            $this->cacheType,
            new CacheKeyBuilder(),
            new ResponseMapper(new NullLogger()),
            new NullLogger()
        );
    }

    private function certResponse(string $certId, array $states): \stdClass
    {
        $exemptStates = [];
        foreach ($states as $abbr) {
            $es = new \stdClass();
            $es->StateAbbr = $abbr;
            $exemptStates[] = $es;
        }
        $detail = new \stdClass();
        $detail->ExemptStates = new \stdClass();
        $detail->ExemptStates->ExemptState = $exemptStates;
        $cert = new \stdClass();
        $cert->CertificateID = $certId;
        $cert->Detail = $detail;
        $result = new \stdClass();
        $result->ResponseType = 'OK';
        $result->ExemptCertificates = new \stdClass();
        $result->ExemptCertificates->ExemptionCertificate = [$cert];
        $response = new \stdClass();
        $response->GetExemptCertificatesResult = $result;
        return $response;
    }

    public function testReturnsNullOnEmptyInputs()
    {
        $this->assertNull($this->validator()->validate('', '42', 'GA'));
        $this->assertNull($this->validator()->validate('cert', '', 'GA'));
        $this->assertNull($this->validator()->validate('cert', '42', ''));
    }

    public function testUsesCachedStatesWhenPresentAndMatches()
    {
        $this->cacheType->method('load')->willReturn(json_encode(['GA', 'NJ']));

        $this->assertSame('cert-1', $this->validator()->validate('cert-1', '42', 'GA'));
    }

    public function testUsesCachedStatesWhenPresentAndDoesNotMatch()
    {
        $this->cacheType->method('load')->willReturn(json_encode(['NY']));

        $this->assertNull($this->validator()->validate('cert-1', '42', 'GA'));
    }

    public function testFetchesAndCachesStatesThenMatches()
    {
        $this->cacheType->method('load')->willReturn(false);
        $this->provider->method('getClient')->willReturn($this->soapClient);
        $this->soapClient->method('GetExemptCertificates')->willReturn($this->certResponse('cert-1', ['GA']));

        $this->cacheType->expects($this->once())
            ->method('save')
            ->with(json_encode(['GA']), $this->stringContains('cert-1'), [], ExemptionValidator::STATE_CACHE_TTL);

        $this->assertSame('cert-1', $this->validator()->validate('cert-1', '42', 'GA'));
    }

    public function testFetchedStatesThatDoNotCoverDestinationReturnNull()
    {
        $this->cacheType->method('load')->willReturn(false);
        $this->provider->method('getClient')->willReturn($this->soapClient);
        $this->soapClient->method('GetExemptCertificates')->willReturn($this->certResponse('cert-1', ['GA']));

        $this->assertNull($this->validator()->validate('cert-1', '42', 'NY'));
    }

    public function testReturnsNullWhenNoSoapClient()
    {
        $this->cacheType->method('load')->willReturn(false);
        $this->provider->method('getClient')->willReturn(null);

        $this->assertNull($this->validator()->validate('cert-1', '42', 'GA'));
    }

    public function testFailsClosedWhenSoapCallThrows()
    {
        $this->cacheType->method('load')->willReturn(false);
        $this->provider->method('getClient')->willReturn($this->soapClient);
        $this->soapClient->method('GetExemptCertificates')
            ->willThrowException(new \RuntimeException('soap down'));

        $this->assertNull($this->validator()->validate('cert-1', '42', 'GA'));
    }
}
