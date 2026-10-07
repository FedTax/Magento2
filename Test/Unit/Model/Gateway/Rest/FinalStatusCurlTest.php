<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Taxcloud\Magento2\Model\Gateway\Rest\FinalStatusCurl;

/**
 * Header-callback parsing on the real core parent class: the status and
 * headers reported are the final response's, whatever interim 1xx responses
 * precede it. Lines are fed exactly as libcurl's CURLOPT_HEADERFUNCTION hands
 * them over — one raw line at a time, CRLF included, each response block
 * terminated by an empty line.
 *
 * Runs against whichever Magento the suite is bootstrapped on, so a change to
 * core Curl's parsing internals fails here on the CI matrix rather than at a
 * merchant's checkout.
 */
class FinalStatusCurlTest extends TestCase
{
    /**
     * Feed raw header lines through the client's header callback.
     *
     * @param Curl $client
     * @param string[] $lines
     * @return int[] The byte counts the callback returned, one per line
     */
    private function feed(Curl $client, array $lines): array
    {
        $method = new ReflectionMethod($client, 'parseHeaders');
        $handled = [];
        foreach ($lines as $line) {
            $handled[] = $method->invoke($client, null, $line);
        }
        return $handled;
    }

    public function testPlainResponseIsReportedUnchanged()
    {
        $client = new FinalStatusCurl();
        $this->feed($client, [
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: application/json\r\n",
            "\r\n",
        ]);

        $this->assertSame(200, $client->getStatus());
        $this->assertSame(['Content-Type' => 'application/json'], $client->getHeaders());
    }

    /**
     * The production failure: libcurl sent `Expect: 100-continue`, TaxCloud
     * answered `100 Continue`, then the real 200.
     */
    public function testContinueBeforeSuccessReportsTheSuccess()
    {
        $client = new FinalStatusCurl();
        $this->feed($client, [
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: application/json\r\n",
            "\r\n",
        ]);

        $this->assertSame(200, $client->getStatus());
        $this->assertSame(['Content-Type' => 'application/json'], $client->getHeaders());
    }

    /**
     * A real error behind an interim response must keep its own status: the
     * merchant log read "HTTP 100 Unprocessable Entity" for this sequence.
     */
    public function testContinueBeforeValidationErrorReportsTheError()
    {
        $client = new FinalStatusCurl();
        $this->feed($client, [
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 422 Unprocessable Entity\r\n",
            "Content-Type: application/problem+json\r\n",
            "\r\n",
        ]);

        $this->assertSame(422, $client->getStatus());
    }

    /**
     * Interim responses can carry headers of their own; none of them may leak
     * into the final response's.
     */
    public function testEarlyHintsHeadersAreDiscardedWithTheInterimResponse()
    {
        $client = new FinalStatusCurl();
        $this->feed($client, [
            "HTTP/2 103\r\n",
            "Link: </style.css>; rel=preload; as=style\r\n",
            "\r\n",
            "HTTP/2 200\r\n",
            "content-type: application/json\r\n",
            "\r\n",
        ]);

        $this->assertSame(200, $client->getStatus());
        $this->assertSame(['content-type' => 'application/json'], $client->getHeaders());
    }

    public function testSeveralInterimResponsesAreAllDiscarded()
    {
        $client = new FinalStatusCurl();
        $this->feed($client, [
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 103 Early Hints\r\n",
            "Link: </a.js>; rel=preload\r\n",
            "\r\n",
            "HTTP/1.1 201 Created\r\n",
            "Location: /orders/1\r\n",
            "\r\n",
        ]);

        $this->assertSame(201, $client->getStatus());
        $this->assertSame(['Location' => '/orders/1'], $client->getHeaders());
    }

    /**
     * libcurl aborts the transfer unless the callback reports every byte of
     * every line as handled — interim lines included.
     */
    public function testCallbackReportsEveryLineAsHandled()
    {
        $lines = [
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: application/json\r\n",
            "\r\n",
        ];

        $this->assertSame(array_map('strlen', $lines), $this->feed(new FinalStatusCurl(), $lines));
    }

    /**
     * Pins why the subclass exists: core Curl reports the interim status for
     * the very sequence above. If this ever starts failing, core has fixed
     * its parser and the subclass can go.
     */
    public function testCoreCurlReportsTheInterimStatus()
    {
        $core = new Curl();
        $this->feed($core, [
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: application/json\r\n",
            "\r\n",
        ]);

        $this->assertSame(100, $core->getStatus());
    }
}
