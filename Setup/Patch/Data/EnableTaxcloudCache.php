<?php

/**
 * Patch Data for Taxcloud Magento 2 Extension
 */

namespace Taxcloud\Magento2\Setup\Patch\Data;

use Magento\Framework\App\Cache\Manager;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Cache\Type\Taxcloud;

/**
 * Turn the TaxCloud cache type on.
 *
 * A cache type with no entry in app/etc/env.php reads as disabled, and a
 * disabled type silently drops every write, so an install that never enables
 * this one calls TaxCloud on every checkout. Fresh installs get an entry for
 * each declared type automatically; installs that gain the type through an
 * upgrade do not, which is what this patch covers.
 *
 * It runs once. Disabling the type afterwards is an operator decision and
 * stays put.
 */
class EnableTaxcloudCache implements DataPatchInterface
{
    /**
     * @var Manager
     */
    private $cacheManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Manager         $cacheManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Manager $cacheManager,
        LoggerInterface $logger
    ) {
        $this->cacheManager = $cacheManager;
        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @return array
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * Apply patch
     *
     * @return $this
     */
    public function apply()
    {
        try {
            $this->cacheManager->setEnabled([Taxcloud::TYPE_IDENTIFIER], true);
        } catch (\Throwable $e) {
            // Enabling persists to app/etc/env.php, which a pipeline deploy may
            // hand over read-only. Losing the cache is a performance problem;
            // failing the upgrade over it would be worse, so log the manual step
            // and let setup:upgrade finish.
            $this->logger->warning(
                'Taxcloud: could not enable the "' . Taxcloud::TYPE_IDENTIFIER . '" cache type ('
                . $e->getMessage() . '). TaxCloud responses will not be cached until you run '
                . '"bin/magento cache:enable ' . Taxcloud::TYPE_IDENTIFIER . '".'
            );
        }

        return $this;
    }
}
