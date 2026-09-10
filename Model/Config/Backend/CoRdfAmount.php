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

declare(strict_types=1);

namespace Taxcloud\Magento2\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\ValidatorException;

/**
 * Server-side validation of the Colorado Retail Delivery Fee amount.
 *
 * TaxCloud accepts any value on the TIC 11098 line without validation and
 * remits it as sent, so a typo here would be charged to customers and filed
 * with the state. The browser-side validate-number-range cannot be relied on
 * (config can be saved over the API), hence this backend model.
 */
class CoRdfAmount extends Value
{
    private const MIN = 0.0;
    private const MAX = 2.0;

    /**
     * @return $this
     * @throws ValidatorException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());

        if ($value === '' || !is_numeric($value)) {
            throw new ValidatorException(
                __('The Colorado Retail Delivery Fee amount must be a number.')
            );
        }

        $amount = (float) $value;
        if ($amount < self::MIN || $amount > self::MAX) {
            throw new ValidatorException(
                __(
                    'The Colorado Retail Delivery Fee amount must be between %1 and %2.',
                    number_format(self::MIN, 2),
                    number_format(self::MAX, 2)
                )
            );
        }

        $this->setValue(number_format($amount, 2, '.', ''));

        return parent::beforeSave();
    }
}
