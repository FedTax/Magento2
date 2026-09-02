<?php
/**
 * Taxcloud_Magento2 — collector-diagnostics doubles.
 *
 * The tax collector probe classifies collectors and plugins by NAMESPACE:
 * Taxcloud\Magento2\* is ours, Magento\* is core, anything else is a
 * third-party module that may have displaced us. PHPUnit mock class names
 * cannot express that — setMockClassName() produces names like
 * MockObject_Subtotal_1a2b3c, which read as third-party and made a mocked core
 * collector look like a competitor.
 *
 * So the diagnostics tests use these: real, empty classes declared in the
 * namespaces they are meant to imitate. Declared once here rather than per
 * test, because a mock class name can only be claimed once per process.
 */

namespace Competitor\Tax\Model {

    class Total
    {
    }

    class Plugin
    {
    }
}

namespace Loyalty\Model {

    class Total
    {
    }
}

namespace Weee\Model {

    class Total
    {
    }
}

namespace Magento\FakeCore\Model {

    /**
     * Stands in for a core collector ordered after tax (grand total and
     * friends), which must never be reported as a competitor.
     */
    class GrandTotal
    {
    }
}
