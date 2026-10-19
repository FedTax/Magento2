<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\ItemIdResolver;

/**
 * TaxCloud rejects a whole cart when one item ID exceeds 50 characters, and a
 * Dynamic SKU bundle routinely does. The ID sent instead has to be the same at
 * lookup, capture and every refund — so the format is pinned here, not just
 * its length: changing it would orphan every long-SKU order placed before.
 */
class ItemIdResolverTest extends TestCase
{
    /** The SKU from the field report (90 characters). */
    private const PLANNER_SKU =
        'planner_bundle-clos-it-217000-850573-850634-217154-RR-217307-217116-RR-216294-RR-216287-RR';

    /**
     * @return array<string, array{0: string}>
     */
    public static function fittingSkuProvider(): array
    {
        return [
            'short' => ['test-product'],
            'exactly 50' => [str_repeat('x', 50)],
            'empty' => [''],
            // 30 + 10 four-byte characters = 50 UTF-16 units: still fits.
            'supplementary characters at the limit' => [str_repeat('a', 30) . str_repeat("\u{1F600}", 10)],
        ];
    }

    /**
     * @dataProvider fittingSkuProvider
     */
    #[DataProvider('fittingSkuProvider')]
    public function testASkuThatFitsIsSentUnchanged(string $sku): void
    {
        $this->assertSame($sku, ItemIdResolver::fromSku($sku));
    }

    public function testANullSkuBecomesAnEmptyId(): void
    {
        $this->assertSame('', ItemIdResolver::fromSku(null));
    }

    public function testTheFieldReportSkuIsShortenedToItsPinnedId(): void
    {
        $this->assertSame(
            'planner_bundle-clos-it-217000-850573-85~601f5fa994',
            ItemIdResolver::fromSku(self::PLANNER_SKU)
        );
    }

    public function testOneCharacterOverTheLimitIsShortenedToExactly50(): void
    {
        $sku = str_repeat('x', 51);

        $id = ItemIdResolver::fromSku($sku);

        $this->assertSame(50, strlen($id));
        $this->assertSame(str_repeat('x', 39) . '~' . substr(sha1($sku), 0, 10), $id);
    }

    public function testTheSameSkuAlwaysYieldsTheSameId(): void
    {
        $this->assertSame(ItemIdResolver::fromSku(self::PLANNER_SKU), ItemIdResolver::fromSku(self::PLANNER_SKU));
    }

    /**
     * Two shelf configurations that differ only after character 50 — exactly
     * the case plain truncation would merge into one item.
     */
    public function testConfigurationsSharingThe50CharacterPrefixStayDistinct(): void
    {
        $a = ItemIdResolver::fromSku(self::PLANNER_SKU);
        $b = ItemIdResolver::fromSku(substr(self::PLANNER_SKU, 0, -2) . 'XX');

        $this->assertSame(substr($a, 0, 40), substr($b, 0, 40), 'the readable prefix is shared');
        $this->assertNotSame($a, $b);
    }

    /**
     * v1 is a .NET service: a character outside the Basic Multilingual Plane
     * counts twice there. 31 + 10 such characters is 41 characters but 51 units.
     */
    public function testSupplementaryCharactersCountAsTwoUnits(): void
    {
        $sku = str_repeat('a', 31) . str_repeat("\u{1F600}", 10);

        $id = ItemIdResolver::fromSku($sku);

        $this->assertNotSame($sku, $id);
        $this->assertSame(
            str_repeat('a', 31) . str_repeat("\u{1F600}", 4) . '~',
            mb_substr($id, 0, 36, 'UTF-8'),
            'the prefix fills 39 units: 31 + 4 x 2'
        );
        $this->assertSame(50, $this->utf16Length($id));
    }

    /**
     * A four-byte character straddling unit 39 is left out whole: the prefix
     * stops one unit short rather than emitting half a surrogate pair.
     */
    public function testThePrefixNeverSplitsACharacter(): void
    {
        $sku = str_repeat('a', 38) . "\u{1F600}" . str_repeat('b', 20);

        $id = ItemIdResolver::fromSku($sku);

        $this->assertSame(str_repeat('a', 38) . '~', mb_substr($id, 0, 39, 'UTF-8'));
        $this->assertSame(49, $this->utf16Length($id));
        $this->assertTrue(mb_check_encoding($id, 'UTF-8'));
    }

    /**
     * Magento stores the SKU in a varchar(255) and truncates silently; the
     * lookup may see the in-memory SKU and the refund the stored one.
     */
    public function testASkuBeyondTheColumnLengthMatchesItsStoredForm(): void
    {
        $inMemory = str_repeat('s', 255) . '-extra-selection-sku';
        $stored = substr($inMemory, 0, 255);

        $this->assertSame(ItemIdResolver::fromSku($stored), ItemIdResolver::fromSku($inMemory));
    }

    private function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
