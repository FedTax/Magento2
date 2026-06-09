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
