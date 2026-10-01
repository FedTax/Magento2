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
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Probe\ApiProbe;

/**
 * probe.json: live API calls made from the store at the moment of capture.
 */
class ProbeSection implements SectionInterface
{
    public const FILE = 'probe.json';

    /**
     * @var ApiProbe
     */
    private $probe;

    /**
     * @param ApiProbe $probe
     */
    public function __construct(ApiProbe $probe)
    {
        $this->probe = $probe;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'probe';
    }

    /**
     * @inheritDoc
     */
    public function isApplicable(BundleContext $context): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function collect(BundleContext $context, BundleArchive $archive): array
    {
        if (!$context->getRequest()->isRunProbe()) {
            $data = ['ran' => false, 'reason' => 'The live API probe was turned off when the bundle was generated.'];
        } else {
            $data = ['ran' => true] + $this->probe->probe($context->getScope()->getStores());
        }

        // No customer data: the probe uses a fixed test address and the store's
        // own origin. Masking it would mark TaxCloud's office address as hidden
        // customer data. Credentials are still redacted.
        $archive->addJson(self::FILE, $data, false);

        return $data;
    }
}
