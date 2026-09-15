<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Redaction;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Redaction\CredentialFingerprint;

/**
 * A fingerprint must carry the debugging value of a credential — set, length,
 * tail, comparable hash, invisible-character flags — without the value.
 */
class CredentialFingerprintTest extends TestCase
{
    private const KEY = '8F1C2D3E-4A5B-6C7D-8E9F-0A1B2C3D4E5F';

    public function testUnsetValue()
    {
        $fp = (new CredentialFingerprint())->fingerprint(null);

        $this->assertFalse($fp['set']);
        $this->assertSame(0, $fp['length']);
        $this->assertNull($fp['last4']);
        $this->assertNull($fp['sha256_prefix']);
        $this->assertSame($fp, (new CredentialFingerprint())->fingerprint(''));
    }

    public function testSetValueNeverContainsTheValue()
    {
        $fp = (new CredentialFingerprint())->fingerprint(self::KEY);

        $this->assertTrue($fp['set']);
        $this->assertSame(36, $fp['length']);
        $this->assertSame('4E5F', $fp['last4']);
        $this->assertSame(substr(hash('sha256', self::KEY), 0, 12), $fp['sha256_prefix']);
        $this->assertFalse($fp['leading_whitespace']);
        $this->assertFalse($fp['trailing_whitespace']);
        $this->assertFalse($fp['embedded_newline']);
        $this->assertFalse($fp['non_ascii']);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($fp));
    }

    public function testSameValueSameHashPrefixDifferentValueDifferentPrefix()
    {
        $fingerprint = new CredentialFingerprint();

        $this->assertSame(
            $fingerprint->fingerprint(self::KEY)['sha256_prefix'],
            $fingerprint->fingerprint(self::KEY)['sha256_prefix']
        );
        $this->assertNotSame(
            $fingerprint->fingerprint(self::KEY)['sha256_prefix'],
            $fingerprint->fingerprint(strtolower(self::KEY))['sha256_prefix']
        );
    }

    public function testShortValuesWithholdTheTail()
    {
        $this->assertNull((new CredentialFingerprint())->fingerprint('abc1234')['last4']);
        $this->assertSame('5678', (new CredentialFingerprint())->fingerprint('abcd5678')['last4']);
    }

    public function testFlagsInvisibleCharacters()
    {
        $fingerprint = new CredentialFingerprint();

        $leading = $fingerprint->fingerprint(' ' . self::KEY);
        $this->assertTrue($leading['leading_whitespace']);
        $this->assertFalse($leading['trailing_whitespace']);

        $trailing = $fingerprint->fingerprint(self::KEY . "\n");
        $this->assertTrue($trailing['trailing_whitespace']);
        $this->assertFalse($trailing['embedded_newline'], 'a trailing newline is trailing whitespace, not embedded');

        $embedded = $fingerprint->fingerprint("8F1C2D3E\r\n4A5B");
        $this->assertTrue($embedded['embedded_newline']);

        $nonAscii = $fingerprint->fingerprint("8F1C2D3E\u{00A0}4A5B");
        $this->assertTrue($nonAscii['non_ascii']);

        $this->assertSame(
            ['leading whitespace', 'trailing whitespace'],
            $fingerprint->anomalies($fingerprint->fingerprint(' ' . self::KEY . ' '))
        );
        $this->assertSame([], $fingerprint->anomalies($fingerprint->fingerprint(self::KEY)));
    }
}
