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

namespace Taxcloud\Magento2\Controller\Adminhtml\Canada;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Canada\ConfigScopeStore;

/**
 * AJAX endpoint behind the admin "Check Canada Access" button.
 *
 * Thin HTTP shell over {@see CanadaAccessChecker}, run against the saved
 * settings of the scope being edited. Responds with JSON
 * {success, outcome, message}.
 */
class Check extends Action implements HttpPostActionInterface
{
    /**
     * Same resource that protects Stores → Configuration → Sales → Tax.
     */
    public const ADMIN_RESOURCE = 'Magento_Tax::config_tax';

    /**
     * @var CanadaAccessChecker
     */
    private $checker;

    /**
     * @var ConfigScopeStore
     */
    private $scopeStore;

    /**
     * @param Context $context
     * @param CanadaAccessChecker $checker
     * @param ConfigScopeStore $scopeStore
     */
    public function __construct(Context $context, CanadaAccessChecker $checker, ConfigScopeStore $scopeStore)
    {
        parent::__construct($context);
        $this->checker = $checker;
        $this->scopeStore = $scopeStore;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $request = $this->getRequest();
        $store = $this->scopeStore->resolve($request->getParam('website'), $request->getParam('store'));

        $outcome = $this->checker->check($store);

        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $result->setData([
            'success' => $outcome->isEnabled(),
            'outcome' => $outcome->getOutcome(),
            'message' => (string) $outcome->getMessage(),
        ]);
    }
}
