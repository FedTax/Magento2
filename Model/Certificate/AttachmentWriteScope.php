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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Certificate;

/**
 * Marks a customer save as the module's own write of the attached certificate.
 *
 * The attachment is a customer attribute, so any path that saves a customer —
 * the REST and GraphQL customer APIs, the admin customer form, an import — can
 * carry a new value for it. The repository guard refuses those; this scope is
 * how it recognises the one writer that has already checked who is asking and
 * that the certificate is theirs: CertificateAttachment.
 *
 * A shared instance with no dependencies, so the writer and the guard are
 * always looking at the same flag.
 */
class AttachmentWriteScope
{
    /**
     * @var int Nesting depth, so a save inside a save does not close the scope early
     */
    private $depth = 0;

    /**
     * Run a save with the scope open.
     *
     * @param callable $write
     * @return mixed Whatever the write returns
     */
    public function run(callable $write)
    {
        $this->depth++;

        try {
            return $write();
        } finally {
            $this->depth--;
        }
    }

    /**
     * Whether a save currently under way is CertificateAttachment's.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->depth > 0;
    }
}
