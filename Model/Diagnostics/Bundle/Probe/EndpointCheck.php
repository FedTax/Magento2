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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Probe;

/**
 * DNS and TLS reachability of an API endpoint, measured separately from any
 * API call.
 *
 * The distinction is the diagnosis: a DNS failure or a TLS handshake that
 * never completes points at a firewall, proxy or outbound-network policy on
 * the merchant's host; a clean handshake followed by a 401 points at the
 * credential. Without it both read as "could not reach TaxCloud".
 */
class EndpointCheck
{
    /**
     * Proxy variables whose presence (never value) is reported.
     */
    private const PROXY_VARIABLES = ['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'NO_PROXY', 'no_proxy'];

    /**
     * @param string $url     Endpoint URL
     * @param int    $timeout Seconds; the store's API timeout
     * @return array
     */
    public function check(string $url, int $timeout): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? 'https')) : 'https';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'http' ? 80 : 443);

        $result = [
            'host' => $host,
            'port' => $port,
            'proxy_environment_variables' => $this->proxyVariables(),
            'dns' => ['ok' => false],
            'tls' => ['ok' => false],
        ];

        if ($host === '') {
            $result['dns']['error'] = 'Endpoint URL has no host';
            return $result;
        }

        $result['dns'] = $this->resolve($host);
        if (!$result['dns']['ok']) {
            $result['tls'] = ['ok' => false, 'skipped' => 'DNS resolution failed'];
            return $result;
        }

        $result['tls'] = $scheme === 'https'
            ? $this->handshake($host, $port, $timeout)
            : ['ok' => true, 'skipped' => 'plain HTTP endpoint'];

        return $result;
    }

    /**
     * @param string $host
     * @return array
     */
    private function resolve(string $host): array
    {
        $start = microtime(true);
        try {
            $addresses = gethostbynamel($host);
        } catch (\Throwable $e) {
            $addresses = false;
        }
        $ms = $this->elapsedMs($start);

        if ($addresses === false || $addresses === []) {
            return ['ok' => false, 'duration_ms' => $ms, 'error' => 'Host name could not be resolved'];
        }

        return ['ok' => true, 'duration_ms' => $ms, 'address_count' => count($addresses)];
    }

    /**
     * @param string $host
     * @param int    $port
     * @param int    $timeout
     * @return array
     */
    private function handshake(string $host, int $port, int $timeout): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        try {
            $socket = stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errno,
                $errstr,
                max(1, $timeout),
                STREAM_CLIENT_CONNECT,
                $context
            );
        } catch (\Throwable $e) {
            $socket = false;
            $errstr = $errstr !== '' ? $errstr : $e->getMessage();
        }
        $ms = $this->elapsedMs($start);

        if ($socket === false) {
            return [
                'ok' => false,
                'duration_ms' => $ms,
                'timed_out' => $ms >= $timeout * 1000,
                'error' => trim(($errno ? '[' . $errno . '] ' : '') . $errstr) ?: 'TLS handshake failed',
            ];
        }

        $result = ['ok' => true, 'duration_ms' => $ms];
        try {
            /** @var array<string, mixed> $meta crypto is present on TLS streams but not in the stub's shape */
            $meta = stream_get_meta_data($socket);
            $result['protocol'] = $meta['crypto']['protocol'] ?? null;
            $result['cipher'] = $meta['crypto']['cipher_name'] ?? null;

            $params = stream_context_get_params($socket);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($certificate !== null && function_exists('openssl_x509_parse')) {
                $parsed = openssl_x509_parse($certificate);
                if (is_array($parsed)) {
                    $result['certificate'] = [
                        'subject' => $parsed['subject']['CN'] ?? null,
                        'issuer' => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? null),
                        'valid_to' => isset($parsed['validTo_time_t']) ? gmdate('c', $parsed['validTo_time_t']) : null,
                    ];
                }
            }
        } finally {
            fclose($socket);
        }

        return $result;
    }

    /**
     * @return array<string, bool>
     */
    private function proxyVariables(): array
    {
        $present = [];
        foreach (self::PROXY_VARIABLES as $name) {
            if (getenv($name) !== false) {
                $present[$name] = true;
            }
        }

        return $present;
    }

    /**
     * @param float $start
     * @return int
     */
    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
