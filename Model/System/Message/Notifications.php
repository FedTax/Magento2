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

namespace Taxcloud\Magento2\Model\System\Message;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use Taxcloud\Magento2\Model\Diagnostics\Acknowledgement;
use Taxcloud\Magento2\Model\Diagnostics\CollectorVerdict;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;

/**
 * Admin banner raised when TaxCloud is enabled but will not calculate tax.
 *
 * Modelled on \Magento\Tax\Model\System\Message\Notifications, which merchants
 * already know from core's tax misconfiguration warning: an aggregator over
 * individual findings, critical severity, and a one-click dismissal link.
 *
 * A banner rather than a row in the TaxCloud configuration section, because
 * nobody has a reason to open that section on a store where the extension looks
 * installed, enabled and connected — which is exactly how this failure survives.
 */
class Notifications implements MessageInterface
{
    /**
     * Config path for the documentation link, mirroring tax/notification/info_url.
     */
    public const XML_PATH_INFO_URL = 'taxcloud/notification/info_url';

    /**
     * @var TaxCollectorDiagnostics
     */
    private $diagnostics;

    /**
     * @var Acknowledgement
     */
    private $acknowledgement;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var NotificationInterface[]
     */
    private $notifications;

    /**
     * @param TaxCollectorDiagnostics $diagnostics
     * @param Acknowledgement         $acknowledgement
     * @param UrlInterface            $urlBuilder
     * @param ScopeConfigInterface    $scopeConfig
     * @param Escaper                 $escaper
     * @param NotificationInterface[] $notifications
     */
    public function __construct(
        TaxCollectorDiagnostics $diagnostics,
        Acknowledgement $acknowledgement,
        UrlInterface $urlBuilder,
        ScopeConfigInterface $scopeConfig,
        Escaper $escaper,
        array $notifications = []
    ) {
        $this->diagnostics = $diagnostics;
        $this->acknowledgement = $acknowledgement;
        $this->urlBuilder = $urlBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->escaper = $escaper;
        $this->notifications = $notifications;
    }

    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'TAXCLOUD_COLLECTOR_NOTIFICATION';
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed()
    {
        $verdict = $this->diagnostics->verdict();

        if ($verdict->isHealthy()) {
            // A dismissal must not outlive the conflict it acknowledged: if the
            // merchant fixes it and the same conflict later returns, the banner
            // has to come back. Guarded inside clear(), so this writes at most
            // once — on the transition from a dismissed conflict to healthy.
            $this->acknowledgement->clear();
            return false;
        }

        if ($this->acknowledgement->matches($verdict->fingerprint())) {
            return false;
        }

        foreach ($this->notifications as $notification) {
            if ($notification->isDisplayed($verdict)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getText()
    {
        $verdict = $this->diagnostics->verdict();

        $text = '';
        foreach ($this->notifications as $notification) {
            if ($notification->isDisplayed($verdict)) {
                $text .= $notification->getText($verdict);
            }
        }

        $text .= '<p>';
        $text .= __(
            'See the <a href="%1">troubleshooting guide</a> for how to resolve this. ',
            $this->getInfoUrl()
        );
        $text .= __(
            'Or <a href="%1">ignore this notification</a> — it will return on its own if the '
            . 'conflict changes.',
            $this->getIgnoreUrl($verdict)
        );
        $text .= '</p>';

        return $text;
    }

    /**
     * Critical, matching how core treats a tax misconfiguration: an install in
     * this state is under-collecting tax, which is a filing exposure.
     *
     * @inheritDoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * @return string
     */
    public function getInfoUrl(): string
    {
        return $this->escaper->escapeUrl((string) $this->scopeConfig->getValue(self::XML_PATH_INFO_URL));
    }

    /**
     * Dismissal link, carrying the fingerprint of the conflict being shown.
     *
     * Passing it explicitly means the controller acknowledges what the admin
     * actually read, not whatever the verdict happens to say by the time the
     * click lands.
     *
     * @param CollectorVerdict $verdict
     * @return string
     */
    public function getIgnoreUrl(CollectorVerdict $verdict): string
    {
        return $this->urlBuilder->getUrl(
            'taxcloud/notification/ignore',
            ['state' => $verdict->fingerprint()]
        );
    }
}
