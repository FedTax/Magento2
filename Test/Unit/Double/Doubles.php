<?php
/**
 * Taxcloud_Magento2 — unit-test doubles.
 *
 * PHPUnit 12 removed MockBuilder::addMethods(), which the suite previously used to
 * stub Magento "magic" (__call) accessors and SOAP operations that aren't declared
 * methods on their class. We keep doubles to the unavoidable minimum:
 *
 *  - SoapClientDouble: the external SOAP boundary — can't be instantiated without a
 *    WSDL and tests assert call counts, so it must be a mock.
 *  - The heavy quote/catalog models (Region, Quote\Address, Product, Quote\Item,
 *    Total): these reach into the global ObjectManager from their constructors, so
 *    a real instance can't be built in a unit test — they must be partial mocks
 *    (disableOriginalConstructor). These doubles declare the magic accessors the
 *    tests stub as real methods, so onlyMethods() accepts them on every PHPUnit
 *    version (9–12), keeping the suite backward compatible.
 *  - EAV install-script service stand-ins (InstallTaxcloudDataTest).
 *
 * Light data carriers (DataObject, Event, per-item tax details) are NOT doubled —
 * tests use real instances of those instead.
 *
 * Each double has a no-op constructor so the heavy Magento parent constructor is
 * never invoked; the method bodies are irrelevant (mocks override them) and exist
 * only so the names resolve for onlyMethods().
 */

namespace Taxcloud\Magento2\Test\Unit\Double;

class SoapClientDouble extends \SoapClient
{
    public function __construct() {}
    public function Returned($params = null) {}
    public function Ping($params = null) {}
    public function lookup($params = null) {}
    public function authorizedWithCapture($params = null) {}
    public function OrderDetails($params = null) {}
    public function GetExemptCertificates($params = null) {}
    public function verifyAddress($params = null) {}
    public function GetTICs($params = null) {}
}

/**
 * DataObject whose magic accessors (setParams/getParams/setResult/getResult) are
 * declared as real methods, for the event-handoff object in ApiTest — there the
 * object is a mock with capturing callbacks / call-count expectations, so it must
 * remain a mock (onlyMethods) rather than a plain real instance.
 */
class DataObjectDouble extends \Magento\Framework\DataObject
{
    public function setParams($params = null) { return $this; }
    public function getParams() { return null; }
    public function setResult($result = null) { return $this; }
    public function getResult() { return null; }
}

class RegionDouble extends \Magento\Directory\Model\Region
{
    public function __construct() {}
    public function getCode() { return null; }
}

class QuoteAddressDouble extends \Magento\Quote\Model\Quote\Address
{
    public function __construct() {}
    public function getShippingAmount() { return null; }
    public function getAddress() { return null; }
}

class ProductDouble extends \Magento\Catalog\Model\Product
{
    public function __construct() {}
    public function getTaxClassId() { return null; }
}

class ItemDetailsDouble extends \Magento\Tax\Model\Sales\Quote\ItemDetails
{
    public function __construct() {}
    public function getRowTotal() { return null; }
}

/**
 * Quote whose magic currency accessor is declared, for the v3 cart payload
 * builder tests (getId/getStoreId are real parent methods already).
 */
class QuoteDouble extends \Magento\Quote\Model\Quote
{
    public function __construct() {}
    public function getQuoteCurrencyCode() { return null; }
}

class QuoteItemDouble extends \Magento\Quote\Model\Quote\Item
{
    public function __construct() {}
    public function getBasePrice() { return null; }
    public function getBaseRowTotal() { return null; }
    public function getRowTotal() { return null; }
    public function getTaxCalculationItemId() { return null; }
    public function getDiscountAmount() { return null; }
    public function setTaxAmount($v = null) { return $this; }
    public function setBaseTaxAmount($v = null) { return $this; }
    public function setTaxPercent($v = null) { return $this; }
    public function setPriceInclTax($v = null) { return $this; }
    public function setBasePriceInclTax($v = null) { return $this; }
    public function setRowTotalInclTax($v = null) { return $this; }
    public function setBaseRowTotalInclTax($v = null) { return $this; }
}

class TotalDouble extends \Magento\Quote\Model\Quote\Address\Total
{
    public function __construct() {}
    public function getTaxAmount() { return null; }
    public function getBaseTaxAmount() { return null; }
    public function getExtraTaxAmount() { return null; }
    public function getBaseExtraTaxAmount() { return null; }
    public function setTaxAmount($v = null) { return $this; }
    public function setBaseTaxAmount($v = null) { return $this; }
}

/**
 * Stand-in for the per-item tax-detail objects (originally stubbed as stdClass).
 * Plain class — not coupled to any Magento type — declaring the accessors the
 * Tax collector reads/writes, so they can be stubbed/asserted with onlyMethods().
 */
class TaxDetailDouble
{
    public function getPrice() { return null; }
    public function getRowTax() { return null; }
    public function getRowTotal() { return null; }
    public function setAppliedTaxes($v = null) { return $this; }
    public function setPriceInclTax($v = null) { return $this; }
    public function setRowTax($v = null) { return $this; }
    public function setRowTotalInclTax($v = null) { return $this; }
    public function setTaxPercent($v = null) { return $this; }
}

// ─── EAV install-script collaborators (InstallTaxcloudDataTest) ──────────────

class EavSetupDouble
{
    public function addAttribute($entityType = null, $code = null, $attr = null) {}
    public function removeAttribute($entityType = null, $code = null) {}
}

class EavAttributeDouble
{
    public function addData($data = null) { return $this; }
    public function save() { return $this; }
}

class EavConfigDouble
{
    public function getAttribute($entityType = null, $code = null) { return null; }
    public function getEntityType($code = null) { return null; }
}

class CustomerEntityDouble
{
    public function getDefaultAttributeSetId() { return null; }
}

class CustomerSetupDouble
{
    public function addAttribute($entityType = null, $code = null, $attr = null) {}
    public function removeAttribute($entityType = null, $code = null) {}
    public function getEavConfig() { return null; }
}

class AttributeSetDouble
{
    public function getDefaultGroupId($setId = null) { return null; }
}
