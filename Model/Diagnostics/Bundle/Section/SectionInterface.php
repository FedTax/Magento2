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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Section;

use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleArchive;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\BundleContext;

/**
 * One independently collected part of a diagnostics bundle.
 *
 * Sections run in isolation: the generator catches anything one throws,
 * records it in the manifest and summary, and carries on. A partial bundle is
 * always better than an error page.
 */
interface SectionInterface
{
    /**
     * Stable identifier, used in the manifest's failure list.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Whether this section belongs in the bundle being generated.
     *
     * @param BundleContext $context
     * @return bool
     */
    public function isApplicable(BundleContext $context): bool;

    /**
     * Collect the section and add its file(s) to the archive.
     *
     * Everything added must already be scrubbed through the context; the data
     * returned is what the summary renders from.
     *
     * @param BundleContext $context
     * @param BundleArchive $archive
     * @return array
     */
    public function collect(BundleContext $context, BundleArchive $archive): array;
}
