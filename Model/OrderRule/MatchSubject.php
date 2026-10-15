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

namespace Taxcloud\Magento2\Model\OrderRule;

/**
 * The order properties rules match on, taken from a quote or a placed order
 * by SubjectReader.
 *
 * Normalising both into one shape is what keeps the checkout-time and the
 * placement-time decision the same function: the evaluator never sees a quote
 * or an order, only this. A property that is not known yet is null, and a
 * non-empty filter never matches null — which is how a prefix rule "cannot
 * match" at checkout, where the order has no number.
 */
class MatchSubject
{
    /**
     * @var int
     */
    private $storeId;

    /**
     * @var int|null
     */
    private $customerGroupId;

    /**
     * @var string|null
     */
    private $paymentMethod;

    /**
     * @var string|null
     */
    private $shippingMethod;

    /**
     * @var string|null
     */
    private $orderNumber;

    /**
     * @param int $storeId
     * @param int|null $customerGroupId
     * @param string|null $paymentMethod
     * @param string|null $shippingMethod
     * @param string|null $orderNumber
     */
    public function __construct(
        int $storeId,
        ?int $customerGroupId,
        ?string $paymentMethod,
        ?string $shippingMethod,
        ?string $orderNumber
    ) {
        $this->storeId = $storeId;
        $this->customerGroupId = $customerGroupId;
        $this->paymentMethod = $paymentMethod === '' ? null : $paymentMethod;
        $this->shippingMethod = $shippingMethod === '' ? null : $shippingMethod;
        $this->orderNumber = $orderNumber === '' ? null : $orderNumber;
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * @return int|null
     */
    public function getCustomerGroupId(): ?int
    {
        return $this->customerGroupId;
    }

    /**
     * @return string|null
     */
    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    /**
     * @return string|null
     */
    public function getShippingMethod(): ?string
    {
        return $this->shippingMethod;
    }

    /**
     * @return string|null
     */
    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    /**
     * Identity of everything a rule can match on, to tell whether a cached
     * decision is still valid.
     *
     * @return string
     */
    public function fingerprint(): string
    {
        return implode('|', [
            $this->storeId,
            $this->customerGroupId ?? '',
            $this->paymentMethod ?? '',
            $this->shippingMethod ?? '',
            $this->orderNumber ?? '',
        ]);
    }
}
