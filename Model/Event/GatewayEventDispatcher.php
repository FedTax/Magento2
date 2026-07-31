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

namespace Taxcloud\Magento2\Model\Event;

use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface;

/**
 * Dispatches the gateway's before/after events and carries observer edits back.
 *
 * Every gateway operation exposes a "before" hook (observers may rewrite the
 * request — e.g. address verification) and an "after" hook (observers may
 * rewrite the response). Both hand a DataObject holder to observers via the
 * `obj` event key; this collapses that create/set/dispatch/get boilerplate into
 * two calls.
 */
class GatewayEventDispatcher
{
    /**
     * @var ManagerInterface
     */
    private $eventManager;

    /**
     * @var DataObjectFactory
     */
    private $objectFactory;

    /**
     * @param ManagerInterface  $eventManager
     * @param DataObjectFactory $objectFactory
     */
    public function __construct(ManagerInterface $eventManager, DataObjectFactory $objectFactory)
    {
        $this->eventManager = $eventManager;
        $this->objectFactory = $objectFactory;
    }

    /**
     * Dispatch a "before" event carrying the request params, and return the
     * params as (possibly) modified by observers.
     *
     * @param string $eventName
     * @param array  $params
     * @param array  $context Additional event data (customer, order, quote, ...)
     * @return array
     */
    public function dispatchBefore($eventName, array $params, array $context = [])
    {
        $holder = $this->objectFactory->create();
        $holder->setParams($params);
        $this->eventManager->dispatch($eventName, array_merge(['obj' => $holder], $context));
        return $holder->getParams();
    }

    /**
     * Dispatch an "after" event carrying the response result, and return the
     * result as (possibly) modified by observers.
     *
     * @param string $eventName
     * @param mixed  $result
     * @param array  $context Additional event data (customer, order, quote, ...)
     * @return mixed
     */
    public function dispatchAfter($eventName, $result, array $context = [])
    {
        $holder = $this->objectFactory->create();
        $holder->setResult($result);
        $this->eventManager->dispatch($eventName, array_merge(['obj' => $holder], $context));
        return $holder->getResult();
    }
}
