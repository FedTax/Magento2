<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Gateway\RetryPolicy;

/**
 * Covers the retry discipline extracted from Model\Api::callSoapWithRetry /
 * isTimeoutError: success passes through, transient faults retry once, timeouts
 * are never retried, and the final exception surfaces once retries are spent.
 */
#[AllowMockObjectsWithoutExpectations]
class RetryPolicyTest extends TestCase
{
    private function policy(?LoggerInterface $logger = null): RetryPolicy
    {
        // backoff 0 so the retry path does not actually sleep during tests.
        return new RetryPolicy($logger ?? $this->createMock(LoggerInterface::class), 0);
    }

    public function testReturnsCallResultWithoutRetryOnSuccess()
    {
        $calls = 0;
        $result = $this->policy()->execute(function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls, 'a successful call must not be retried');
    }

    public function testRetriesOnceOnTransientFaultThenSucceeds()
    {
        $calls = 0;
        $result = $this->policy()->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('transient blip');
            }
            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls, 'a transient fault should be retried exactly once by default');
    }

    public function testRethrowsAfterRetriesExhausted()
    {
        $calls = 0;
        try {
            $this->policy()->execute(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('still broken');
            });
            $this->fail('expected the final exception to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('still broken', $e->getMessage());
        }
        $this->assertSame(2, $calls, 'initial attempt + one retry = two calls');
    }

    public function testTimeoutIsNeverRetried()
    {
        $calls = 0;
        try {
            $this->policy()->execute(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('Connection timed out after 10s');
            });
            $this->fail('expected the timeout to be rethrown immediately');
        } catch (\RuntimeException $e) {
            $this->assertSame('Connection timed out after 10s', $e->getMessage());
        }
        $this->assertSame(1, $calls, 'timeouts must not be retried');
    }

    public function testRespectsCustomMaxRetries()
    {
        $calls = 0;
        try {
            $this->policy()->execute(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('nope');
            }, 3);
            $this->fail('expected exhaustion');
        } catch (\RuntimeException $e) {
            // expected
        }
        $this->assertSame(4, $calls, 'initial attempt + three retries = four calls');
    }

    public function testLogsEachRetryAttempt()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('retrying (1/1)'));

        $calls = 0;
        $this->policy($logger)->execute(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('blip');
            }
            return 'ok';
        });
    }

    /**
     * @param string $message
     * @param bool   $expected
     * @dataProvider timeoutMessageProvider
     */
    #[DataProvider('timeoutMessageProvider')]
    public function testIsTimeoutErrorByMessage(string $message, bool $expected)
    {
        $this->assertSame(
            $expected,
            $this->policy()->isTimeoutError(new \RuntimeException($message))
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function timeoutMessageProvider(): array
    {
        return [
            'timed out' => ['Operation timed out', true],
            'timeout word' => ['Read timeout reached', true],
            'fetching headers' => ['Error Fetching http headers', true],
            'could not connect' => ['Could not connect to host', true],
            'failed to open' => ['failed to open stream', true],
            'unrelated fault' => ['Encoding: object has no property', false],
        ];
    }

    public function testHttpSoapFaultIsTreatedAsTimeout()
    {
        $fault = new \SoapFault('HTTP', 'Error Fetching http headers');
        $this->assertTrue($this->policy()->isTimeoutError($fault));
    }

    /**
     * A transport supplies its own rule — REST retries 5xx and never 4xx, which
     * the fault-code-and-message default cannot express.
     */
    public function testPerCallPredicateDecidesRetryability()
    {
        $retryServerErrors = static fn (\Throwable $e): bool => $e->getCode() >= 500;

        $calls = 0;
        $result = $this->policy()->execute(
            function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('service unavailable', 503);
                }
                return 'recovered';
            },
            1,
            $retryServerErrors
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls, '5xx is retryable under this predicate');
    }

    public function testPerCallPredicateRejectionFailsImmediately()
    {
        $retryServerErrors = static fn (\Throwable $e): bool => $e->getCode() >= 500;

        $calls = 0;
        try {
            $this->policy()->execute(
                function () use (&$calls) {
                    $calls++;
                    throw new \RuntimeException('unauthorized', 401);
                },
                1,
                $retryServerErrors
            );
            $this->fail('expected the non-retryable fault to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('unauthorized', $e->getMessage());
        }

        $this->assertSame(1, $calls, '4xx must not be retried under this predicate');
    }

    /**
     * The predicate is the sole authority once supplied: a timeout, which the
     * default never retries, is retried when the predicate says so.
     */
    public function testPredicateOverridesTheDefaultTimeoutRule()
    {
        $retryEverything = static fn (\Throwable $e): bool => true;

        $calls = 0;
        $result = $this->policy()->execute(
            function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('Operation timed out');
                }
                return 'recovered';
            },
            1,
            $retryEverything
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls, 'the predicate outranks the default timeout rule');
    }

    /**
     * A gateway configures its rule once at construction rather than repeating
     * it at every call site.
     */
    public function testConstructorPredicateAppliesWhenNoPerCallPredicateGiven()
    {
        $policy = new RetryPolicy(
            $this->createMock(LoggerInterface::class),
            0,
            static fn (\Throwable $e): bool => false
        );

        $calls = 0;
        try {
            $policy->execute(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('transient blip');
            });
            $this->fail('expected the fault to surface without retry');
        } catch (\RuntimeException $e) {
            $this->assertSame('transient blip', $e->getMessage());
        }

        $this->assertSame(1, $calls, 'the instance predicate marked this non-retryable');
    }

    public function testPerCallPredicateOverridesConstructorPredicate()
    {
        $policy = new RetryPolicy(
            $this->createMock(LoggerInterface::class),
            0,
            static fn (\Throwable $e): bool => false
        );

        $calls = 0;
        $result = $policy->execute(
            function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('transient blip');
                }
                return 'recovered';
            },
            1,
            static fn (\Throwable $e): bool => true
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls, 'the per-call predicate wins over the instance default');
    }

    /**
     * Default behaviour is unchanged when no predicate is supplied anywhere.
     */
    public function testDefaultRetryabilityRetriesEverythingButTimeouts()
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isRetryableByDefault(new \RuntimeException('transient blip')));
        $this->assertFalse($policy->isRetryableByDefault(new \RuntimeException('Operation timed out')));
    }

    /**
     * SOAP v1 Returned is not deduplicated on TaxCloud's side, so only a failure
     * that proves the request never left the client may be retried. Anything
     * ambiguous could be a refund TaxCloud already booked.
     *
     * @param string $message
     * @param bool   $expected
     * @dataProvider nonIdempotentMessageProvider
     */
    #[DataProvider('nonIdempotentMessageProvider')]
    public function testIsRetryableForNonIdempotentOnlyAcceptsUndeliveredRequests(string $message, bool $expected)
    {
        $this->assertSame(
            $expected,
            $this->policy()->isRetryableForNonIdempotent(new \RuntimeException($message)),
            $message
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function nonIdempotentMessageProvider(): array
    {
        return [
            // Never reached TaxCloud — nothing can have been booked.
            'could not connect' => ['Could not connect to host', true],
            'failed to open stream' => ['failed to open stream: Connection refused', true],
            'connection refused' => ['Connection refused', true],
            'dns failure' => ['php_network_getaddresses: getaddrinfo failed: Name or service not known', true],
            'name resolution' => ['Temporary failure in name resolution', true],
            // Sent, outcome unknown — retrying risks a second refund.
            'read timeout' => ['Operation timed out', false],
            'fetching headers' => ['Error Fetching http headers', false],
            'malformed response' => ['looks like we got no XML document', false],
            'application fault' => ['Server was unable to process request', false],
        ];
    }

    public function testNonIdempotentPredicateDoesNotRetryAnAmbiguousFault()
    {
        $calls = 0;
        try {
            $this->policy()->execute(
                function () use (&$calls) {
                    $calls++;
                    throw new \SoapFault('HTTP', 'Error Fetching http headers');
                },
                1,
                [$this->policy(), 'isRetryableForNonIdempotent']
            );
            $this->fail('expected the ambiguous fault to surface without retry');
        } catch (\SoapFault $e) {
            $this->assertStringContainsString('Error Fetching http headers', $e->getMessage());
        }

        $this->assertSame(1, $calls, 'a request that may have been processed must not be repeated');
    }

    public function testNonIdempotentPredicateStillRetriesAConnectFailure()
    {
        $calls = 0;
        $result = $this->policy()->execute(
            function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    throw new \SoapFault('HTTP', 'Could not connect to host');
                }
                return 'recovered';
            },
            1,
            [$this->policy(), 'isRetryableForNonIdempotent']
        );

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls, 'a request that never left the client is safe to repeat');
    }
}
