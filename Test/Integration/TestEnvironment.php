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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration;

use Magento\Framework\ObjectManagerInterface;

/**
 * Static holder for the ObjectManager produced by the integration test
 * bootstrap. Test cases call {@see TestEnvironment::getObjectManager()} to
 * resolve services off the same booted Magento, without going through
 * Magento's dev/tests/integration/ framework.
 */
final class TestEnvironment
{
    private static ?ObjectManagerInterface $objectManager = null;

    public static function setObjectManager(ObjectManagerInterface $objectManager): void
    {
        self::$objectManager = $objectManager;
    }

    public static function getObjectManager(): ObjectManagerInterface
    {
        if (self::$objectManager === null) {
            throw new \LogicException(
                'TestEnvironment is uninitialised. The integration bootstrap '
                . '(Test/Integration/bootstrap.php) should have set the ObjectManager '
                . 'before any test ran. Check phpunit.integration.xml.dist:bootstrap.'
            );
        }
        return self::$objectManager;
    }

    /**
     * Convenience: instantiate / fetch a class by FQN. Equivalent to
     * TestEnvironment::getObjectManager()->get($class).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function get(string $class)
    {
        return self::getObjectManager()->get($class);
    }
}
