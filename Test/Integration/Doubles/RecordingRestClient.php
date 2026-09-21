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

namespace Taxcloud\Magento2\Test\Integration\Doubles;

use Taxcloud\Magento2\Model\Gateway\PingResult;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestCredentials;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;

/**
 * Test double standing in for {@see RestClient}, the v3 REST transport.
 *
 * The REST counterpart of {@see RecordingSoapClient}: it records every call the
 * module makes and answers each from a canned responder, so integration tests
 * can drive the real Magento order lifecycle over the v3 path without touching
 * TaxCloud. Responders are keyed "<METHOD> <path-prefix>" and matched longest
 * prefix first, because v3 paths carry identifiers ("/orders/refunds/100000042")
 * that a test should not have to spell out.
 *
 * It extends RestClient so it satisfies every consumer's type expectation, but
 * deliberately does NOT call the parent constructor — that would pull in the
 * curl factory, config and auth provider this double exists to bypass. Auth is
 * likewise stubbed: `ping()` and `pingForScope()` answer from a settable
 * outcome, so a test can make the connection look healthy (the default) or
 * broken without credentials.
 */
class RecordingRestClient extends RestClient
{
    /** @var array<int, array{method: string, path: string, body: array|null, store: mixed, connectionScoped: bool}> */
    private array $calls = [];

    /** @var array<string, \Closure> "<METHOD> <path-prefix>" => Closure(?array $body, mixed $store): RestResponse */
    private array $responders = [];

    private PingResult $pingResult;

    /**
     * @param array<string, \Closure> $responders "<METHOD> <path-prefix>" => responder
     */
    public function __construct(array $responders = [])
    {
        // Intentionally NOT calling parent::__construct(): the real client's
        // collaborators (curl factory, auth provider) are exactly what this
        // double replaces.
        $this->responders = $responders;
        $this->pingResult = new PingResult(PingResult::OK);
    }

    /**
     * Configure (or replace) the responder for a method and path prefix.
     *
     * @param \Closure $responder Closure(?array $body, mixed $store): RestResponse
     */
    public function respondTo(string $method, string $pathPrefix, \Closure $responder): void
    {
        $this->responders[strtoupper($method) . ' ' . $pathPrefix] = $responder;
    }

    /**
     * Answer every later ping with this outcome (default: OK).
     */
    public function setPingResult(PingResult $result): void
    {
        $this->pingResult = $result;
    }

    /**
     * @inheritDoc
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        $store = null,
        bool $connectionScoped = true
    ): RestResponse {
        $this->calls[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'body' => $body,
            'store' => $store,
            'connectionScoped' => $connectionScoped,
        ];

        $responder = $this->responderFor(strtoupper($method), $path);
        if ($responder === null) {
            throw new \RuntimeException(sprintf(
                "RecordingRestClient: no canned response configured for '%s %s'. Add one via ->respondTo().",
                strtoupper($method),
                $path
            ));
        }

        return $responder($body, $store);
    }

    /**
     * @inheritDoc
     */
    public function ping(RestCredentials $credentials, $store = null): PingResult
    {
        return $this->pingResult;
    }

    /**
     * @inheritDoc
     */
    public function pingForScope($store = null, ?string $connectionIdOverride = null): PingResult
    {
        $this->calls[] = [
            'method' => 'GET',
            'path' => '/ping',
            'body' => null,
            'store' => $store,
            'connectionScoped' => true,
        ];

        return $this->pingResult;
    }

    /**
     * Every recorded call, in order.
     *
     * @return array<int, array{method: string, path: string, body: array|null, store: mixed, connectionScoped: bool}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * Recorded calls matching a method and path prefix, in order.
     *
     * @return array<int, array{method: string, path: string, body: array|null, store: mixed, connectionScoped: bool}>
     */
    public function callsTo(string $method, string $pathPrefix): array
    {
        $method = strtoupper($method);
        $out = [];
        foreach ($this->calls as $call) {
            if ($call['method'] === $method && strpos($call['path'], $pathPrefix) === 0) {
                $out[] = $call;
            }
        }

        return $out;
    }

    public function callCount(string $method, string $pathPrefix): int
    {
        return count($this->callsTo($method, $pathPrefix));
    }

    /**
     * The request body of the first matching call, or null when there was none.
     *
     * @return array|null
     */
    public function firstBody(string $method, string $pathPrefix): ?array
    {
        $calls = $this->callsTo($method, $pathPrefix);

        return $calls === [] ? null : $calls[0]['body'];
    }

    /**
     * The first cart of the first POST /carts body — the v3 lookup payload
     * every lookup assertion is really about.
     *
     * @return array|null
     */
    public function firstLookupCart(): ?array
    {
        $body = $this->firstBody('POST', '/carts');

        return is_array($body) ? ($body['items'][0] ?? null) : null;
    }

    /**
     * Forget recorded calls, keeping responders — for a test that asserts about
     * a second phase (a refund after a placement) with a clean count.
     */
    public function resetCalls(): void
    {
        $this->calls = [];
    }

    /**
     * Longest matching prefix wins, so "/orders/refunds/…" is not served by the
     * "/orders" responder.
     */
    private function responderFor(string $method, string $path): ?\Closure
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->responders as $key => $responder) {
            [$responderMethod, $prefix] = explode(' ', $key, 2);
            if ($responderMethod !== $method || strpos($path, $prefix) !== 0) {
                continue;
            }
            if (strlen($prefix) > $bestLength) {
                $best = $responder;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }
}
