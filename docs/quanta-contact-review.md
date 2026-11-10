# Reviewed contact changes between Core and Quanta

Status: integration branch only, 2026-11-10. Core review, the saved-decision return queue and Quanta's native receiver are implemented, with worker and reviewer controls. Neither branch is a published Thunderhawk workflow.

## Field ownership

| Value | Current implementation | Required return behavior |
| --- | --- | --- |
| Core person number and Quanta worker ID | Existing explicit links identify the same person. Contact requests cannot change either ID. | Preserve both IDs. |
| First and last name | Core publishes a versioned contact snapshot. The signed-in Quanta worker proposes corrections; Core staff accept or reject them. | Align Quanta's native full name after confirmed acceptance. |
| Contact email | Same reviewed request flow; normalized lowercase ASCII or an explicit empty value. | Store as contact email separately from sign-in identity. |
| Phone | Same reviewed request flow, including an explicit request to clear the value. | Align Quanta's native phone, including clearing it, without overwriting concurrent edits. |
| Sign-in email, credentials, roles and access | Excluded from both the proposal and Core update. | No change through this workflow. |
| Placement, rates, payroll and financial data | Excluded from contact proposals. | Continue through their respective reviewed contracts. |

## Implemented path

1. In Core's Quanta settings, share a reviewed contact snapshot for an already linked person. The provider publication receipt and encrypted, immutable snapshot are saved together. An uncertain publication is recovered from the original receipt, without blindly sending it again.
2. In Quanta's Profile page, the current worker can view their own published details and submit a correction. The request records the original version/hash, the proposed values and the authenticated worker identity. A lost response retains the original request identity for retry.
3. Core's contact request list resolves the canonical person into the authorized people directory. Unverified or foreign-directory source records are withheld. Staff see the originally shared, proposed and current Core values.
4. Acceptance compares all three versions. Unrelated Core changes are preserved. A conflicting change to the same field blocks acceptance of the whole request. Rejection requires a note. A Core edit after preview requires a new review.
5. A transaction locks the current connection, directory policy, Core person and worker link. The native contact change and encrypted decision/audit receipt commit together. Failure to save the receipt rolls back the contact update. A repeat request returns its original result; a contradictory decision cannot replace it.

`COREFLUX_QUANTA_PROFILE_RETURN` defaults off and also respects the existing tenant allowlist. The API requires `integrations.quanta.manage` and `people.view`; request listing, review, decisions, return controls and receipts additionally require `people.manage`. Migration 185 stores baseline evidence; 186 stores immutable decisions, including encrypted before/after values, source proposal, actor and reason. Migration 187 keeps mutable attempt and encrypted return-receipt evidence separate from that original decision.

6. Staff load the saved-decision queue and explicitly return a decision. Core reconstructs the exact message from the encrypted outbox, verifies the company, directory and worker link, and commits a delivery claim before provider IO. A newer edit to the accepted Core fields blocks a stale return; unrelated edits remain intact.
7. A completed provider receipt records whether contacts were applied, conflicted or rejected. A received-only or interrupted response remains unconfirmed. Inspection performs GET only. An explicit resume reads the original resource again and sends the exact immutable PUT, allowing Quanta to resume its saved conditional plan. Missing receipts never trigger an automatic retry. Historical completed receipts remain inspectable after Core offboarding.

## Quanta receiver and interrupted returns

The new `PUT /api/v1/integrations/coreflux/profile-changes/{change_id}/decision` resource requires both Core and worker write permissions. It accepts one immutable, hash-verified Core decision for the original proposal. An identical PUT may resume that original conditional plan; a different decision conflicts. Its GET receipt requires both read permissions and never performs a native update. A worker session cannot use the provider decision route.

At submission, Quanta saves its native contact observation separately from the Core baseline. An accepted return compares the requested native fields with that observation, preserving unrelated edits. A newer same-field edit blocks all contact changes and produces an explicit conflict outcome while retaining Core's acceptance. Full name and phone update in the native worker document; contact email is a separate native `contact_email` field and never replaces `email` or the global sign-in identity. Rejection changes no native contacts.

Mongo transactions are not assumed. The contact patch and a recovery marker commit in one worker-document write. Concurrent deliveries use one saved conditional plan. The marker cannot be replaced until its immutable proposal outcome is recorded; a later decision waits for the earlier one if necessary. Losing the response or outcome write therefore does not authorize a second contact update. The receipt distinguishes received, applied, conflict and rejected states. Older requests without a saved native observation cannot be applied by guessing a baseline.

The UI labels an accepted decision as accepted in Core and awaiting return until a completed receipt is verified. A native conflict remains visible as reconciliation work. Snapshot publication does not update Quanta's native name, phone or sign-in email.

## Validation and remaining release gates

The MariaDB fixture publishes synthetic snapshots through the existing delivery ledger, then exercises actual Core updates, rejection, same-field conflicts, unrelated edits, duplicate previews/retries, changed source, connection/directory/worker changes, offboarding, explicit phone removal, duplicate email, missing publication evidence and rollback after receipt failure. UI tests cover explicit confirmation, conflict handling, publication versus decision confirmation, workspace changes and lost-response recovery. Browser/PHP/Python hashing shares the same ASCII canonical JSON fixture.

The return fixture covers an immutable queued body, company and directory isolation, provider capability checks, initial delivery, explicit recovery, missing receipts, native conflicts, known refusals, lost acknowledgements, local receipt failures and worker-link changes. The new UI tests cover separately confirmed initial delivery and recovery, preserved workspace scope and honest native-conflict presentation.

Remaining: pass the new return path on real MariaDB; test the Core HTTP permission boundary with real sessions; complete the authenticated hosted worker-to-Core-to-worker browser round trip; update the full integration field dictionary; and merge/deploy only after complete acceptance. The Quanta receiver has native API-key/worker-session route tests and concurrent/failure storage tests using isolated mock Mongo; an actual isolated Mongo process is still required for full acceptance. These fixtures do not establish production readiness, actual Thunderhawk staff approval or a completed live synchronization.
