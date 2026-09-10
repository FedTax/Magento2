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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Controller\Adminhtml\Tic;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Taxcloud\Magento2\Api\TicLookupInterface;

/**
 * AJAX endpoint behind the TIC autocomplete.
 *
 * Thin HTTP shell over {@see TicLookupInterface}: takes a query and the scope
 * being edited, returns suggestions. TaxCloud is reached server-side, so no
 * credential ever travels to the browser.
 *
 * Responds with the lookup result verbatim — {available:false, reason} when the
 * lookup could not run, {available:true, suggestions:[…]} otherwise. An empty
 * suggestion list is a successful search that matched nothing, which the field
 * renders quite differently from being unable to search at all.
 */
class Search extends Action implements HttpPostActionInterface
{
    /**
     * Authorization is handled by {@see _isAllowed()} rather than a single
     * resource: a TIC can be set from three different admin areas, guarded by
     * three different resources.
     */
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /**
     * Every resource that legitimately reaches a TIC field.
     *
     * A catalog manager editing a product's TIC holds Magento_Catalog::products
     * and typically no tax-config permission at all. Guarding this endpoint
     * with the tax resource alone — as the connection test does, correctly, for
     * a field that only exists in the tax configuration — would leave them a
     * search box that silently returned nothing.
     */
    private const TIC_RESOURCES = [
        'Magento_Tax::config_tax',
        'Magento_Catalog::products',
        'Magento_Catalog::categories',
    ];

    /**
     * @var TicLookupInterface
     */
    private $ticLookup;

    /**
     * @param Context $context
     * @param TicLookupInterface $ticLookup
     */
    public function __construct(Context $context, TicLookupInterface $ticLookup)
    {
        parent::__construct($context);
        $this->ticLookup = $ticLookup;
    }

    /**
     * Allow any admin who can reach a TIC field from anywhere it appears.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        foreach (self::TIC_RESOURCES as $resource) {
            if ($this->_authorization->isAllowed($resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $request = $this->getRequest();
        $query = trim((string) $request->getParam('query', ''));
        $store = $this->resolveStore();

        // "resolve" asks what a stored code means; "search" offers candidates.
        $result = $request->getParam('mode') === 'resolve'
            ? $this->ticLookup->resolve($query, $store)
            : $this->ticLookup->search($query, $store);

        return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($result->toArray());
    }

    /**
     * The store whose configuration governs this lookup.
     *
     * The caller sends the scope it is editing: a store view id for the
     * category and configuration fields, nothing for the globally scoped
     * product attribute, which resolves at default scope. Never the ambient
     * store — an admin editing store B's category must search store B's API.
     *
     * @return int|null
     */
    private function resolveStore(): ?int
    {
        $store = $this->getRequest()->getParam('store');

        return $store === null || $store === '' ? null : (int) $store;
    }
}
