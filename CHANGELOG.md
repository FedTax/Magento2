# Changelog

All notable changes to the TaxCloud Magento 2 extension are documented here.

## 1.1.1.1

### Security

- Redact TaxCloud API credentials (`apiLoginID`, `apiKey`) from
  `var/log/taxcloud.log`. Before this release, every SOAP request payload —
  lookupTaxes, authorizedWithCapture, returnOrder, returnOrderCancellation
  and verifyAddress — was written to disk with both credentials in cleartext.
  Logging is enabled by default, so any merchant who had not explicitly
  disabled it was persisting their store's API credentials on every
  checkout, refund, cancellation and address verification, exposing them to
  anyone with file-system or log-aggregation access (sysadmins, hosting
  staff, backup snapshots, log shippers such as Datadog/Splunk, SIEM
  exports). The field names still appear in the log so operators can
  confirm the params were sent; their values are replaced with
  `***REDACTED***`.
- Scope the exempt-states cache for exemption certificates per
  `(customer, certificate)` instead of per certificate alone. The previous
  key allowed a customer who learned another customer's certificate UUID
  (the `taxcloud_cert` Magento attribute is user-editable) to inherit the
  rightful holder's cached state list at checkout. Each customer now gets
  their own cache slot, validated against TaxCloud independently.

### Documentation

- Document the 1-hour propagation window for exemption-certificate changes.
  At checkout the extension caches the list of states covered by a
  certificate for 1 hour, so revocations or covered-state edits made in the
  TaxCloud dashboard may take up to an hour to be reflected in this
  extension's checkout decisions. Operators who need a change reflected
  immediately can flush Magento's cache.
