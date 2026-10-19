## Context

`RequestBuilder` builds every v1 cart the extension sends: the lookup, the credit-memo return, and the full-order cart used for full returns and exempt re-creates. `RestRequestBuilder` reshapes those carts for v3 and builds the v3 order payload itself. `RefundDistributor` builds the adjustment-only distribution. Each of them writes `$item->getSku()` straight into `ItemID` / `itemId`.

Magento's SKU for a line is not the catalog SKU:

- A bundle with `sku_type = 0` (Dynamic SKU) gets its SKU from `Bundle\Model\Product\Type::getSku()`, which appends each selected selection's SKU.
- Any product with custom options gets its SKU from `AbstractType::getOptionSku()`, which appends each chosen option's SKU.

`Quote\Item::setProduct()` stores that composed SKU on the quote item, and the quote-to-order conversion copies it to `sales_order_item.sku` (varchar 255). Credit-memo items read it through `getOrderItem()`.

TaxCloud caps the item ID at 50 characters on both APIs and rejects the entire request on violation. This was verified live on 2026-10-19 against the sandbox account, with the merchant's 90-character SKU as one line next to a short one:

- v1 SOAP: `ResponseType Error`, "ItemID execeeds 50 characters (…) for Cart Item 1".
- v3 REST: `POST /carts` → 422, `body.items[0].lineItems[1].itemId: expected length <= 50`.

On both transports, a 50-character ID passes, as do IDs built with `~`, `#` or `-` as the separator.

## Goals / Non-Goals

**Goals:**
- No line is ever sent with an item ID over 50 characters.
- Every payload for one order names a given line by the same ID: lookup, v3 order, credit-memo return, adjustment-only distribution, full return / exempt re-create.
- Lines whose SKUs share a long common prefix stay distinct.
- Every SKU that fits today is sent exactly as today, so existing orders keep matching their TaxCloud records.

**Non-Goals:**
- Changing the limit, changing Magento SKUs, or storing a SKU↔ID mapping.
- Repairing past orders (none reached TaxCloud with a long ID).

## Decisions

### 1. Derive the ID from the SKU; do not store it

`ItemIdResolver::fromSku($sku)` is a static pure function. Every builder already has the line's SKU, and Magento carries the same string from quote item to order item to credit-memo item. A pure function of it is therefore identical at every step without persistence.

The alternative was a stored short ID: new columns on `quote_item` and `sales_order_item`, populated before the first lookup and carried by the `sales_convert_quote_item` fieldset. That adds schema, a conversion path Magento silently drops custom columns from unless mapped, and a gap for every order created before the release. It also buys nothing the derivation does not.

A static class matches `CompositeItemResolver` and needs no constructor changes in the five consumers. Those include `RefundDistributor`, whose constructor is injected by several tests.

### 2. Format: 39-character prefix, `~`, 10 hex characters of SHA-1

- **Up to 50 characters:** the SKU is returned unchanged, so every existing ID is preserved.
- **Over 50 characters:** the first 39 characters of the SKU, `~`, then `substr(sha1(sku), 0, 10)`. That is 39 + 1 + 10 = 50, exactly the limit.
- The prefix keeps the ID recognizable in TaxCloud's reports.
- `~` marks the ID as shortened. It is rare in SKUs, and both APIs accept it.
- The hash covers the **whole** SKU, so configurations that differ only after character 39 get different IDs. Plain truncation is what the merchant rightly rejected.
- 10 hex characters are 40 bits. For two distinct long SKUs in one cart, the chance of a shared ID is about 1 in 10¹², and a collision needs the same 39-character prefix as well.
- SHA-1 is used as a fingerprint, not for security. PHP has it built in on every supported version.

### 3. Measure length in UTF-16 code units

v1 is a .NET service, where string length counts UTF-16 code units. v3 counts characters. A character outside the Basic Multilingual Plane (an emoji, say) is one code point but two UTF-16 units, and the unit count is never lower than the code point count. So the resolver measures in UTF-16 units, and an ID that fits that measure fits both APIs. The prefix is built character by character and never splits a multibyte character.

### 4. Hash at most the first 255 characters

`quote_item.sku` and `sales_order_item.sku` are varchar 255, and Magento runs MySQL without strict mode, so a longer SKU is truncated on save. The lookup can run on a quote item still in memory, holding the untruncated SKU, while the refund reads the saved order item. Hashing only the first 255 characters gives both the same ID. The prefix is unaffected because 39 < 255.

### 5. The distribution walks order basis items

`RefundDistributor::buildRemainingItems()` iterates `getAllItems()` and skips children, which keeps a dynamic-price bundle's wrapper. It changes to iterate top-level items (no parent object and no `parent_item_id`), then expand each through `CompositeItemResolver::orderBasisItems()`. This is the same walk `buildCartItemsFromOrder()` and `RestRequestBuilder::buildOrderPayload()` already use. Order-side quantities are absolute, so the selections' `qty_ordered` and `qty_refunded` are used as stored.

## Risks / Trade-offs

- **The ID shown in TaxCloud for a long SKU is not the Magento SKU.** This is mitigated by the readable prefix, and the docs say what the suffix is.
- **A merchant whose SKU is exactly 50 characters today keeps it unchanged, while one character more switches to the shortened form.** This is intended: only IDs that would be rejected change.
- **An order placed before the upgrade with a long SKU had its lookup rejected.** With fallback on it was taxed at Magento rates; otherwise it carried no tax. Its TaxCloud capture (SOAP `AuthorizedWithCapture` names the cart, not the lines) would have failed against a cart that was never created. Upgrading does not change such orders. They are handled like any other unfiled order.

## Migration Plan

Drop-in: upgrade to 1.6.1, `setup:upgrade`, flush cache. There is no data migration, and IDs that fit are unchanged.
