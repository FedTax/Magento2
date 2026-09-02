# Overview

The TaxCloud extension connects your Magento store to
[TaxCloud](https://taxcloud.com/), so that US sales tax is calculated correctly
at checkout and every sale is recorded where it can be filed.

## What it does

**Calculates tax at checkout.** When a shopper reaches checkout, the extension
sends the cart — what is in it, where it is going, where it ships from — to
TaxCloud, and TaxCloud returns the tax due. That covers more than 13,000 US
jurisdictions, including the ones where the rate changes street by street.

**Records the sale.** Once the order is complete, it is reported to TaxCloud as
a transaction. That record is what a return is built from, and what backs you up
if a state asks questions later.

**Keeps the record straight afterwards.** Refunds and cancellations are sent
back to TaxCloud too, so what it holds matches what actually happened in your
store.

**Applies exemption certificates.** If you sell to resellers, schools or
government buyers, a certificate held against a customer is applied
automatically at checkout when it covers the destination state. This is off
until you turn it on.

## What stays your responsibility

The extension automates calculation and reporting. It does not make the
decisions that come before them:

- **Where you collect tax.** You choose your states in TaxCloud, based on where
  you have nexus. The extension charges tax where TaxCloud says to charge it.
- **What you sell.** Tax depends on the kind of product. You tell TaxCloud what
  each product is by assigning it a
  [TIC](tics.md) — a Taxability Information Code. Get that wrong and the tax is
  wrong, however good the calculation.
- **Your origin address.** Many states tax based on where the order ships from.
  You enter that in Magento, and it must be right, down to the ZIP+4.
- **Exemption claims.** TaxCloud does not verify them. If a customer is exempt,
  you are the one who must hold a valid certificate on file.
- **Filing.** TaxCloud can file and remit for you, but that is a service you
  arrange with them — it is not something the extension switches on.

## What it does not cover

- **US sales tax only.** Orders shipping outside the United States are left
  alone, and Magento handles them however it did before.
- **Nothing is changed in your storefront's look.** Tax appears in the totals
  the way Magento already displays it.

## How the pieces fit together

| Where | What lives there |
|---|---|
| Your TaxCloud account | The states you collect in, your business locations, your exemption certificates, your transactions and returns |
| Magento — TaxCloud Settings | Credentials, and how the extension behaves: caching, logging, when orders are reported |
| Magento — products and categories | The TIC for each thing you sell |
| Magento — customers | Which customers are exempt, and under which certificate |

## Where to go next

- Never set this up before → [Before you begin](before-you-begin.md)
- Already have a TaxCloud account → [Installing the extension](installing.md)
- Installed, want it working → [Quick start](quick-start.md)
- Want the detail on one setting → [Settings reference](settings.md)
