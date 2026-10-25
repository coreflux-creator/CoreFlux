# Core payment result evidence

Core's vendor-payment rail reserves one USD obligation for each durable attempt. A provider bill ID and terminal status alone cannot settle or release that reservation. A $40 payment against its bill must not settle a $125 Core payment.

The Core consumer requires the following evidence from an authenticated payment-detail read or verified signed callback. Complete bill-specific history only locates a payment; Core then reads its full detail so the compact history projection cannot conceal allocation semantics:

| Field | Meaning and validation |
| --- | --- |
| `id` or `payment_id` | Actual provider payment ID; both must agree when present. |
| `bill_id` or `payable_id` | Exactly the bill bound to the original Core attempt; both must agree when present. |
| `amount` | Gross amount for the one directly linked bill, matching Core's entire reserved amount to the cent. Decimal dollars, at most two decimal places; no rounding. |
| `currency` | Explicit `USD`. Missing currency is unknown, including on historical records. |
| `status` or `pay_status` | Explicit recognized payment state; multiple aliases must agree. A callback must also agree with its event type. |

Provider `gross_cents`, when present, must be an integer equal to the reserved cents. PurePay's stored `amount_cents` means the **net transfer**, unlike Core's request field of the same name. It requires explicit matching `net_cents`; those values must be positive integer cents no greater than the gross amount. They never substitute for the gross bill obligation. Ledger-only QuickBooks receipts and Mercury request acknowledgements do not prove delivery.

A QuickBooks bookkeeping reference can coexist with an actual Moov result: `moov_transfer_id` must be a valid identity, and `moov_status` must agree with the payment state (`completed` for settled, `failed`, `reversed` for returned, or `canceled`/`cancelled`). Missing or contradictory rail evidence is held. Explicit QuickBooks-only and Mercury methods remain unconfirmed. These checks also apply when rail fields are supplied without a bookkeeping reference.

The callback receiver accepts distinct settled, failed, returned and cancelled events. A return, cancellation or failure following settlement is held for reconciliation instead of reversing accounting automatically. An event ID is bound to its original signed bytes; changed-content reuse is rejected. An unmatched or review-held callback returns a retryable response after recording the reason; an already processed identical event is acknowledged without processing again.

PurePay's existing `allocated` response field describes an adjustment or overpayment, not an allocation of payment money to a bill. A nonzero adjustment, partial result, or multi-bill allocation structure requires review. The current consumer does not sum partial payments or apply a multi-bill payment total to one Core reservation. Supporting those cases requires a separate explicit allocation contract.

Core persists a versioned receipt after validating the result. Retries and the final AP clearance/cancellation checks require that receipt to match the saved payment, bill, amount, and status. An unreadable, missing, ambiguous, or conflicting current result adds a review hold while preserving the prior receipt and reservation. A fresh consistent result can resolve the hold. Status-only historical rows require reconciliation; they are not automatically backfilled.

## Provider compatibility gate

PurePay source `30ccb2a` is installed in the held `pure-pay-support` preview. New wallet records now store `USD` from the same execution contract used by the ACH and instant-transfer clients, with exact gross cents. Explicit incompatible bill currencies and invalid requested money are rejected before a payment is stored. Payment detail preserves the saved evidence, and immediate v1 terminal callbacks re-read the exact tenant/bill/payment before emitting the agreeing amount, currency and identifiers. Cached results cannot override missing or conflicting current records.

The installed sources passed 54 isolated full-application checks, with no candidate overlays, real database access, application startup or network calls. The check uses actual registered HTTP authentication/routes, a stub at the payment-execution boundary, an isolated Mongo fixture, and fake HTTP transport for the real ACH/instant clients. New-record creation is stopped immediately after storage, before financial execution. This proves those bounded contracts, not a live payout or a complete Thunderhawk financial round trip. Evidence is in `deploy/purepay-wallet-evidence-preview-20261025.json`; the provider's full per-test receipt is retained with its source and on the existing preview host.

The compatibility gate remains open. The consumer changes above and the provider's durable callback worker are being validated together; the prior installed preview receipt does not prove the new callback implementation. The provider candidate uses the existing payment event log to recover missed enqueue attempts, stable company/endpoint/event IDs, leased batches, retry backoff and a current-payment check before each delivery. Real Thunderhawk delivery/replay and existing endpoint subscriptions to returned/cancelled events still need verification. Historical missing currency requires reconciliation; neither detail nor bill history backfills it from today's configuration or a UI dollar sign. The preview keeps background jobs, migrations and scheduled operations disabled. No production publication, financial posting, payment or payroll execution is authorized by this change.
