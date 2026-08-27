## Context

See proposal.md — Why. The forms exist and work; this is about whether a person who does not already know the answers can complete them.

One constraint shapes several decisions: **nothing verifies an exemption claim**. TaxCloud accepts a certificate carrying an invented tax id, confirmed against the live API. So the form's job is to help a merchant record the right thing, never to imply the software has checked it.

## Goals / Non-Goals

**Goals:**
- Make every choice recognisable without knowing the API.
- Stop asking for anything the store cannot record.

**Non-Goals:**
- Everything in proposal.md — Non-goals, particularly Canada: both integrations gate to `US` and always have.
- Rewriting the panel onto a Magento UI component. Its AJAX load exists so a slow TaxCloud cannot make the customer page slow; that reason is unchanged.

## Decisions

### Labels live with the values, and match WooCommerce

`CertificateFormReader` already owns the enum both APIs accept, so it gains the display label beside each value rather than a second list elsewhere that can drift out of step.

The labels are WooCommerce's — "Wholesale Trade", "Industrial Production or Manufacturing", "Direct Pay Permit". Not because they are better, but because a merchant running both products, or a support agent reading a ticket, should not have to work out that two different words mean one certificate.

*Alternative considered:* generating labels by splitting the CamelCase identifier. Rejected — it produces "Non profit Organization" and "Not A Business", and the point is to look considered.

### At least one state stays required, and the reason is not what it looked like

Verified against the live API on 2026-08-20, with the control that matters:

| Request | Result |
| --- | --- |
| TX cart, no exemption | tax 8.25 |
| TX cart, certificate covering **NY only** | tax **0.00** |
| TX cart, certificate covering **no states** | tax **0.00** |

**TaxCloud does not consult `states`.** Any certificate on the account exempts
any cart that names it, whatever states it lists — so an empty list means
neither "all" nor "none" to TaxCloud; it means nothing at all. State coverage is
enforced solely by this module, exactly as ownership is.

So an empty list is genuinely ambiguous, and nothing outside the module can
resolve it. Treating it as "all states" would have this module invent a scope
the merchant never granted; treating it as "none" makes a certificate that
silently never works. Requiring the merchant to say which states apply removes
the ambiguity instead of guessing at it — and a **Select all** control makes the
all-states case one click rather than a reason to allow blank.

This also recasts the defect that started this work. The original bug parsed
`states` into an empty list, which read as a failure to apply exemptions; in
fact that parse was the only thing standing between every certificate and
universal application, and it happened to fail in the safe direction.

### States come from Magento's own region source, filtered to the US

Rather than a hand-written list. The store already knows its regions, they are already translated, and a hand-written list is a second place to be wrong.

Filtered to the US because every lookup in this module is US-gated: offering a province that could never take effect would be offering a choice that silently does nothing.

### The tax id is removed rather than hidden per transport

The alternative was showing it only on SOAP stores. Rejected: a field that appears and vanishes with a setting most admins do not know exists is more confusing than one that is simply gone — and it would leave certificates describing the same customer differently depending on which transport happened to be selected the day it was created.

Nothing of value is lost. The tax id is on the signed certificate the merchant retains, which is the only copy that matters at audit, since TaxCloud stores neither the document nor the number on v3.

*Alternative considered:* keeping it for v1 and accepting the asymmetry. Rejected for the same reason single-purchase certificates were dropped — a feature that exists on one transport only is worse than one that exists on neither.

### Guidance links rather than inline explanation

Which exemption reason applies is a tax question with a legal answer, and the form is the wrong place to answer it — a paraphrase that is subtly wrong is worse than a link. TaxCloud's own guidance is authoritative and stays current without us.

## Risks / Trade-offs

- **Removing the tax id changes what V1 stores record.** → Only for certificates created afterwards; existing ones are untouched, and the number remains on the merchant's own paperwork.
- **A multiselect of fifty states is long.** → Standard Magento behaviour, searchable in the admin, and the alternative is typing abbreviations correctly from memory.
- **Labels drift from WooCommerce if they change theirs.** → Both derive from the API's enum, which is the stable part; the labels are cosmetic and a mismatch is a cosmetic bug rather than a functional one.

## Migration Plan

None. No stored data changes, and no certificate already in TaxCloud is affected.

## Open Questions

None blocking.

One for TaxCloud rather than for us, worth asking alongside the single-purchase and `reason: "Unknown"` findings: their v3 API accepts `CA` as a country, while both their integrations gate to `US`. Whether that is intended for integrations or for direct-API customers decides whether Canada is a roadmap item or a boundary to document.
