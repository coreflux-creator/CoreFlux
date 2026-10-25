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

PurePay's existing `allocated` response field describes an adjustment or overpayment, not an allocation of payment money to a bill. A nonzero adjustment, partial result, or multi-bill allocation structure requires review. The current consumer does not sum partial payments or apply a multi-bill payment total to one Core reservation. Supporting those cases requires a separate explicit allocation contract.

Core persists a versioned receipt after validating the result. Retries and the final AP clearance/cancellation checks require that receipt to match the saved payment, bill, amount, and status. An unreadable, missing, ambiguous, or conflicting current result adds a review hold while preserving the prior receipt and reservation. A fresh consistent result can resolve the hold. Status-only historical rows require reconciliation; they are not automatically backfilled.

## Provider compatibility gate

The reviewed PurePay source at `01515145805ee30054e25e77c06538adfa68db14` stores wallet payment `amount` without an explicit `currency`, and its terminal callback helper omits both amount and currency. Those results intentionally remain unresolved under this consumer contract. The bounded bill-history endpoint also preserves missing source money as null; it must keep doing so.

Before real payment activation, the provider needs a separately reviewed change that establishes currency from the actual payment execution contract, records that evidence for new payments, and emits the same gross money and direct bill identity in detail/history/callbacks. Historical currency cannot be inferred from the current workspace, today's configuration, or a UI dollar sign. This Core change does not authorize or execute a payment and does not change PurePay source or runtime.
