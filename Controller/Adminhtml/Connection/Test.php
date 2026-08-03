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

namespace Taxcloud\Magento2\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Taxcloud\Magento2\Model\Gateway\ConnectionTester;

/**
 * AJAX endpoint behind the admin "Test Connection" button.
 *
 * Thin HTTP shell: authorization comes from the same ACL resource that guards
 * the tax configuration section, everything else lives in
 * {@see ConnectionTester}. Responds with JSON {success, message}.
 */
class Test extends Action implements HttpPostActionInterface
{
    /**
     * Same resource that protects Stores → Configuration → Sales → Tax.
     */
    public const ADMIN_RESOURCE = 'Magento_Tax::config_tax';

    /**
     * @var ConnectionTester
     */
    private $connectionTester;

    /**
     * @param Context $context
     * @param ConnectionTester $connectionTester
     */
    public function __construct(Context $context, ConnectionTester $connectionTester)
    {
        parent::__construct($context);
        $this->connectionTester = $connectionTester;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $request = $this->getRequest();

        $outcome = $this->connectionTester->test(
            [
                'api_type' => $request->getParam('api_type'),
                'api_id' => $request->getParam('api_id'),
                'api_key' => $request->getParam('api_key'),
                'rest_api_key' => $request->getParam('rest_api_key'),
                'rest_connection_id' => $request->getParam('rest_connection_id'),
            ],
            $request->getParam('website'),
            $request->getParam('store')
        );

        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $result->setData([
            'success' => $outcome['success'],
            'message' => (string) $outcome['message'],
        ]);
    }
}
