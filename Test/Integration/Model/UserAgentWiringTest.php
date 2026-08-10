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

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;
use Taxcloud\Magento2\Model\Gateway\UserAgent;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The User-Agent's failure mode is invisible to unit tests: they inject the
 * builder by hand, so they prove the string is correct and prove nothing about
 * whether the object manager actually hands the real one to each transport, or
 * whether the real version sources resolve to anything in an installed
 * Magento. A silently unwired or silently degraded header still passes every
 * unit test and still lets every request succeed — it just stops identifying
 * anyone, which nobody notices until support asks for a version.
 *
 * So this test asserts two things the unit suite cannot: that all three
 * transports receive the same DI-managed instance, and that in a real install
 * that instance resolves real versions rather than "unknown".
 */
class UserAgentWiringTest extends IntegrationTestCase
{
    /**
     * The value an unresolvable component degrades to.
     */
    private const UNKNOWN = 'unknown';

    /**
     * Read a private collaborator to prove which instance was injected.
     *
     * Reflection is the point here rather than a shortcut: "was this wired"
     * has no public surface, and the alternative — inferring it from an
     * outgoing request — would need a live call per transport.
     *
     * @param object $object
     * @param string $property
     * @return mixed
     */
    private function injected(object $object, string $property)
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    public function testEveryTransportReceivesTheSharedUserAgentInstance(): void
    {
        $shared = $this->get(UserAgent::class);

        $this->assertSame(
            $shared,
            $this->injected($this->get(RestClient::class), 'userAgent'),
            'RestClient must receive the DI-managed UserAgent — every v3 operation and both pings '
            . 'are identified through it.'
        );
        $this->assertSame(
            $shared,
            $this->injected($this->get(TokenExchange::class), 'userAgent'),
            'TokenExchange must receive the DI-managed UserAgent — the credential exchange runs '
            . 'during setup:upgrade, often the first request a migrating install makes.'
        );
        $this->assertSame(
            $shared,
            $this->injected($this->get(SoapGateway::class), 'userAgent'),
            'SoapGateway must receive the DI-managed UserAgent. It is declared before the optional '
            . '$logger precisely so the object manager auto-wires it; a trailing optional argument '
            . 'would silently keep its default here while unit tests still passed.'
        );
    }

    /**
     * The version sources behave differently in a real install than under
     * mocks: PackageInfo parses the module's composer.json off disk, and
     * ProductMetadata resolves through the Composer runtime and a cache.
     */
    public function testRealInstallResolvesEveryComponent(): void
    {
        $userAgent = $this->get(UserAgent::class)->get();

        $this->assertStringNotContainsString(
            self::UNKNOWN,
            $userAgent,
            'Every component must resolve in a real install. A degraded header still sends and still '
            . 'succeeds, so nothing else would ever report this: ' . $userAgent
        );
        $this->assertStringNotContainsString(
            'UNKNOWN',
            $userAgent,
            "ProductMetadata's literal UNKNOWN must be normalized, not passed through."
        );
    }

    public function testReportsTheModulesDeclaredVersion(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true
        );

        $this->assertStringStartsWith(
            'TaxCloud-Magento2/' . $composer['version'] . ' ',
            $this->get(UserAgent::class)->get(),
            'The extension token must report the version this module declares.'
        );
    }

    public function testReportsTheInstallsMagentoVersionEditionAndPhp(): void
    {
        $metadata = $this->get(ProductMetadataInterface::class);
        $userAgent = $this->get(UserAgent::class)->get();

        $this->assertStringContainsString(
            sprintf('Magento/%s (%s)', $metadata->getVersion(), $metadata->getEdition()),
            $userAgent
        );
        $this->assertStringEndsWith(' PHP/' . PHP_VERSION, $userAgent);
    }

    /**
     * Attribution depends on one installation producing one string, so the
     * SOAP transport's two placements must agree with what REST sends.
     */
    public function testSoapOptionsCarryTheSameStringAsTheRestTransport(): void
    {
        $expected = $this->get(UserAgent::class)->get();

        $options = $this->get(SoapGateway::class)->buildSoapOptions();
        $context = stream_context_get_options($options['stream_context']);

        $this->assertSame($expected, $options['user_agent'] ?? null, 'SOAP call');
        $this->assertSame($expected, $context['http']['user_agent'] ?? null, 'WSDL fetch');
    }
}
