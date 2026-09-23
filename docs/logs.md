# Reading the log

The TaxCloud log is the record of what the extension did — which calls it made,
which were served from cache, which failed and why. When something looks wrong,
this is where you look first.

## Where it is

`var/log/taxcloud.log`, under your Magento installation.

Reading it directly needs file access to the server. If you do not have that,
create a [diagnostics file](diagnostics.md) from the admin: it includes the
recent log, or just an order's entries when created from the order.

## The three modes

*Stores → Configuration → Sales → Tax →* **TaxCloud Settings** → **Logging**

| Mode | What it records | When to use it |
|---|---|---|
| Enable - Basic | What happened and why: calls made, cache hits, orders skipped and the reason, every warning and error | Always. Low volume, safe permanently |
| Enable - Advanced | All of the above plus full request and response detail, and timing | While reproducing a problem |
| Disable | Nothing | Not recommended |

Basic is the default and is what you want day to day. It is enough to answer
"did this order reach TaxCloud", "why was this address skipped", "was that
figure cached".

Advanced adds everything TaxCloud support needs to diagnose a discrepancy: the
exact request sent and the exact response received.

!!! warning "Advanced logging produces a lot of data"
    Turn it on to reproduce a specific problem, then turn it back to Basic. If
    you leave it on, make sure log rotation is configured for
    `var/log/taxcloud.log` — ask your host if you are not sure.

!!! note "Your credentials are never in the log"
    In every mode the API ID and API Key are replaced with `***REDACTED***`
    before anything is written, including in full request dumps. Logs, backups
    and log-shipping tools never carry your credentials, so you can send a log
    file to support without exposing them.

## What a line looks like

```text
[2026-09-14T10:15:02.418223+00:00] tclogger.INFO: Calling authorizeCapture (v3 REST) for order 100000123 {"correlation_id":"3f9a1c07b2e4","operation":"capture","quote_id":"4411","order_increment_id":"100000123"} {"request":"9f3c1a2b","pid":812}
```

Each line starts with the time (UTC) and the level — `INFO` for what happened,
`WARNING` and `ERROR` for problems, `DEBUG` for the extra detail Advanced mode
adds. Then comes the message.

Every line ends with the request that wrote it, and lines written while
TaxCloud is working on a cart or an order carry a group of labels before that:

| Label | What it is |
|---|---|
| `correlation_id` | A short code shared by every line of one piece of work — one tax calculation, one capture, one refund. Lines with the same code belong together |
| `operation` | What the work was: `lookup` (tax calculation), `verify_address`, `capture`, `refund`, `cancel` or `order_details` |
| `quote_id` | The shopping cart, when known |
| `order_increment_id` | The order number, when known |

A tax calculation at checkout usually shows the cart but not yet the order
number; the capture and later steps show both. Lines that are not about a cart
or an order show `[]` in place of these labels.

The last group identifies the request:

| Label | What it is |
|---|---|
| `request` | A short code shared by every line one page load, admin action, command or background job wrote. Several shoppers and admins use the store at once, so their lines are mixed together by time; this tells them apart |
| `pid` | The server process that wrote the line. Some hosting providers do not allow reading it, and it is then left out |

Each entry is on a single line, request and response details included, and
names the API it used: `(v3 REST)` or `(v1 SOAP)`.

## What to look for

**Was this order reported?** Search the log for the order number, for example
`"order_increment_id":"100000123"`. The capture ends with a line saying either
`Order 100000123 captured in TaxCloud` or `Order 100000123 was NOT captured in
TaxCloud`, with the reason on the line before it. Refunds end the same way:
`Refund for order 100000123 recorded in TaxCloud`, or `was NOT recorded`. To
follow one step from start to finish, search for its `correlation_id`.

**Why was there no tax?** Look around the time of the order for a skipped
address, a destination outside the US (or a Canadian one while
[Canadian tax](canadian-tax.md) is off for that store), an invalid ZIP or postal
code, or a failed lookup.

**Which address was this order taxed against?** Every reported order records
whether it was sourced to its shipping or its billing address. A download-only
order shows the billing address, which is expected — see [Digital and
downloadable products](checkout.md#digital-and-downloadable-products). A
physical order showing the billing address is not.

**Why was this customer not exempt?** Certificate resolution is logged — which
identity was used, which certificates came back, whether one covered the
destination state.

**Is TaxCloud reachable?** Timeouts and connection failures appear as errors,
usually in runs when there is a problem.

**Is this figure cached?** Basic logging records cache hits, so you can tell a
stale answer from a fresh one.

## Sending a log to support

You do not need to send the log file by hand. A
[diagnostics file](diagnostics.md) includes it, with your credentials removed —
and when created from an order, only that order's entries.

When TaxCloud support asks for detailed logs:

1. Set **Logging** to `Enable - Advanced`.
2. Reproduce the problem — place the order, issue the refund, whatever it is.
3. Create a diagnostics file — from the order, if the problem is about one.
4. Set logging back to `Enable - Basic`.

Tell them the order number and roughly when it happened.

## Changing where the log is written

The location can be changed, and the same redaction applies wherever it goes.
That is a developer task — see [Extending the extension](extending.md).

## What the log will not tell you

It records what the extension did, not what it should have done. A wrong TIC
produces a perfectly clean log and the wrong tax. If the figures are wrong but
nothing is failing, the problem is configuration, not connectivity — start with
[Common problems](common-problems.md).
