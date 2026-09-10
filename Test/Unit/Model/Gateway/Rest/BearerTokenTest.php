<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;

/**
 * The token value object: validity math and the guarantee that the raw token
 * never appears in string/dump output.
 */
class BearerTokenTest extends TestCase
{
    public function testValidityHonorsMargin()
    {
        $token = new BearerToken('jwt-value', 1000);

        $this->assertTrue($token->isValidAt(699, 300));
        $this->assertFalse($token->isValidAt(700, 300), 'validTo minus margin is the cutoff');
        $this->assertTrue($token->isValidAt(999));
        $this->assertFalse($token->isValidAt(1000), 'expired exactly at validTo');
    }

    public function testAccessors()
    {
        $token = new BearerToken('jwt-value', 1234);

        $this->assertSame('jwt-value', $token->getToken());
        $this->assertSame(1234, $token->getValidTo());
    }

    public function testStringAndDebugOutputNeverContainTheRawToken()
    {
        $token = new BearerToken('secret-jwt-value', 1234);

        $this->assertStringNotContainsString('secret-jwt-value', (string) $token);

        ob_start();
        var_dump($token);
        $dump = ob_get_clean();
        $this->assertStringNotContainsString('secret-jwt-value', $dump);

        $this->assertStringNotContainsString('secret-jwt-value', print_r($token, true));
    }
}
