<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

// Namespaced stand-ins for the functions the processor calls unqualified: PHP
// resolves those to the processor's namespace first, which is how a host with
// getmypid()/random_bytes() in disable_functions is simulated here.
namespace Taxcloud\Magento2\Logger\Processor {

    use Taxcloud\Magento2\Test\Unit\Logger\HostRestrictions;

    function function_exists($name)
    {
        if ($name === 'getmypid' && HostRestrictions::$getmypidDisabled) {
            return false;
        }

        return \function_exists($name);
    }

    function random_bytes($length)
    {
        if (HostRestrictions::$randomBytesFails) {
            throw new \Exception('Could not gather sufficient random data');
        }

        return \random_bytes($length);
    }
}

namespace Taxcloud\Magento2\Test\Unit\Logger {

    use Monolog\Level;
    use Monolog\LogRecord;
    use PHPUnit\Framework\TestCase;
    use Taxcloud\Magento2\Logger\Logger;
    use Taxcloud\Magento2\Logger\Processor\RequestIdentityProcessor;

    /**
     * Switches for the namespaced function stand-ins above.
     */
    class HostRestrictions
    {
        /** @var bool */
        public static $getmypidDisabled = false;

        /** @var bool */
        public static $randomBytesFails = false;
    }

    /**
     * Every record carries the request that wrote it — and on a host that
     * forbids reading the process id, logging keeps working without it.
     */
    class RequestIdentityProcessorTest extends TestCase
    {
        protected function tearDown(): void
        {
            HostRestrictions::$getmypidDisabled = false;
            HostRestrictions::$randomBytesFails = false;
        }

        private function logLine(RequestIdentityProcessor $processor, string $message): string
        {
            $stream = fopen('php://memory', 'w+');
            $logger = new Logger('tclogger', [new \Monolog\Handler\StreamHandler($stream)], [$processor]);
            $logger->info($message);
            rewind($stream);

            return (string) stream_get_contents($stream);
        }

        public function testAddsARequestIdAndTheProcessIdToTheFormattedLine()
        {
            $line = $this->logLine(new RequestIdentityProcessor(), 'SoapClient created');

            $this->assertSame(1, preg_match('/\{"request":"([0-9a-f]{8})","pid":(\d+)\}\s*$/', $line, $m), $line);
            $this->assertSame(getmypid(), (int) $m[2]);
        }

        public function testTheRequestIdIsStablePerInstanceAndDiffersBetweenInstances()
        {
            $processor = new RequestIdentityProcessor();
            preg_match('/"request":"([0-9a-f]+)"/', $this->logLine($processor, 'one'), $first);
            preg_match('/"request":"([0-9a-f]+)"/', $this->logLine($processor, 'two'), $second);
            preg_match('/"request":"([0-9a-f]+)"/', $this->logLine(new RequestIdentityProcessor(), 'three'), $other);

            $this->assertSame($first[1], $second[1]);
            $this->assertNotSame($first[1], $other[1]);
        }

        public function testExistingExtraIsKept()
        {
            $processor = new RequestIdentityProcessor();
            $record = ['message' => 'x', 'extra' => ['url' => '/checkout']];

            $result = $processor($record);

            $this->assertSame('/checkout', $result['extra']['url']);
            $this->assertArrayHasKey('request', $result['extra']);
        }

        public function testWorksOnMonologThreeRecords()
        {
            if (!class_exists(LogRecord::class)) {
                $this->markTestSkipped('Monolog 2 (Magento 2.4.7) passes array records, covered above');
            }

            $record = new LogRecord(new \DateTimeImmutable(), 'tclogger', Level::Info, 'x');
            $result = (new RequestIdentityProcessor())($record);

            $this->assertArrayHasKey('request', $result->extra);
        }

        public function testAHostThatDisablesGetmypidStillLogs()
        {
            HostRestrictions::$getmypidDisabled = true;

            $line = $this->logLine(new RequestIdentityProcessor(), 'still logged');

            $this->assertStringContainsString('still logged', $line);
            $this->assertMatchesRegularExpression('/\{"request":"[0-9a-f]{8}"\}\s*$/', $line);
            $this->assertStringNotContainsString('"pid"', $line);
        }

        public function testAHostWithoutAnEntropySourceStillGetsARequestId()
        {
            HostRestrictions::$randomBytesFails = true;
            HostRestrictions::$getmypidDisabled = true;

            $line = $this->logLine(new RequestIdentityProcessor(), 'still logged');

            $this->assertMatchesRegularExpression('/\{"request":"[0-9a-f]{8}"\}\s*$/', $line);
        }
    }
}
