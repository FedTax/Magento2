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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Gateway\Rest;

use Magento\Framework\HTTP\Client\Curl;

/**
 * Magento's curl client, reporting the status and headers of the FINAL response.
 *
 * Core Curl::parseHeaders() takes the status from the first header line libcurl
 * hands it. When a response is preceded by an interim 1xx — `100 Continue` after
 * an `Expect: 100-continue` request, `103 Early Hints` from a CDN — that first
 * line is the interim one: getStatus() reports 100 while getBody() holds the
 * final response's body, and a successful call reads as a failure. Older
 * libcurl (7.61 on RHEL/AlmaLinux 8) adds the Expect header on its own to
 * HTTP/1.1 POST bodies over 1 KiB, so this turned every large v3 cart into a
 * silent Magento-rate fallback.
 *
 * A status line arriving after header lines were already seen starts a new
 * response: the interim block's count and headers are dropped and the parent
 * records the new status exactly as it records the first.
 */
class FinalStatusCurl extends Curl
{
    /**
     * libcurl header callback.
     *
     * @param resource|\CurlHandle $ch
     * @param string|null $data One raw header line, including its CRLF
     * @return int Number of bytes handled, as libcurl requires
     */
    protected function parseHeaders($ch, $data)
    {
        if ($this->_headerCount > 0 && $data !== null && strncasecmp($data, 'HTTP/', 5) === 0) {
            $this->_headerCount = 0;
            $this->_responseHeaders = [];
        }

        return parent::parseHeaders($ch, $data);
    }
}
