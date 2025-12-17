<?php
/**
 * Minimal Magento Class Definitions for Unit Testing
 * 
 * This file provides minimal class definitions needed for PHPUnit mocking
 * without requiring a full Magento installation.
 * 
 * HOW TO GENERATE THESE MOCKS:
 * (Asking AI to generate the mocks for the classes that are not found works pretty well)
 * 
 * 1. When you get "Class not found" errors during unit testing:
 *    - Add the missing class to the appropriate namespace below
 *    - Include only the methods that are actually called in your tests
 *    - Use empty method bodies: public function methodName() {}
 * 
 * 2. For interfaces, just declare them without implementation
 * 3. For classes, add minimal method signatures that your tests need
 * 4. Keep it minimal - only add what's necessary for tests to run
 * 
 * Example:
 *    class MyClass {
 *        public function getValue() {}
 *        public function setValue($value) { return $this; }
 *    }
 */

namespace Magento\Framework\App\Config {
    // Prevent the real registration.php from being loaded
    if (!defined('MAGENTO_MOCKS_LOADED')) {
        define('MAGENTO_MOCKS_LOADED', true);
    }
    interface ScopeConfigInterface
    {
        public function getValue($path, $scopeType = null, $scopeCode = null);
    }
}

namespace Magento\Store\Model {
    class ScopeInterface
    {
        const SCOPE_STORE = 'store';
        const SCOPE_WEBSITE = 'website';
        const SCOPE_GROUP = 'group';
    }
}

// Mock Magento Catalog Classes
namespace Magento\Catalog\Model {
    class ProductFactory
    {
        public function create() { return new Product(); }
    }

    class Product
    {
        public function getId() { return null; }
        public function setId($id) { return $this; }
        public function load($id) { return $this; }
        public function getCustomAttribute($code) { return null; }
        public function setCustomAttribute($code, $value) { return $this; }
    }
}

// Mock Magento Sales Classes
namespace Magento\Sales\Model\Order {
    class Item
    {
        public function getSku() { return null; }
        public function setSku($sku) { return $this; }
        public function getProduct() { return null; }
        public function setProduct($product) { return $this; }
        public function getQuoteItemId() { return null; }
        public function getId() { return null; }
    }

    class Order
    {
        public function getIncrementId() { return null; }
        public function setIncrementId($id) { return $this; }
        public function getAllItems() { return []; }
        public function setItems($items) { return $this; }
    }

    class Creditmemo
    {
        public function getOrder() { return null; }
        public function setOrder($order) { return $this; }
        public function getAllItems() { return []; }
        public function setItems($items) { return $this; }
        public function getShippingAmount() { return 0; }
        public function setShippingAmount($amount) { return $this; }
    }
}

namespace Magento\Sales\Model\Order\Creditmemo {
    class Item
    {
        public function getOrderItem() { return null; }
        public function setOrderItem($item) { return $this; }
        public function getPrice() { return 0; }
        public function setPrice($price) { return $this; }
        public function getDiscountAmount() { return 0; }
        public function setDiscountAmount($amount) { return $this; }
        public function getQty() { return 0; }
        public function setQty($qty) { return $this; }
    }
}

// Mock Magento Framework API Classes
namespace Magento\Framework\Api {
    class AttributeValue
    {
        public function getValue() { return null; }
        public function setValue($value) { return $this; }
    }
}

// Mock Magento Framework Component Registrar (prevents registration.php errors)
namespace Magento\Framework\Component {
    class ComponentRegistrar
    {
        const MODULE = 'module';
        const LIBRARY = 'library';
        const THEME = 'theme';
        const LANGUAGE = 'language';
        
        public static function register($type, $componentName, $path) { /* do nothing */ }
    }
}

// Mock Magento Framework Classes
namespace Magento\Framework {
    class DataObject
    {
        public function getData($key = null) { return null; }
        public function setData($key, $value = null) { return $this; }
    }

    class DataObjectFactory
    {
        public function create(array $data = []) { return new DataObject(); }
    }
}

namespace Magento\Framework\App {
    class ObjectManager
    {
        public static function getInstance() { return new self(); }
        public function get($type) { return null; }
    }
}

// Mock Magento Framework Exception Classes
namespace Magento\Framework\Exception {
    class LocalizedException extends \Exception { }
    class NoSuchEntityException extends \Exception { }
}

// Mock Magento Framework Event Classes
namespace Magento\Framework\Event {
    interface ManagerInterface
    {
        public function dispatch($eventName, array $data = []);
    }
    
    class Manager implements ManagerInterface
    {
        public function dispatch($eventName, array $data = []) { /* do nothing */ }
    }
}

// Mock Magento Framework Cache Classes
namespace Magento\Framework\App {
    interface CacheInterface
    {
        public function load($identifier);
        public function save($data, $identifier, $tags = [], $lifeTime = null);
        public function remove($identifier);
        public function clean($tags = []);
    }
    
    class Cache implements CacheInterface
    {
        public function load($identifier) { return false; }
        public function save($data, $identifier, $tags = [], $lifeTime = null) { return true; }
        public function remove($identifier) { return true; }
        public function clean($tags = []) { return true; }
    }
}

// Mock Magento Framework Serializer Classes
namespace Magento\Framework\Serialize {
    interface SerializerInterface
    {
        public function serialize($data);
        public function unserialize($string);
    }
}

namespace Magento\Framework\Serialize\Serializer {
    class Json implements \Magento\Framework\Serialize\SerializerInterface
    {
        public function serialize($data) { return json_encode($data); }
        public function unserialize($string) { return json_decode($string, true); }
    }
}

// Mock Magento Directory Classes
namespace Magento\Directory\Model {
    class RegionFactory
    {
        public function create() { return new Region(); }
    }
    
    class Region
    {
        public function getId() { return null; }
        public function setId($id) { return $this; }
        public function getCode() { return null; }
        public function setCode($code) { return $this; }
        public function getName() { return null; }
        public function setName($name) { return $this; }
        public function load($id) { return $this; }
    }
}

// Mock Magento Framework WebAPI Classes
namespace Magento\Framework\Webapi\Soap {
    class ClientFactory
    {
        public function create($wsdl, $options = []) { return new Client($wsdl, $options); }
    }
    
    class Client
    {
        public function __construct($wsdl, $options = []) { /* do nothing */ }
        public function __call($method, $args) { return new \stdClass(); }
    }
}

// Mock SOAP Classes
namespace {
    if (!class_exists('SoapClient')) {
        class SoapClient
        {
            public function __construct($wsdl, $options = []) { /* do nothing */ }
            public function __call($method, $args) { return new \stdClass(); }
        }
    }

    if (!class_exists('SoapFault')) {
        class SoapFault extends \Exception
        {
            public function __construct($faultcode, $faultstring, $faultactor = null, $detail = null, $faultname = null, $headerfault = null)
            {
                parent::__construct($faultstring, 0);
            }
        }
    }
}

// Mock TaxCloud Logger
namespace Taxcloud\Magento2\Logger {
    class Logger
    {
        public function info($message) { /* do nothing */ }
        public function error($message) { /* do nothing */ }
        public function debug($message) { /* do nothing */ }
    }
}