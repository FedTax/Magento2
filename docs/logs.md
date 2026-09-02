# Reading the log

The TaxCloud log is the record of what the extension did — which calls it made,
which were served from cache, which failed and why. When something looks wrong,
this is where you look first.

## Where it is

`var/log/taxcloud.log`, under your Magento installation.

Reading it needs file access to the server. If you do not have that, ask your
developer or host for the file — or for the last few hundred lines of it, which
is usually enough.

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

## What to look for

**Was this order reported?** Search the log for the order increment ID. A
successful capture is recorded with the order.

**Why was there no tax?** Look around the time of the order for a skipped
address, a non-US destination, an invalid ZIP, or a failed lookup.

**Why was this customer not exempt?** Certificate resolution is logged — which
identity was used, which certificates came back, whether one covered the
destination state.

**Is TaxCloud reachable?** Timeouts and connection failures appear as errors,
usually in runs when there is a problem.

**Is this figure cached?** Basic logging records cache hits, so you can tell a
stale answer from a fresh one.

## Sending a log to support

When TaxCloud support asks for logs:

1. Set **Logging** to `Enable - Advanced`.
2. Reproduce the problem — place the order, issue the refund, whatever it is.
3. Note the time and the order number.
4. Take the log file, or the section around that time.
5. Set logging back to `Enable - Basic`.

Tell them the order number and roughly when it happened; it saves everyone
reading through unrelated traffic.

## Changing where the log is written

The location can be changed, and the same redaction applies wherever it goes.
That is a developer task — see [Extending the extension](extending.md).

## What the log will not tell you

It records what the extension did, not what it should have done. A wrong TIC
produces a perfectly clean log and the wrong tax. If the figures are wrong but
nothing is failing, the problem is configuration, not connectivity — start with
[Common problems](common-problems.md).
