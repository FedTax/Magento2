# Sending diagnostics to support

When something about TaxCloud looks wrong, the fastest way to get it fixed is to
send TaxCloud support a **diagnostics file**. It is one ZIP file, created from
your admin in a few seconds, that holds everything support needs to see how
TaxCloud is set up on your store and what it has been doing — without anyone
needing access to your store or your server.

Without it, support has to ask you for your versions, your settings and your
logs one question at a time. With it, they can usually start on the answer
straight away.

## Where the buttons are

There are two, depending on what the problem is about.

**A problem with your whole store** — no tax anywhere, every order failing,
settings that seem to have no effect:

*Stores → Configuration → Sales → Tax →* **TaxCloud Settings** → **Diagnostics**
→ **Download Diagnostics**

The file describes the store view selected in the **Store View** switcher at the
top left of the page. Leave it on **Default Config** to include every store; pick
a website or store view to describe just that one.

**A problem with one order** — "the tax on order 100000123 is wrong":

*Sales → Orders →* open the order → **TaxCloud Diagnostics** in the button bar
at the top

This file describes that order and the store it was placed in, and includes only
the TaxCloud log entries recorded for that order. Start here whenever the
problem is about a specific order.

!!! note "Not seeing the buttons?"
    They appear only for admin users whose role includes **TaxCloud Diagnostics
    Export** (*System → Permissions → User Roles →* the role → **Role
    Resources**, under *Stores → Settings*). Because the file can contain
    customer order data, this permission is separate from permission to edit
    tax settings. Ask whoever manages your admin users to grant it.

## Creating the file

Clicking either button opens a short dialog before anything is created:

1. **Mask customer details** — unticked by default. See
   [Customer details](#customer-details) below.
2. **Log window** — how much of the log to include. The recommended setting
   takes the last 10 MB or the last 7 days of log, whichever is smaller. Choose
   a larger window if the problem started more than a week ago.
3. **Test the connection** — ticked by default. See
   [The connection test](#the-connection-test) below.

Click **Generate and download**. The file downloads to your computer with a name
like `taxcloud-diagnostics-default-20260914-101500.zip`. Nothing is sent
anywhere else.

## What the file contains

| Part | What it tells support |
|---|---|
| A summary | A one-page report of everything below, with anything unusual flagged — the first thing support reads. It lists every distinct warning and error in the included logs with how often and how recently it happened, when TaxCloud last calculated tax, captured and refunded, and whether a `bin/magento setup:upgrade` is still pending |
| Your TaxCloud settings | Every TaxCloud setting at every level (default, website, store view): where each value was set, which values are inherited, and which are locked by your server's configuration so they cannot be changed in the admin |
| Magento's own tax settings | Magento tax settings, tax rules, tax rates and tax classes, which can interfere with TaxCloud |
| Installed extensions | Every extension on your store, with versions — other extensions are a common cause of tax problems |
| Server details | Magento, PHP and extension versions, server settings, cron and indexer status, time zones |
| Tax calculation check | Whether TaxCloud is the extension actually calculating tax on each store — see [Another extension is calculating tax](extension-conflicts.md) |
| Connection test results | The outcome of the live test, if you left it on |
| The TaxCloud log | Recent entries from the [TaxCloud log](logs.md), plus TaxCloud-related entries from Magento's own error logs |
| The order | For the order button only: the order's totals, items and the TIC used for each, addresses, invoices, credit memos and shipments |

If part of the information cannot be collected — a database table is missing, a
log file cannot be read — the file is still created, and the summary says
exactly what is missing and why.

!!! warning "Turn logging on before you reproduce a problem"
    If **Logging** is set to `Disable`, the file cannot show what TaxCloud did,
    and the summary says so at the top. Set it to `Enable - Basic` (or
    `Enable - Advanced` if support asks), reproduce the problem, then create the
    file. See [Reading the log](logs.md).

## Your credentials are never included

Your API ID, API Key and V3 API Key are never written to the file, whichever
options you choose. There is no setting that includes them. They are removed
from settings and from every log entry, including old log entries.

In their place the file records a harmless description of each one: whether it
is set, how long it is, its last four characters, and whether it contains stray
spaces or line breaks. That is enough for support to spot the most common
credential problem — a key pasted with an invisible space — without ever seeing
the key.

Your **Connection ID** is included, because support needs it to find your
connection in TaxCloud and it cannot be used to reach your account on its own.
If your Connection ID happens to be the same value as your V1 API Key (common on
accounts moved from V1 to V3), it is described the same way as a credential
instead.

## Customer details

By default the file includes customer details as they appear in your orders and
logs — names, street addresses, email addresses and phone numbers — so support
can reproduce the exact order that went wrong.

Tick **Mask customer details** to replace those with `***MASKED***` throughout
the file, including inside log entries. City, state, ZIP code and TIC are always
kept: tax depends on them, and a file without them could not answer the
question.

The summary at the top of the file states which choice was made, so support
knows whether a missing detail was never there or was masked.

Every time a diagnostics file is created, Magento records who created it, when,
for which store or order, and whether customer details were masked. It is
written to Magento's system log on every edition. On Adobe Commerce it also
appears in *System → Action Logs →* **Report**, as long as **TaxCloud
Diagnostics** is ticked under *Stores → Configuration → Advanced → Admin →*
**Admin Actions Logging**.

## The connection test

With **Test the connection** ticked, your store makes two calls to TaxCloud while
the file is being created: a tax calculation for a single $10 item, and an
address check. Both use TaxCloud's own business address, never a customer's, and
nothing is recorded as a sale.

Because the calls come from your server, the result shows whether your server
can reach TaxCloud at all, whether a firewall or proxy is getting in the way,
and whether your credentials work — often the whole answer. If TaxCloud cannot
be reached, the file is still created, and the failure is recorded in it.

Untick it only if support has asked you to, or your server is not allowed to
make outside connections.

## Attaching it to a support ticket

1. Create the file as described above — from the order if the problem is about
   an order.
2. Open a ticket with TaxCloud support, or reply to your existing one.
3. Attach the ZIP file as it is. Do not unzip or edit it.
4. In the ticket, say what you expected and what happened instead — for
   example, "order 100000123 charged no tax on a Texas address".

The file is yours to send: it stays on your computer until you attach it.

## If you have server access

A developer or host can create the same file from the command line — useful when
the admin is not reachable:

```bash
bin/magento taxcloud:diagnostics:export
bin/magento taxcloud:diagnostics:export --order=100000123 --redact
```

The file is written to Magento's `var/` directory and its path is printed. See
the repository README for all options.
