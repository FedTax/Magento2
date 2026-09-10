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
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;

/**
 * RestResponse contract: success/retryable/not-found classification and
 * ErrorModel folding into a single log line.
 */
class RestResponseTest extends TestCase
{
    public function testSuccessStatusesAreSuccessAndNotRetryable()
    {
        foreach ([200, 201, 204] as $status) {
            $response = new RestResponse($status, '{}');
            $this->assertTrue($response->isSuccess(), "status $status");
            $this->assertFalse($response->isRetryable(), "status $status");
        }
    }

    public function testRateLimitAndServerErrorsAreRetryable()
    {
        foreach ([429, 500, 502, 503] as $status) {
            $response = new RestResponse($status, '');
            $this->assertFalse($response->isSuccess(), "status $status");
            $this->assertTrue($response->isRetryable(), "status $status");
        }
    }

    public function testClientErrorsAreTerminal()
    {
        foreach ([400, 401, 403, 404, 422] as $status) {
            $response = new RestResponse($status, '');
            $this->assertFalse($response->isSuccess(), "status $status");
            $this->assertFalse($response->isRetryable(), "status $status");
        }
    }

    public function testStatusClassificationHelpers()
    {
        $this->assertTrue((new RestResponse(404))->isNotFound());
        $this->assertFalse((new RestResponse(400))->isNotFound());
        $this->assertTrue((new RestResponse(401))->isUnauthorized());
        $this->assertFalse((new RestResponse(403))->isUnauthorized());
    }

    public function testBodyIsDecodedWhenJsonObject()
    {
        $response = new RestResponse(200, '{"items":[{"cartId":"7"}]}');
        $this->assertSame([['cartId' => '7']], $response->getBody()['items']);
        $this->assertSame('{"items":[{"cartId":"7"}]}', $response->getRawBody());
    }

    public function testBodyIsNullForEmptyOrNonJsonPayloads()
    {
        $this->assertNull((new RestResponse(200, ''))->getBody());
        $this->assertNull((new RestResponse(502, '<html>Bad Gateway</html>'))->getBody());
        $this->assertNull((new RestResponse(200, '"just a string"'))->getBody());
    }

    public function testErrorDetailFoldsFullErrorModel()
    {
        $body = json_encode([
            'status' => 422,
            'title' => 'Unprocessable Entity',
            'detail' => 'validation failed',
            'errors' => [
                ['location' => 'body.lineItems[0].price', 'message' => 'must be >= 0', 'value' => -1],
                ['message' => 'orderId already exists'],
            ],
        ]);

        $this->assertSame(
            'HTTP 422 Unprocessable Entity - validation failed'
            . ' - (body.lineItems[0].price: must be >= 0; orderId already exists)',
            (new RestResponse(422, $body))->errorDetail()
        );
    }

    public function testErrorDetailFallsBackToHttpStatus()
    {
        $this->assertSame('HTTP 503', (new RestResponse(503, ''))->errorDetail());
        $this->assertSame('HTTP 500', (new RestResponse(500, 'not json'))->errorDetail());
        // ErrorModel with non-string / malformed members degrades gracefully.
        $this->assertSame(
            'HTTP 400',
            (new RestResponse(400, '{"title": 5, "errors": ["oops"]}'))->errorDetail()
        );
    }
}
