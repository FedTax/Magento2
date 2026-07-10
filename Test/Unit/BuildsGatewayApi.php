<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit;

use Psr\Log\NullLogger;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Api;
use Taxcloud\Magento2\Model\Cache\ResultCache;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Event\GatewayEventDispatcher;
use Taxcloud\Magento2\Model\Fallback\MagentoTaxFallback;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;
use Taxcloud\Magento2\Model\Gateway\ExemptionValidator;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\ResponseMapper;
use Taxcloud\Magento2\Model\Gateway\RetryPolicy;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * Assembles a Model\Api from the same leaf mocks the pre-decomposition tests
 * used, by building the real gateway collaborators around them.
 *
 * This keeps the (large) behavioral test bodies intact after Model\Api moved to
 * constructor-injected collaborators: only construction changed, so the tests
 * still exercise the whole gateway end-to-end through the real collaborators.
 *
 * `$leaf` keys: scopeConfig, cacheType, eventManager, soapClientFactory,
 * objectFactory, regionFactory, logger, serializer, cartItemResponseHandler,
 * productTicService, taxCalculationService, quoteDetailsFactory,
 * quoteDetailsItemFactory, taxClassKeyFactory, customerAddressFactory,
 * refundDistributor.
 */
trait BuildsGatewayApi
{
    /**
     * @param array $leaf
     * @return array Constructor arguments for Model\Api, in order.
     */
    private function gatewayApiCollaborators(array $leaf): array
    {
        $config = new TaxcloudConfig($leaf['scopeConfig']);
        $soapGateway = new SoapGateway($leaf['soapClientFactory'], $config, new NullLogger());
        $requestBuilder = new RequestBuilder(
            $config,
            $leaf['scopeConfig'],
            $leaf['regionFactory'],
            $leaf['productTicService'],
            $leaf['refundDistributor'],
            new NullLogger()
        );
        $responseMapper = new ResponseMapper($leaf['cartItemResponseHandler'], new NullLogger());
        $cacheKeyBuilder = new CacheKeyBuilder();
        $resultCache = new ResultCache($leaf['cacheType'], $leaf['serializer'], $cacheKeyBuilder, $config);
        $exemptionValidator = new ExemptionValidator(
            $soapGateway,
            $config,
            $leaf['cacheType'],
            $cacheKeyBuilder,
            $responseMapper,
            new NullLogger()
        );
        $fallback = new MagentoTaxFallback(
            $leaf['customerAddressFactory'],
            $leaf['quoteDetailsFactory'],
            $leaf['quoteDetailsItemFactory'],
            $leaf['taxClassKeyFactory'],
            $leaf['taxCalculationService'],
            new NullLogger()
        );
        $eventDispatcher = new GatewayEventDispatcher($leaf['eventManager'], $leaf['objectFactory']);
        // Zero backoff so the retry path doesn't sleep during tests.
        $retryPolicy = new RetryPolicy(new NullLogger(), 0);
        $logger = $leaf['logger'] instanceof Logger
            ? new GatewayLogger($leaf['logger'], $config)
            : new GatewayLogger(new Logger('taxcloud-test'), $config);

        return [
            $config,
            $soapGateway,
            $requestBuilder,
            $responseMapper,
            $resultCache,
            $exemptionValidator,
            $fallback,
            $eventDispatcher,
            $retryPolicy,
            $logger,
        ];
    }

    /**
     * @param array $leaf
     * @return Api
     */
    private function buildGatewayApi(array $leaf): Api
    {
        return new Api(...$this->gatewayApiCollaborators($leaf));
    }
}
