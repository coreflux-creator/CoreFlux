# Saved subcontract-company review

The stored assignment preview now uses the same read-only evidence lookup as its
apply operation. A C2C subcontract ID needs an existing company in the same tenant,
a successful saved company record with a matching ID and usable name, or an exact
ID/name pair in its assignment contract. Placeholders and foreign-tenant records
do not satisfy that requirement. Preview does not create companies, roles, vendors,
or mappings, and stored review never fetches the provider while holding its locks.

The Contract checks section reports the evidence source and name, or explains that
saved company evidence must be refreshed in JobDiva settings before refreshing the
preview. Missing evidence blocks selection and contract completeness. The review
token covers the selected company evidence, including the saved payload or current
canonical company. Changed evidence requires a new review before any write.

The disposable-database test covers missing evidence, placeholders, mismatched IDs,
failed company mirrors, foreign tenant isolation, name-only assignment evidence,
stale saved and canonical data, canonical payee alignment, preserved identities,
replay, and no financial posting. Its initial candidate found a fixture assumption
that every table had an `id` column; comparison now sorts complete rows, including
the company-role table's composite key. The existing React review component has a
test proving that the new blocker is visible and cannot be selected.

CI and isolated hosted acceptance are pending. This change does not establish full
integration completion or authorize production or live financial activity.
