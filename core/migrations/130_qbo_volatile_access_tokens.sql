-- QuickBooks Payments security hardening.
-- Access tokens are short lived and now live only in encrypted volatile
-- cache storage. Refresh tokens remain AES-256-GCM encrypted at rest.

UPDATE qbo_connections
   SET access_token_ct = ''
 WHERE OCTET_LENGTH(access_token_ct) > 0;
