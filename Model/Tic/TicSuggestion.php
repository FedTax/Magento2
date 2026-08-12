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

namespace Taxcloud\Magento2\Model\Tic;

/**
 * One TIC offered to the admin, normalized across both API generations.
 *
 * The two sources describe a TIC very differently: v1 GetTICs returns an id and
 * a short description and nothing else, while v3 tic search adds natural-language
 * labelling, documentation and a relevance score. Rather than teach the UI two
 * shapes, both backends map onto this one and the extra fields stay null when
 * the source has nothing to put there — the template renders what is present.
 */
class TicSuggestion
{
    /**
     * @var string
     */
    private $code;

    /**
     * @var string
     */
    private $label;

    /**
     * Longer explanation, when the source provides one (v3 only).
     *
     * @var string|null
     */
    private $detail;

    /**
     * Relevance in 0..1, when the source ranks (v3 only).
     *
     * @var float|null
     */
    private $score;

    /**
     * @param string $code TIC code, as stored in the field
     * @param string $label Short description
     * @param string|null $detail Longer explanation, null when unavailable
     * @param float|null $score Relevance 0..1, null when the source does not rank
     */
    public function __construct(string $code, string $label, ?string $detail = null, ?float $score = null)
    {
        $this->code = $code;
        $this->label = $label;
        $this->detail = $detail;
        $this->score = $score;
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return string|null
     */
    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * @return float|null
     */
    public function getScore(): ?float
    {
        return $this->score;
    }

    /**
     * Shape handed to the admin UI.
     *
     * Every key is always present, null when the source had nothing to put
     * there. Omitting them would be a smaller payload but a trap: inside a
     * Knockout foreach a bare `detail` resolves against $data only when the
     * property exists, and otherwise escapes to global scope and throws. Always
     * emitting the key makes both `detail` and `$data.detail` safe.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'detail' => $this->detail !== '' ? $this->detail : null,
            'score' => $this->score,
        ];
    }
}
