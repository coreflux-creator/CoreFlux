# Reviewed contact changes between Core and Quanta

Status: integration branch only, 2026-11-10. The Core review service and screens are implemented. The Quanta worker request screen is implemented in its separate source branch. Neither is a published Thunderhawk workflow. Returning the saved decision to Quanta and updating its native contact fields remain required before release.

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

`COREFLUX_QUANTA_PROFILE_RETURN` defaults off and also respects the existing tenant allowlist. The API requires `integrations.quanta.manage` and `people.view`; request listing, review, decisions and decision receipts additionally require `people.manage`. Migration 185 stores baseline evidence; 186 stores immutable decisions, including encrypted before/after values, source proposal, actor and reason. The decision record also supplies the pending return message; **no decision delivery consumer exists yet**.

The UI labels an accepted decision as accepted in Core and awaiting return to Quanta. It must not represent that state as synchronized. Snapshot publication does not update Quanta's native name, phone or sign-in email.

## Validation and remaining release gates

The MariaDB fixture publishes synthetic snapshots through the existing delivery ledger, then exercises actual Core updates, rejection, same-field conflicts, unrelated edits, duplicate previews/retries, changed source, connection/directory/worker changes, offboarding, explicit phone removal, duplicate email, missing publication evidence and rollback after receipt failure. UI tests cover explicit confirmation, conflict handling, publication versus decision confirmation, workspace changes and lost-response recovery. Browser/PHP/Python hashing shares the same ASCII canonical JSON fixture.

Remaining: implement and validate decision return, native Quanta contact alignment and current-contact conflict handling; test the Core HTTP permission boundary with real sessions; complete the authenticated hosted worker-to-Core-to-worker browser round trip; update the full integration field dictionary; and merge/deploy only after the complete acceptance. These fixtures do not establish production readiness, actual Thunderhawk staff approval or a completed live synchronization.
