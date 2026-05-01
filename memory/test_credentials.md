# Test credentials

Existing demo/test user (unchanged from prior forks — no new credentials were
created in the AP Phase A0 session; AP module uses RBAC on the same identity):

- **email:** `kunal@coreflux.app`
- **role:** `master_admin`
- **password:** (seeded on Cloudways via `/install.php`; ask user if testing
  requires password reset — NOT stored in repo for security)

This user has all `ap.*` permissions (master_admin defaults to all perms)
including:

- `ap.view`
- `ap.bill.create`, `ap.bill.review`, `ap.bill.approve`, `ap.bill.void`, `ap.bill.post`
- `ap.payment.create`, `ap.payment.send`, `ap.payment.allocate`
- `ap.expense.submit`, `ap.expense.approve`
- `ap.recurring.manage`
- `ap.vendor.view_pii`
- `ap.1099.view`, `ap.1099.generate`
- `ap.reports.view`

**For two-eye testing** (bill approve, payment send, expense approve) you
need a second user. Create via master admin panel → Users → New user,
assign role `admin` or `tenant_admin`. Use a different email.

**Plaid Transfer keys** (deferred — feature env-gated via
`apPlaidConfigured()`): not set in this session. To enable, add
`PLAID_CLIENT_ID` and `PLAID_SECRET_SANDBOX` to Cloudways env.
