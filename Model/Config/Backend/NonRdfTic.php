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
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Rejects the Colorado Retail Delivery Fee TIC on the default and shipping
 * TIC settings.
 *
 * TaxCloud zero-rates any line carrying the RDF TIC and files its amount as
 * fee revenue, so a product or shipping charge under that code is silently
 * untaxed and mis-filed — no error surfaces anywhere downstream. The only
 * place the code may appear is the dedicated fee line the module builds.
 */
class NonRdfTic extends Value
{
    /**
     * @return $this
     * @throws ValidatorException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());

        $rdfTic = (string) $this->_config->getValue(
            TaxcloudConfig::XML_PATH_CO_RDF_TIC,
            $this->getScope() ?: 'default',
            $this->getScopeId()
        );
        if ($rdfTic === '') {
            $rdfTic = TaxcloudConfig::DEFAULT_CO_RDF_TIC;
        }

        if ($value !== '' && ltrim($value, '0') === ltrim($rdfTic, '0')) {
            throw new ValidatorException(
                __(
                    'TIC %1 is reserved for the Colorado Retail Delivery Fee line and cannot be used here: '
                    . 'TaxCloud does not tax lines under this code and files their amounts as fee revenue.',
                    $rdfTic
                )
            );
        }

        return parent::beforeSave();
    }
}
