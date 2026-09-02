<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\System\Message;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Acknowledgement;
use Taxcloud\Magento2\Model\Diagnostics\CollectorVerdict;
use Taxcloud\Magento2\Model\Diagnostics\StoreVerdict;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;
use Taxcloud\Magento2\Model\System\Message\Notification\CollectorOverridden;
use Taxcloud\Magento2\Model\System\Message\Notifications;
use Taxcloud\Magento2\Model\Tax;

/**
 * The dismissal gate.
 *
 * Core's tax notification stores a permanent boolean, which would put back the
 * silence this feature exists to remove: dismiss one competitor during a
 * migration, and a different one taking the slot months later never raises the
 * banner. These tests pin the replacement — the acknowledgement is scoped to the
 * conflict, and stops applying the moment the conflict changes or is fixed.
 */
#[AllowMockObjectsWithoutExpectations]
class NotificationsTest extends TestCase
{
    private function verdict(?string $winner): CollectorVerdict
    {
        if ($winner === null) {
            return new CollectorVerdict([new StoreVerdict(1, 'Default', Tax::class, true)]);
        }

        return new CollectorVerdict([new StoreVerdict(1, 'Default', $winner, false)]);
    }

    /**
     * @param string $acknowledged Fingerprint already stored, '' for none
     */
    private function build(CollectorVerdict $verdict, string $acknowledged, ?Acknowledgement $ack = null): Notifications
    {
        $diagnostics = $this->createMock(TaxCollectorDiagnostics::class);
        $diagnostics->method('verdict')->willReturn($verdict);

        if ($ack === null) {
            $ack = $this->createMock(Acknowledgement::class);
            $ack->method('get')->willReturn($acknowledged);
            $ack->method('matches')->willReturnCallback(
                static function (string $fingerprint) use ($acknowledged) {
                    return $fingerprint !== '' && $fingerprint === $acknowledged;
                }
            );
        }

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('http://example.test/ignore');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('http://docs.test/troubleshooting/');

        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);
        $escaper->method('escapeUrl')->willReturnArgument(0);

        return new Notifications(
            $diagnostics,
            $ack,
            $urlBuilder,
            $scopeConfig,
            $escaper,
            [new CollectorOverridden($escaper)]
        );
    }

    public function testDisplayedWhenAnotherModuleOwnsTheCollector()
    {
        $this->assertTrue($this->build($this->verdict('Competitor\\Total'), '')->isDisplayed());
    }

    public function testNotDisplayedWhenHealthy()
    {
        $this->assertFalse($this->build($this->verdict(null), '')->isDisplayed());
    }

    public function testNotDisplayedWhenTheCurrentConflictIsAcknowledged()
    {
        $verdict = $this->verdict('Competitor\\Total');

        $this->assertFalse($this->build($verdict, $verdict->fingerprint())->isDisplayed());
    }

    /**
     * The scenario a permanent boolean gets wrong.
     */
    public function testDisplayedAgainWhenADifferentModuleTakesTheSlot()
    {
        $dismissed = $this->verdict('Avalara\\Total')->fingerprint();

        $this->assertTrue($this->build($this->verdict('Vertex\\Total'), $dismissed)->isDisplayed());
    }

    /**
     * A dismissal must not outlive its conflict: once the verdict goes healthy
     * the acknowledgement is dropped, so the same conflict returning later
     * raises the banner again rather than staying hidden forever.
     */
    public function testAcknowledgementIsClearedOnceTheVerdictIsHealthy()
    {
        $ack = $this->createMock(Acknowledgement::class);
        $ack->expects($this->once())->method('clear');
        $ack->method('matches')->willReturn(false);

        $this->assertFalse($this->build($this->verdict(null), '', $ack)->isDisplayed());
    }

    public function testAcknowledgementIsNotClearedWhileTheConflictStands()
    {
        $ack = $this->createMock(Acknowledgement::class);
        $ack->expects($this->never())->method('clear');
        $ack->method('matches')->willReturn(false);

        $this->build($this->verdict('Competitor\\Total'), '', $ack)->isDisplayed();
    }

    public function testTextNamesTheWinnerAndOffersBothLinks()
    {
        $text = $this->build($this->verdict('Competitor\\Total'), '')->getText();

        $this->assertStringContainsString('Competitor\\Total', $text);
        $this->assertStringContainsString('Default', $text);
        $this->assertStringContainsString('http://docs.test/troubleshooting/', $text);
        $this->assertStringContainsString('http://example.test/ignore', $text);
    }

    /**
     * An install in this state is under-collecting tax, which is a filing
     * exposure — the same severity core gives its tax misconfiguration warning.
     */
    public function testSeverityIsCritical()
    {
        $this->assertSame(
            MessageInterface::SEVERITY_CRITICAL,
            $this->build($this->verdict('Competitor\\Total'), '')->getSeverity()
        );
    }
}
