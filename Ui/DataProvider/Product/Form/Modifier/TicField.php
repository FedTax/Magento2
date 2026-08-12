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

namespace Taxcloud\Magento2\Ui\DataProvider\Product\Form\Modifier;

use Magento\Backend\Model\UrlInterface;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use Taxcloud\Magento2\Setup\Patch\Data\AddCategoryTicAttribute;

/**
 * Points the product form's TIC field at the autocomplete component.
 *
 * Unlike the category form, the product form has no XML file listing its
 * attribute fields — they are generated from EAV metadata at runtime, so the
 * only way to attach a component is to rewrite the generated meta.
 *
 * The field is located by name rather than by path: which fieldset an
 * attribute lands in depends on its attribute group, which a merchant can
 * change, so a hardcoded path would silently stop matching. Everything else
 * about the field — label, scope, validation, whether it is even present — is
 * left exactly as the platform generated it.
 */
class TicField implements ModifierInterface
{
    /**
     * Shared with the category attribute; both use the same attribute code.
     */
    private const FIELD = AddCategoryTicAttribute::ATTRIBUTE_CODE;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @param UrlInterface $url
     */
    public function __construct(UrlInterface $url)
    {
        $this->url = $url;
    }

    /**
     * @param array $meta
     * @return array
     */
    public function modifyMeta(array $meta)
    {
        $this->attach($meta);

        return $meta;
    }

    /**
     * @param array $data
     * @return array
     */
    public function modifyData(array $data)
    {
        return $data;
    }

    /**
     * Find the TIC field anywhere in the generated meta and attach the
     * component to it.
     *
     * @param array $node
     * @return bool Whether the field was found in this subtree
     */
    private function attach(array &$node): bool
    {
        foreach ($node as $key => &$child) {
            if (!is_array($child)) {
                continue;
            }

            if ($key === self::FIELD && isset($child['arguments']['data']['config'])) {
                $config = &$child['arguments']['data']['config'];
                $config['component'] = 'Taxcloud_Magento2/js/form/element/tic';
                $config['searchUrl'] = $this->url->getUrl('taxcloud/tic/search');
                // The product attribute is global scope, so lookup resolves at
                // default scope; there is no store to pass.
                $config['storeId'] = null;
                $config['fallbackHint'] = (string) __(
                    'Leave empty to inherit the category TIC, or the store default when no category sets one.'
                );

                return true;
            }

            if ($this->attach($child)) {
                return true;
            }
        }

        return false;
    }
}
