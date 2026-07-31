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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Dedicated cache type for TaxCloud API responses.
 *
 * Registered in etc/cache.xml, which is what surfaces it under
 * System → Cache Management as its own enable/refresh/flush row.
 *
 * TagScope stamps CACHE_TAG onto every entry written through this frontend and
 * scopes cleaning to that tag, so flushing the TaxCloud type touches only
 * TaxCloud entries and a full application flush is no longer the only way to
 * clear them.
 */
class Taxcloud extends TagScope
{
    /**
     * Cache type identifier, matching the type name in etc/cache.xml.
     */
    public const TYPE_IDENTIFIER = 'taxcloud';

    /**
     * Tag stamped on every entry written through this cache type.
     */
    public const CACHE_TAG = 'TAXCLOUD';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
