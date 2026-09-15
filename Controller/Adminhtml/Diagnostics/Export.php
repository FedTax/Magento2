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

namespace Taxcloud\Magento2\Controller\Adminhtml\Diagnostics;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Audit\DiagnosticsAudit;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleGenerator;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleRequest;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleWorkspace;

/**
 * POST endpoint behind both "Download Diagnostics" buttons (tax configuration
 * and order view).
 *
 * Thin HTTP shell over {@see BundleGenerator}: reads the dialog's options,
 * generates, streams the ZIP from disk (never from memory) and deletes it once
 * sent. POST-only, so Magento's backend request validator enforces the form
 * key. Guarded by its own ACL resource because the bundle contains customer
 * data.
 */
class Export extends Action implements HttpPostActionInterface
{
    /**
     * Dedicated resource, following Taxcloud_Magento2::certificates.
     */
    public const ADMIN_RESOURCE = 'Taxcloud_Magento2::diagnostics';

    /**
     * @var BundleGenerator
     */
    private $generator;

    /**
     * @var FileFactory
     */
    private $fileFactory;

    /**
     * @var BundleWorkspace
     */
    private $workspace;

    /**
     * @var DiagnosticsAudit
     */
    private $audit;

    /**
     * @param Context          $context
     * @param BundleGenerator  $generator
     * @param FileFactory      $fileFactory
     * @param BundleWorkspace  $workspace
     * @param DiagnosticsAudit $audit
     */
    public function __construct(
        Context $context,
        BundleGenerator $generator,
        FileFactory $fileFactory,
        BundleWorkspace $workspace,
        DiagnosticsAudit $audit
    ) {
        parent::__construct($context);
        $this->generator = $generator;
        $this->fileFactory = $fileFactory;
        $this->workspace = $workspace;
        $this->audit = $audit;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $bundleRequest = $this->buildRequest();

        try {
            $result = $this->generator->generate($bundleRequest);
        } catch (LocalizedException $e) {
            $this->audit->record($bundleRequest, null, $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('The diagnostics bundle could not be generated: %1', $e->getMessage())
            );
            return $this->resultRedirectFactory->create()->setRefererOrBaseUrl();
        }

        $this->audit->record($bundleRequest, $result);

        try {
            return $this->fileFactory->create(
                $result->getFileName(),
                [
                    'type' => 'filename',
                    'value' => $this->workspace->toVarRelativePath($result->getPath()),
                    'rm' => true,
                ],
                DirectoryList::VAR_DIR,
                'application/zip'
            );
        } catch (\Throwable $e) {
            $this->workspace->remove($result->getPath());
            $this->messageManager->addErrorMessage(
                __('The diagnostics bundle could not be sent: %1', $e->getMessage())
            );
            return $this->resultRedirectFactory->create()->setRefererOrBaseUrl();
        }
    }

    /**
     * @return BundleRequest
     */
    private function buildRequest(): BundleRequest
    {
        $request = $this->getRequest();
        $storeParam = (string) $request->getParam('store', '');
        $websiteParam = (string) $request->getParam('website', '');
        $orderParam = (string) $request->getParam('order_id', '');

        if ($storeParam !== '') {
            $scopeType = BundleRequest::SCOPE_STORE;
            $scopeId = (int) $storeParam;
        } elseif ($websiteParam !== '') {
            $scopeType = BundleRequest::SCOPE_WEBSITE;
            $scopeId = (int) $websiteParam;
        } else {
            $scopeType = BundleRequest::SCOPE_DEFAULT;
            $scopeId = null;
        }

        $user = $this->_auth->getUser();

        return new BundleRequest(
            $scopeType,
            $scopeId,
            (string) $request->getParam('redact_pii', '0') === '1',
            (string) $request->getParam('log_window', BundleRequest::DEFAULT_LOG_WINDOW),
            (string) $request->getParam('probe', '1') === '1',
            $user ? (string) $user->getUserName() : '',
            BundleRequest::ORIGIN_ADMIN,
            $orderParam !== '' ? (int) $orderParam : null
        );
    }
}
