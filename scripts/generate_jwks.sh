#!/usr/bin/env bash
#
# Generate the Apollo Router's JWKS file from the shared JWT_SECRET.
#
# Usage:
#   JWT_SECRET=... JWKS_PATH=/etc/coreflux/jwks.json ./generate_jwks.sh
#
# Run at deploy time. The router watches the file path (file://) and
# hot-reloads on change, so rotating JWT_SECRET is:
#   1. update env
#   2. rerun this script
#   3. router picks up the new key within ~1s
#
# Why JWKS instead of inlining the secret in router.yaml?
#   - The router only accepts JWKS for signature material.
#   - JWKS is a JSON Web Key set — a standard format for keys-as-data.
#   - For HS256 the key is `kty: oct` with `k: base64url(secret)`.

set -euo pipefail

: "${JWT_SECRET:?JWT_SECRET env var required}"
JWKS_PATH="${JWKS_PATH:-/etc/coreflux/jwks.json}"

mkdir -p "$(dirname "$JWKS_PATH")"

# base64url-encode the secret. (jwt-cli or openssl-base64 alternatives
# would also work; awk keeps the dependency surface zero.)
B64=$(printf '%s' "$JWT_SECRET" | base64 -w0 2>/dev/null || printf '%s' "$JWT_SECRET" | base64)
B64URL=$(printf '%s' "$B64" | tr '+/' '-_' | tr -d '=')

cat > "$JWKS_PATH" <<EOF
{
  "keys": [
    {
      "kty": "oct",
      "alg": "HS256",
      "use": "sig",
      "kid": "coreflux-hs256-1",
      "k":   "$B64URL"
    }
  ]
}
EOF

chmod 0640 "$JWKS_PATH"
echo "[generate_jwks] wrote $JWKS_PATH"
