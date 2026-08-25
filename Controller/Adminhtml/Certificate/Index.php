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

namespace Taxcloud\Magento2\Controller\Adminhtml\Certificate;

use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * The certificates a customer currently resolves — the grid, and the discovery
 * action, which are the same question.
 *
 * Reporting an EMPTY result is as important as reporting a full one, and is why
 * this exists as a deliberate action rather than a passive list. A merchant who
 * created a certificate in the TaxCloud portal typed a customer id by hand
 * there; if it does not match the identity Magento resolves, the customer holds
 * nothing as far as this module is concerned. Before this endpoint, that
 * situation looked exactly like "customer has no certificates" — and produced a
 * silently taxed customer with nothing to diagnose.
 *
 * Failure is reported as failure, never as an empty list, for the same reason
 * the gateway distinguishes them: they are opposite answers to whether the
 * customer is exempt.
 */
class Index extends AbstractCertificateAction implements HttpGetActionInterface
{
    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $customer = $this->customer();
        if ($customer === null) {
            return $this->error(__('Select a customer first.')->render());
        }

        $storeId = $this->storeId($customer);
        $identity = $this->identity->resolve($customer);

        try {
            $certificates = $this->certificates->forCustomer($identity, $storeId);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'identity' => $identity,
                'message' => __(
                    'Could not read this customer\'s certificates from TaxCloud: %1',
                    $e->getMessage()
                )->render(),
            ]);
        }

        return $this->json([
            'success' => true,
            'identity' => $identity,
            'identityIsDefault' => !$this->identity->isOverridden($customer),
            'storeId' => $storeId,
            'certificates' => array_map([$this, 'present'], $certificates),
        ]);
    }
}
