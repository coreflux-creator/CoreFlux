# PurePayroll employee setup observations

The new `2026-10-22.1` setup contract lets a Core reviewer inspect a confirmed
employee link without interpreting native setup indicators as onboarding
completion or payroll authorization. It is a read-only addition to the existing
companion, not a complete onboarding workflow or a live Thunderhawk acceptance.

## Source semantics

PurePayroll's captured `payroll.py` administrator view reports invitation,
profile-field presence, tax-record provenance and bank-reference presence.
`safe_employee` removes account numbers before that view computes its bank flag;
the exchange therefore uses `bank_last4` presence and never reads bank numbers.
The private values are neither returned nor hashed into the observation version.
The native employee status is reported separately, with unknown values preserved
as `unknown` rather than being interpreted as approved.

The native portal's W-4 indicator differs from the administrator view: the portal
requires employee confirmation while the administrator also recognizes a
provider-synchronized election. The exchange explicitly distinguishes
`employee_confirmed`, `provider_recorded` and `not_recorded`.

The detailed `payroll_onboarding.py` workflow is specific to SurePayroll new hires.
Its encrypted review packet and `provider_ready` flag are not used for this
contract. Quanta's native checklists belong to individual clock-in entries and
are also not employee onboarding records. Neither source establishes a general
Thunderhawk onboarding-complete decision.

## Access and identity

- PurePayroll requires a dedicated Core key, `read:payroll`, explicit
  `read:people_setup`, and the organization's payroll feature. Existing keys do
  not acquire this new permission. The key-creation checkbox defaults off.
- Core requires connection-management, people-view, payroll-view and
  people-documents-view permissions. Directory ownership comes from module
  scope, never a browser-supplied company or directory ID.
- Core follows a single delivered local person link; PurePayroll verifies the
  exact installation, directory, person number and provider employee identity
  against both directions of the provider link. Missing, ambiguous or conflicting
  identities fail closed. Email is never an identity match.
- Core checks the provider company/credential binding and rereads the source and
  local link after the provider request. Revocation or identity changes reject
  the observation.

## Data and limits

`GET /api/v1/integrations/coreflux/people/setup` returns canonical person and
provider IDs, a status revision, UTC observation time, and these indicators:
employee status; invitation recorded; profile fields present; tax election record
source; bank reference present. Both `onboarding_completion` and
`payroll_authorization` are explicitly `not_evaluated`.

Responses use `Cache-Control: no-store, private`. Core allowlists the response,
rejects invalid booleans/statuses and observations older than five minutes or more
than one minute ahead, and discards any extra provider fields. The Core screen
displays the observed time and explains the status limits. It stores no employee
setup observation, sends no invitation, changes no employee and creates no payroll.

The opaque status revision changes with the safe indicators or native record
incarnation. It deliberately does not reveal private-value changes that leave
the indicators unchanged. This is informational state at the time of the read;
it cannot authorize a later operation.

## Verification and release state

Local isolated validation: 40 provider/identity/people/PHP bridge tests passed;
the PHP bridge includes 41 cross-language checks. The new receipt validator has
22 checks, key-scope controls have 10 tests, and the new Core view has three render
tests. The full Core frontend build passed with existing unrelated warnings.
The MariaDB link fixture includes eight new setup access, identity and concurrent
change checks and is included in CI.

This addition initially exists only in the Core branch and local PurePay mirror.
The hosted PurePay review commit `7dbab9b0` predates it. Hosted dependency/runtime
validation and an updated companion review commit are still required. The prior
Live/Test setting corrections remain unsaved pending the user's answer. No new
key or access grant, production publication, live employee change or Thunderhawk
round trip is established by these tests.

Onboarding task ownership, required evidence, employer review, expiry/revision
history, restricted document references and Quanta portal delivery remain to be
implemented and accepted independently of these setup indicators.
