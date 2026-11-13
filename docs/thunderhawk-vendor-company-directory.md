# Native vendor company-directory alignment

The company/client/vendor field release is installed and separately verified. Follow-up inspection found that vendor reads joined companies through the AP owner, supplied company IDs were not validated, and writes used the active workspace even when AP was shared.

The new candidate resolves AP and Companies owners independently from the authenticated workspace. Detail, search, filtering and counts use the selected Companies directory; canonical V and CO numbers retain their respective owners. Inaccessible or retired legacy links are marked as needing review in API responses without exposing their company identity or rewriting stored relationships. Automatic company creation uses the Companies owner. A repeated create preserves the original vendor ID and company link; a different-company retry is refused.

Company creation, vendor changes and their required audit receipt share a transaction. Invalid/foreign/retired selections and a failed audit leave no partial vendor/company creation. Existing vendor-setting writes use the same AP owner as reads. No schema, provider or financial execution change is included.

Six local compatibility/projection suites pass. Expanded native login/API cases cover all four AP/Companies sharing combinations, explicit and automatic creation, canonical IDs, list/count agreement, retries, malformed/foreign/deleted selections, unchanged rejected links, legacy review, read-only denial and audit-failure rollback. CI and isolated installation remain pending. Legacy JobDiva company/contact overwrite and identity handling, wider field alignment and full integration acceptance remain open.
