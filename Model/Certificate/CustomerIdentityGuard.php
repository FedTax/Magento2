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

namespace Taxcloud\Magento2\Model\Certificate;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\AuthorizationInterface;

/**
 * Decides whether the current request may set a customer's TaxCloud identity.
 *
 * Why this exists rather than simply leaving the attribute out of the customer
 * account form: `used_in_forms` governs where Magento RENDERS an attribute, not
 * whether a save will accept it. A crafted account-edit submission, or a
 * customer-token REST call carrying the attribute, would otherwise write it.
 *
 * And writing it is not a cosmetic change. The identity determines whose
 * certificates a customer resolves, and TaxCloud enforces no ownership of its
 * own — verified live: it will apply any certificate on the account to any cart
 * that names it. So an unguarded write here is "grant myself any exemption on
 * this merchant's account", which is why it is refused by area first and
 * permission second rather than by hiding a form field.
 */
class CustomerIdentityGuard
{
    /**
     * ACL resource an administrator needs to manage certificates.
     */
    public const ACL_RESOURCE = 'Taxcloud_Magento2::certificates';

    /**
     * Areas a storefront visitor's request runs in. A write arriving from any
     * of these is customer-facing however it was routed.
     */
    private const CUSTOMER_FACING_AREAS = [
        Area::AREA_FRONTEND,
        Area::AREA_WEBAPI_REST,
        Area::AREA_WEBAPI_SOAP,
        Area::AREA_GRAPHQL,
    ];

    /**
     * @var State
     */
    private $appState;

    /**
     * @var AuthorizationInterface
     */
    private $authorization;

    /**
     * @param State $appState
     * @param AuthorizationInterface $authorization
     */
    public function __construct(State $appState, AuthorizationInterface $authorization)
    {
        $this->appState = $appState;
        $this->authorization = $authorization;
    }

    /**
     * Whether the current request may set the identity.
     *
     * @return bool
     */
    public function isWriteAllowed()
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // No area set: a CLI or setup context, not a customer request.
            // Data patches and seed scripts legitimately land here.
            return true;
        }

        if (in_array($area, self::CUSTOMER_FACING_AREAS, true)) {
            return false;
        }

        if ($area === Area::AREA_ADMINHTML) {
            return $this->authorization->isAllowed(self::ACL_RESOURCE);
        }

        // Crontab, console and other backend contexts: no customer is driving
        // them, so nothing to defend against here.
        return true;
    }
}
