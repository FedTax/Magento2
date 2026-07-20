<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Logger;

use Magento\Framework\Filesystem\DriverInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Logger\Handler;

/**
 * Covers the injectable log destination: the handler used to overwrite whatever
 * fileName it was constructed with, so /var/log/taxcloud.log could only be changed
 * by editing source.
 *
 * Assertions compare resolved stream URLs against an equivalently-constructed
 * handler rather than a hardcoded absolute path. How the framework normalizes a
 * leading slash is version-dependent, and this suite runs against Magento
 * 2.4.7/2.4.8/2.4.9 — what must hold on all three is that the default resolves to
 * the *same place* the hardcoded value used to.
 */
#[AllowMockObjectsWithoutExpectations]
class HandlerTest extends TestCase
{
    /**
     * The legacy hardcoded literal, kept verbatim rather than referencing the
     * constant, so a change to the constant cannot silently move the default.
     */
    private const LEGACY_FILE_NAME = '/var/log/taxcloud.log';

    private function handler($fileName = null, $filePath = null): Handler
    {
        // The stream is opened lazily on first write, so constructing the handler
        // touches no filesystem — the driver mock is never called.
        return new Handler($this->createMock(DriverInterface::class), $filePath, $fileName);
    }

    public function testDefaultResolvesWhereTheHardcodedPathUsedTo()
    {
        $this->assertSame(
            $this->handler(self::LEGACY_FILE_NAME)->getUrl(),
            $this->handler()->getUrl(),
            'existing installs must keep logging to /var/log/taxcloud.log after the DI change'
        );
    }

    public function testDefaultConstantMatchesTheLegacyPath()
    {
        $this->assertSame(self::LEGACY_FILE_NAME, Handler::DEFAULT_FILE_NAME);
    }

    /**
     * No leading slash here, so the expectation holds regardless of how a given
     * framework version sanitizes one.
     */
    public function testInjectedFileNameIsHonored()
    {
        $this->assertSame(
            BP . DIRECTORY_SEPARATOR . 'var/log/taxcloud-custom.log',
            $this->handler('var/log/taxcloud-custom.log')->getUrl(),
            'an operator di.xml override must reach the stream URL'
        );
    }

    /**
     * An empty or absent argument must fall back rather than resolve to the base
     * directory itself, which is what a falsy fileName would produce upstream.
     *
     * @dataProvider emptyFileNameProvider
     */
    #[DataProvider('emptyFileNameProvider')]
    public function testEmptyFileNameFallsBackToDefault($fileName, string $message)
    {
        $this->assertSame($this->handler()->getUrl(), $this->handler($fileName)->getUrl(), $message);
    }

    public static function emptyFileNameProvider(): array
    {
        return [
            'null' => [null, 'null fileName falls back to the module default'],
            'empty string' => ['', 'empty fileName must not resolve to the base directory'],
        ];
    }

    public function testFilePathPrefixIsHonored()
    {
        $this->assertSame(
            '/custom/root/taxcloud.log',
            $this->handler('taxcloud.log', '/custom/root/')->getUrl(),
            'filePath must still prefix the injected fileName'
        );
    }

    /**
     * The handler logs at INFO rather than the framework default of DEBUG; the
     * constructor change must not disturb that. getLevel() returns an int on
     * Monolog 2 (Magento 2.4.7) and a Level enum on Monolog 3 (2.4.9).
     */
    public function testLogLevelRemainsInfo()
    {
        $level = $this->handler()->getLevel();

        $this->assertSame(
            \Monolog\Logger::INFO,
            $level instanceof \Monolog\Level ? $level->value : $level
        );
    }

    /**
     * Acceptance criterion: the di.xml default must match the code default, so the
     * wired-up handler and the bare constructor agree on where TaxCloud logs go.
     */
    public function testDiXmlDefaultMatchesTheCodeDefault()
    {
        $diXml = simplexml_load_file(__DIR__ . '/../../../etc/di.xml');
        $this->assertNotFalse($diXml, 'etc/di.xml must be parseable');

        $argument = $diXml->xpath(
            '//type[@name="Taxcloud\Magento2\Logger\Handler"]/arguments/argument[@name="fileName"]'
        );

        $this->assertCount(1, $argument, 'etc/di.xml must bind exactly one fileName argument');
        $this->assertSame(
            Handler::DEFAULT_FILE_NAME,
            (string) $argument[0],
            'di.xml fileName must match Handler::DEFAULT_FILE_NAME'
        );
    }
}
