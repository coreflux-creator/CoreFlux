# AWS S3 Setup — One-Time Guide for CoreFlux

**Read this when you're ready to provision real AWS.** Total time: ~30 minutes.

This sets up the production storage backend for CoreFlux. Until this is done, the platform runs on `LocalDriver` (files on Cloudways disk) — fine for dev, not OK for production.

---

## What you're creating

1. An AWS account (skip if you have one)
2. An S3 bucket — `coreflux-prod` in `us-east-1`
3. A KMS encryption key — `alias/coreflux-platform`
4. An IAM user — `coreflux-app` — with credentials the PHP backend uses
5. Five environment variables added to your Cloudways `.env`

After this is done, code switches over by setting `STORAGE_DRIVER=s3`. Nothing in CoreFlux's code changes.

---

## Step 1 — Create the AWS account (skip if exists)

- Go to https://aws.amazon.com/, sign up.
- You'll need a credit card. New accounts get 12 months of free tier (5 GB S3 storage included).
- Use a generic email like `aws@yourcompany.com`, not your personal email.
- Enable MFA on the root account immediately (Account → Security credentials → MFA).
- **Don't** use the root account for day-to-day work. Create an admin IAM user and use that.

---

## Step 2 — Create the KMS key (encryption key)

This key encrypts every file at rest. Do this BEFORE creating the bucket.

1. AWS Console → **KMS** → **Customer managed keys** → **Create key**
2. **Key type**: Symmetric. **Key usage**: Encrypt and decrypt. **Next**.
3. **Alias**: `coreflux-platform`. **Next**.
4. **Key administrators**: pick yourself. **Next**.
5. **Key users**: skip for now (we'll add the IAM user via the policy below). **Next**.
6. Review → **Finish**.
7. **Copy the Key ID** (looks like `12345678-1234-1234-1234-123456789012`) — you'll need it.

---

## Step 3 — Create the S3 bucket

1. AWS Console → **S3** → **Create bucket**
2. **Bucket name**: `coreflux-prod` (must be globally unique — append a suffix like `coreflux-prod-yourcompany` if taken)
3. **Region**: **US East (N. Virginia) us-east-1**
4. ☑️ **Enable Object Lock** ← critical. Cannot be enabled later. This is what gives us 7-year IRS-compliant retention immutability for tax docs / pay stubs.
5. ☑️ **Block all public access** (default, leave on)
6. **Bucket Versioning**: **Enable**
7. **Default encryption**: 
   - Server-side encryption with AWS Key Management Service keys (SSE-KMS)
   - Pick the `coreflux-platform` key you just created
   - ☑️ **Bucket Key**: enable (saves on KMS API costs)
8. **Create bucket**

### Bucket → Permissions → CORS

Paste this into the CORS configuration (allows browser direct uploads):

```json
[
  {
    "AllowedHeaders": ["Authorization", "Content-Type", "x-amz-*"],
    "AllowedMethods": ["GET", "PUT", "POST", "HEAD"],
    "AllowedOrigins": [
      "https://app.corefluxapp.com",
      "https://*.corefluxapp.com"
    ],
    "ExposeHeaders": ["ETag", "x-amz-version-id"],
    "MaxAgeSeconds": 3600
  }
]
```

(Update origins later when custom domains come in Phase B.)

### Bucket → Management → Lifecycle rules

Add one rule named **"Cleanup old versions"**:
- Scope: this bucket
- Action: **Permanently delete noncurrent versions of objects** after **90 days**

(Lifecycle rules for Glacier transitions on `tax/*` and `payroll/*` we'll add later when those modules go live — don't need them today.)

---

## Step 4 — Create IAM user + access keys

1. AWS Console → **IAM** → **Users** → **Create user**
2. **User name**: `coreflux-app`
3. ☐ Don't grant console access (this is a programmatic user)
4. **Next** → **Attach policies directly** → **Create policy**

### IAM Policy JSON

Paste this exactly. Replace `coreflux-prod` if your bucket name is different. Replace `YOUR_ACCOUNT_ID` and `YOUR_KMS_KEY_ID`.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "BucketLevel",
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket",
        "s3:ListBucketVersions",
        "s3:GetBucketLocation"
      ],
      "Resource": "arn:aws:s3:::coreflux-prod"
    },
    {
      "Sid": "ObjectLevel",
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:GetObjectVersion",
        "s3:DeleteObject",
        "s3:DeleteObjectVersion",
        "s3:PutObjectRetention",
        "s3:GetObjectRetention",
        "s3:PutObjectLegalHold",
        "s3:GetObjectLegalHold",
        "s3:PutObjectTagging",
        "s3:GetObjectTagging"
      ],
      "Resource": "arn:aws:s3:::coreflux-prod/*"
    },
    {
      "Sid": "KMS",
      "Effect": "Allow",
      "Action": [
        "kms:Encrypt",
        "kms:Decrypt",
        "kms:GenerateDataKey",
        "kms:DescribeKey"
      ],
      "Resource": "arn:aws:kms:us-east-1:YOUR_ACCOUNT_ID:key/YOUR_KMS_KEY_ID"
    }
  ]
}
```

Name the policy `CorefluxAppS3KmsAccess`. Save.

5. Back to user creation: select the policy you just made. **Next** → **Create user**.
6. Click into `coreflux-app` → **Security credentials** → **Create access key**
7. Use case: **Application running outside AWS**
8. **Copy and save** the Access Key ID and Secret Access Key. You will not see the secret again.

---

## Step 5 — Add to Cloudways `.env`

SSH to Cloudways or use the Cloudways UI environment editor. Add these 5 lines:

```
STORAGE_DRIVER=s3
STORAGE_S3_BUCKET=coreflux-prod
STORAGE_S3_REGION=us-east-1
STORAGE_S3_ACCESS_KEY_ID=AKIA...your-key-id
STORAGE_S3_SECRET_ACCESS_KEY=your-secret-here
STORAGE_S3_KMS_KEY_ID=arn:aws:kms:us-east-1:YOUR_ACCOUNT_ID:key/YOUR_KMS_KEY_ID
STORAGE_SIGNED_URL_DEFAULT_TTL=300
```

Restart PHP-FPM via Cloudways UI (Application → App Settings → Restart Services).

---

## Step 6 — Verify

In SSH:

```bash
aws s3 ls s3://coreflux-prod --region us-east-1
# Should return empty (no error)

aws kms describe-key --key-id alias/coreflux-platform --region us-east-1
# Should return key details
```

If both work, you're done. Tell me to "switch to S3" and I'll verify CoreFlux's smoke tests pass against real S3.

---

## Cost expectations

For a small-medium agency tenant:
- Storage: ~5 GB → $0.12/month
- PUT requests (uploads): ~10k/month → $0.05
- GET requests (reads): ~50k/month → $0.02
- KMS calls: ~$0.30 (Bucket Key option keeps this low)
- **Total: ~$0.50/month** until volume grows

Glacier transitions (for tax docs, etc.) save ~95% on cold storage when we wire up those lifecycle rules later.

---

## Things to NOT do

- ❌ Don't enable public access on the bucket. Ever. We use signed URLs.
- ❌ Don't skip Object Lock at bucket creation — you cannot add it later.
- ❌ Don't store the access key in the codebase or commit `.env`.
- ❌ Don't use the AWS root account for the IAM policy. Use your admin IAM user.
- ❌ Don't enable Object Lock COMPLIANCE mode without legal review — it cannot be deleted by anyone, ever, including you. Use GOVERNANCE mode (which CoreFlux applies via API, with optional legal-hold override paths).

---

## When you hit issues tomorrow

Common ones:
- **"Access denied"** on bucket: IAM policy missing → re-check the policy JSON
- **"Access denied"** on KMS: KMS key policy needs to allow the IAM user → KMS console → your key → Key policy → add the user's ARN under `Allow use of the key`
- **CORS errors** on browser upload: add your dev domain to `AllowedOrigins`
- **Bucket name taken**: append a hyphenated suffix like `-yourcompany` and update `STORAGE_S3_BUCKET` env var

Ping me with the exact error message and I'll fix it.
