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

namespace Taxcloud\Magento2\Observer\Adminhtml;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Canada\ConfigScopeStore;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Confirms Canada access right after the tax configuration is saved.
 *
 * Turning on Calculate Canadian Tax does nothing useful unless TaxCloud has
 * also enabled Canada on the account, and a merchant is most likely to miss
 * that at exactly this moment. So when a save leaves Canadian tax on for the
 * edited scope and touched something that decides whether Canadian lookups
 * can work — the setting itself, the API type or a credential — the access
 * check runs and its answer is shown with the save confirmation.
 *
 * Advisory only: the configuration is already saved when this runs, and
 * nothing here may break the save response.
 */
class CheckCanadaAccessOnSave implements ObserverInterface
{
    /**
     * Setting paths whose change warrants re-checking access.
     */
    private const RELEVANT_PATHS = [
        TaxcloudConfig::XML_PATH_CANADA_TAX_ENABLED,
        TaxcloudConfig::XML_PATH_API_TYPE,
        TaxcloudConfig::XML_PATH_REST_API_KEY,
        TaxcloudConfig::XML_PATH_REST_CONNECTION_ID,
        // Bearer auth exchanges the V1 pair, so it decides v3 access too.
        TaxcloudConfig::XML_PATH_API_ID,
        TaxcloudConfig::XML_PATH_API_KEY,
    ];

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var CanadaAccessChecker
     */
    private $checker;

    /**
     * @var ConfigScopeStore
     */
    private $scopeStore;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param TaxcloudConfig $config
     * @param CanadaAccessChecker $checker
     * @param ConfigScopeStore $scopeStore
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger Config-gated TaxCloud logger, bound in di.xml
     */
    public function __construct(
        TaxcloudConfig $config,
        CanadaAccessChecker $checker,
        ConfigScopeStore $scopeStore,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->checker = $checker;
        $this->scopeStore = $scopeStore;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $event = $observer->getEvent();
            $store = $this->scopeStore->resolve($event->getData('website'), $event->getData('store'));

            if (!$this->config->isEnabled($store) || !$this->config->isCanadaTaxEnabled($store)) {
                return;
            }

            $changedPaths = $event->getData('changed_paths');
            // Older saves may not report changed paths: check rather than
            // silently skip.
            if (is_array($changedPaths) && array_intersect(self::RELEVANT_PATHS, $changedPaths) === []) {
                return;
            }

            $result = $this->checker->check($store);
            if ($result->isEnabled()) {
                $this->messageManager->addSuccessMessage($result->getMessage());
            } else {
                $this->messageManager->addWarningMessage($result->getMessage());
            }
        } catch (\Throwable $e) {
            $this->logger->error('Canada access check after configuration save failed: ' . $e->getMessage());
        }
    }
}
