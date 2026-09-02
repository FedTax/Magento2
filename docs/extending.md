# Extending the extension

Developer-facing. If you are not writing Magento modules, this page is one to
hand over — the rest of the site is written for you, this one is not.

## Events

Every TaxCloud API call emits a **before** and an **after** event. Before events
can modify the parameters sent; after events can modify the response. This is
how you adjust behaviour without touching the extension.

Typical reasons: splitting a shipping charge across taxable and exempt items,
fetching exemption data from another system, or overriding the origin address
for multi-warehouse fulfilment.

### V1 SOAP events

| Event | Fires | Data |
|---|---|---|
| `taxcloud_lookup_before` / `_after` | Tax lookup | `$params`/`$result`, `$customer`, `$address`, `$quote`, `$itemsByType`, `$shippingAssignment` |
| `taxcloud_verify_address_before` / `_after` | Address verification | `$params` / `$result` |
| `taxcloud_authorized_with_capture_before` / `_after` | Order capture | `$params`/`$result`, `$order` |
| `taxcloud_returned_before` / `_after` | Credit memo, or cancelled unpaid order | `$params`/`$result`, `$order`, `$items`, `$creditmemo` |

For a cancellation, `$creditmemo` is null and `$items` are the order items.

### V3 REST events

Stores on V3 REST fire a parallel set, carrying v3 JSON shapes:

| Event | Fires instead of | Payload |
|---|---|---|
| `taxcloud_rest_lookup_before` / `_after` | `taxcloud_lookup_*` | `POST /carts` payload / cart response with per-line `tax {amount, rate}` |
| `taxcloud_rest_capture_before` / `_after` | `taxcloud_authorized_with_capture_*` | `POST /orders` payload / order response |
| `taxcloud_rest_refund_before` / `_after` | `taxcloud_returned_*` | Refund payload (`items[]` of `itemId` + `quantity`; empty means full refund) / refund response |
| `taxcloud_rest_verify_address_before` / `_after` | `taxcloud_verify_address_*` | Verify-address payload / verified address in the module's `Address1`…`Zip4` shape |

Context objects are the same as on the SOAP events. V3 payloads never contain
credentials — authentication is in HTTP headers.

!!! warning "Observers are API-specific"
    An observer on the SOAP events stops applying to a store switched to V3
    REST. Subscribe to the REST events and handle the v3 shapes. See
    [Choosing your API](choosing-your-api.md).

## Changing the log file location

Logging goes to `var/log/taxcloud.log`. The filename is a constructor argument
on `Taxcloud\Magento2\Logger\Handler`, so it can be redirected from your own
module's `di.xml`:

```xml
<type name="Taxcloud\Magento2\Logger\Handler">
    <arguments>
        <argument name="fileName" xsi:type="string">/var/log/taxcloud-custom.log</argument>
    </arguments>
</type>
```

The path is relative to the Magento base directory, and the directory is created
if it does not exist. Run `bin/magento setup:di:compile` (or clear `generated/`)
afterwards. Bind it in an area-specific `di.xml` to split logs per environment.

Credential redaction happens before records reach the handler, so `apiLoginID`
and `apiKey` stay redacted wherever you send them. The rotation caveat applies
to any destination — see [Reading the log](logs.md).

## Advanced settings with no admin field

Three values have no admin field and are set with `bin/magento config:set`:

| Path | Default | Purpose |
|---|---|---|
| `tax/taxcloud_settings/rest_endpoint` | `https://api.v3.taxcloud.com` | The V3 REST base URL |
| `tax/taxcloud_settings/rest_auth_endpoint` | TaxCloud's credential-exchange host | Where V1 credentials are exchanged for V3 access |

Only change these if TaxCloud support has asked you to. Both are store-scoped,
so a staging store view can point elsewhere while production does not.

The full path list is in the [Settings reference
appendix](settings.md#appendix-configuration-paths).

## Project documentation

Source, issues and pull requests: [FedTax/Magento2](https://github.com/FedTax/Magento2).

- [Integration tests](INTEGRATION_TESTS.md)
- [E2E tests](E2E_TESTS.md)
- [Writing documentation](writing-documentation.md)
