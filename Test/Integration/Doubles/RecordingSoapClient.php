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

namespace Taxcloud\Magento2\Test\Integration\Doubles;

/**
 * Test double standing in for the \SoapClient that
 * {@see \Taxcloud\Magento2\Model\Api} talks to. It records every SOAP method
 * call and returns a canned response per method, so integration tests can
 * exercise the real Magento order / invoice / shipment / credit-memo flow
 * without ever touching the live TaxCloud SOAP endpoint.
 *
 * It extends \SoapClient so it satisfies both
 * \Magento\Framework\Webapi\Soap\ClientFactory::create()'s `@return \SoapClient`
 * contract and Api::getClient()'s type expectation — but it deliberately does
 * NOT call the parent constructor, which would fetch the TaxCloud WSDL over the
 * network. Every TaxCloud operation (lookup, authorizedWithCapture, Returned,
 * OrderDetails, GetExemptCertificates, verifyAddress) is a magic method on
 * \SoapClient, so we intercept them all through __call().
 */
class RecordingSoapClient extends \SoapClient
{
    /** @var array<int, array{method: string, args: array}> */
    private array $calls = [];

    /** @var array<string, mixed> SOAP method name => canned response (array|object|\Closure) */
    private array $responses;

    /**
     * @param array<string, mixed> $responses keyed by SOAP method name. A value
     *        may be a \Closure(array $args): mixed to compute a per-call response.
     */
    public function __construct(array $responses = [])
    {
        // Intentionally NOT calling parent::__construct(): constructing a real
        // \SoapClient would fetch the TaxCloud WSDL over the network, which is
        // exactly what these tests exist to avoid.
        $this->responses = $responses;
    }

    /**
     * Configure (or override) the canned response for a single SOAP method.
     *
     * @param mixed $response array, stdClass, or \Closure(array $args): mixed
     */
    public function setResponse(string $method, $response): void
    {
        $this->responses[$method] = $response;
    }

    /**
     * @param string $name
     * @param array  $arguments SOAP libs pass a single positional array argument
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function __call($name, $arguments)
    {
        $args = $arguments[0] ?? [];
        $this->calls[] = ['method' => $name, 'args' => $args];

        if (!array_key_exists($name, $this->responses)) {
            throw new \RuntimeException(sprintf(
                "RecordingSoapClient: no canned response configured for SOAP method '%s'. "
                . 'Add one via ->setResponse() or a fixture under '
                . 'Test/Integration/_files/soap_responses/.',
                $name
            ));
        }

        $response = $this->responses[$name];

        return $response instanceof \Closure ? $response($args) : $response;
    }

    /**
     * Every recorded call, in order, as {method, args} tuples.
     *
     * @return array<int, array{method: string, args: array}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * The argument payloads for every call to one SOAP method, in order.
     *
     * @return array<int, array>
     */
    public function callsTo(string $method): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                $out[] = $call['args'];
            }
        }
        return $out;
    }

    /**
     * How many times a given SOAP method was called.
     */
    public function callCount(string $method): int
    {
        return count($this->callsTo($method));
    }

    /**
     * The argument payload of the first call to $method, or null if never called.
     */
    public function firstCallArgs(string $method): ?array
    {
        return $this->callsTo($method)[0] ?? null;
    }

    /**
     * Forget all recorded calls (responses are kept). Useful when a single test
     * exercises two phases and wants a clean call count for the second.
     */
    public function resetCalls(): void
    {
        $this->calls = [];
    }
}
