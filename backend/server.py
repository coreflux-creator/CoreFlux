"""
CoreFlux LayerFi Sandbox — local run harness (FastAPI gateway).

This is NOT a reimplementation of CoreFlux. CoreFlux is a PHP + MySQL app.
This thin FastAPI process exists only to make that PHP app runnable inside the
Emergent preview, whose ingress routes /api/* to port 8001:

  • On startup it ensures MariaDB + the CoreFlux PHP server (php -S :8080) are up.
  • It reverse-proxies every /api/* request to the PHP app (the real backend).
  • It exposes /api/dev/* helpers that mint short-lived JWTs for the two demo
    tenants so the standalone React sandbox has a simple tenant/role switcher
    without standing up the full CoreFlux login stack.

The LayerFi business logic, RBAC, tenant isolation and audit logging all live
in PHP under /app/modules/accounting/api/layer_*.php and
/app/core/integrations/layer/*.php.
"""
import os
import socket
import subprocess
import time
from contextlib import asynccontextmanager
from pathlib import Path

import httpx
import jwt as pyjwt
from dotenv import load_dotenv
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse, Response

BASE_DIR = Path(__file__).resolve().parent
APP_ROOT = BASE_DIR.parent  # /app
load_dotenv(BASE_DIR / ".env")

PHP_HOST = os.environ.get("PHP_HOST", "127.0.0.1")
PHP_PORT = int(os.environ.get("PHP_PORT", "8080"))
PHP_BASE = f"http://{PHP_HOST}:{PHP_PORT}"
MYSQL_SOCKET = os.environ.get("MYSQL_SOCKET", "/var/run/mysqld/mysqld.sock")
JWT_SECRET = os.environ.get("JWT_SECRET", "coreflux-sandbox-dev-secret-change-me")

# Env passed down to the PHP server process (LayerFi config + RBAC mode).
PHP_ENV_KEYS = [
    "ENABLE_LAYER_SANDBOX", "LAYER_ENV", "LAYER_API_BASE_URL", "LAYER_AUTH_URL",
    "LAYER_OAUTH_SCOPE", "LAYER_CLIENT_ID", "LAYER_CLIENT_SECRET",
    "LAYER_BUSINESS_TOKEN_TTL_SECONDS", "LAYER_TENANT_ALLOWLIST",
    "LAYER_TENANT_DEFAULT_ENABLED",
    "CF_RBAC_BRIDGE_MODE", "JWT_SECRET",
]

# Demo identities for the sandbox tenant/role switcher.
DEMO_TENANTS = [
    {"id": 1, "name": "Acme Corp (Sandbox)"},
    {"id": 2, "name": "Beta Industries (Sandbox)"},
]
DEMO_ROLES = {
    "master_admin": {"id": 1, "name": "Demo Admin", "email": "admin@coreflux.demo"},
    "tenant_admin": {"id": 2, "name": "Tenant Admin", "email": "tadmin@coreflux.demo"},
    "employee": {"id": 3, "name": "View Only", "email": "viewer@coreflux.demo"},
}


def _port_open(host: str, port: int, timeout: float = 0.4) -> bool:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.settimeout(timeout)
        return s.connect_ex((host, port)) == 0


def ensure_mariadb() -> None:
    if os.path.exists(MYSQL_SOCKET):
        return
    os.makedirs("/var/run/mysqld", exist_ok=True)
    subprocess.run(["chown", "mysql:mysql", "/var/run/mysqld"], check=False)
    if not os.path.isdir("/var/lib/mysql/mysql"):
        subprocess.run(
            ["mariadb-install-db", "--user=mysql", "--datadir=/var/lib/mysql"],
            check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
    subprocess.Popen(
        ["/usr/sbin/mariadbd", "--user=mysql", "--datadir=/var/lib/mysql",
         f"--socket={MYSQL_SOCKET}"],
        stdout=open("/tmp/mariadbd.log", "ab"), stderr=subprocess.STDOUT,
    )
    for _ in range(30):
        if os.path.exists(MYSQL_SOCKET):
            break
        time.sleep(1)


def ensure_seed() -> None:
    seed = APP_ROOT / "sql" / "layer_sandbox_seed.sql"
    if not seed.exists() or not os.path.exists(MYSQL_SOCKET):
        return
    try:
        with open(seed, "rb") as fh:
            subprocess.run(
                ["mysql", f"--socket={MYSQL_SOCKET}", "grcudkpvcd"],
                stdin=fh, check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
    except Exception as exc:  # pragma: no cover
        print(f"[harness] seed failed: {exc}")


def ensure_migrations() -> None:
    """Run CoreFlux migrations once if the LayerFi table is missing."""
    if not os.path.exists(MYSQL_SOCKET):
        return
    check = subprocess.run(
        ["mysql", f"--socket={MYSQL_SOCKET}", "grcudkpvcd", "-N", "-e",
         "SHOW TABLES LIKE 'tenant_layer_accounts'"],
        capture_output=True, text=True, check=False,
    )
    if "tenant_layer_accounts" in (check.stdout or ""):
        return
    subprocess.run(
        ["php", str(APP_ROOT / "core" / "migrate.php")],
        check=False, stdout=open("/tmp/migrate.log", "ab"), stderr=subprocess.STDOUT,
        env={**os.environ},
    )


def start_php() -> None:
    if _port_open(PHP_HOST, PHP_PORT):
        return
    php_env = {**os.environ, "PHP_CLI_SERVER_WORKERS": "6"}
    for k in PHP_ENV_KEYS:
        if os.environ.get(k) is not None:
            php_env[k] = os.environ.get(k, "")
    subprocess.Popen(
        ["php", "-S", f"{PHP_HOST}:{PHP_PORT}", str(APP_ROOT / "router.php")],
        cwd=str(APP_ROOT), env=php_env,
        stdout=open("/tmp/php_server.log", "ab"), stderr=subprocess.STDOUT,
    )
    for _ in range(30):
        if _port_open(PHP_HOST, PHP_PORT):
            break
        time.sleep(0.5)


@asynccontextmanager
async def lifespan(app: FastAPI):
    ensure_mariadb()
    ensure_seed()
    ensure_migrations()
    start_php()
    yield


app = FastAPI(title="CoreFlux LayerFi Sandbox Gateway", lifespan=lifespan)


def _mint_token(role: str, tenant_id: int) -> dict:
    user = DEMO_ROLES.get(role, DEMO_ROLES["master_admin"])
    now = int(time.time())
    payload = {
        "user_id": user["id"],
        "tenant_id": tenant_id,
        "name": user["name"],
        "email": user["email"],
        "role": role,
        "iat": now,
        "exp": now + 8 * 3600,
    }
    token = pyjwt.encode(payload, JWT_SECRET, algorithm="HS256")
    if isinstance(token, bytes):
        token = token.decode()
    return {"token": token, "user": {**user, "role": role}}


@app.get("/api/dev/context")
async def dev_context():
    """Demo tenants + roles for the standalone sandbox switcher."""
    return {
        "tenants": DEMO_TENANTS,
        "roles": [
            {"key": "master_admin", "label": "Internal Admin (master_admin)"},
            {"key": "tenant_admin", "label": "Tenant Admin"},
            {"key": "employee", "label": "View-only (no accounting.view)"},
        ],
    }


@app.get("/api/dev/token")
async def dev_token(tenant_id: int = 1, role: str = "master_admin"):
    if tenant_id not in (1, 2):
        return JSONResponse({"error": "unknown tenant"}, status_code=400)
    if role not in DEMO_ROLES:
        return JSONResponse({"error": "unknown role"}, status_code=400)
    minted = _mint_token(role, tenant_id)
    tenant = next((t for t in DEMO_TENANTS if t["id"] == tenant_id), None)
    return {**minted, "tenant": tenant}


@app.get("/api/health")
async def health():
    return {"ok": True, "php_up": _port_open(PHP_HOST, PHP_PORT), "service": "coreflux-layer-gateway"}


# Cookie + set-cookie are intentionally dropped: the gateway is stateless and
# every request must authenticate purely via its JWT bearer. Forwarding (or
# persisting) PHP session cookies would let one request reuse another user's
# session, so we never carry them.
_HOP_BY_HOP = {"host", "content-length", "accept-encoding", "connection", "keep-alive",
               "transfer-encoding", "te", "trailer", "upgrade", "proxy-authorization",
               "cookie"}


@app.api_route("/api/{path:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"])
async def proxy(path: str, request: Request):
    """Reverse-proxy everything else to the CoreFlux PHP backend (stateless)."""
    body = await request.body()
    fwd_headers = {k: v for k, v in request.headers.items() if k.lower() not in _HOP_BY_HOP}
    try:
        # Fresh client per request → no shared cookie jar, no session bleed.
        async with httpx.AsyncClient(base_url=PHP_BASE, timeout=60.0, cookies=None) as client:
            upstream = await client.request(
                request.method, f"/api/{path}",
                params=dict(request.query_params), content=body, headers=fwd_headers,
            )
    except Exception as exc:
        return JSONResponse(
            {"error": f"CoreFlux backend unavailable: {exc}", "kind": "proxy"},
            status_code=502,
        )
    resp_headers = {
        k: v for k, v in upstream.headers.items()
        if k.lower() not in _HOP_BY_HOP and k.lower() not in ("content-encoding", "set-cookie")
    }
    return Response(content=upstream.content, status_code=upstream.status_code,
                    headers=resp_headers, media_type=upstream.headers.get("content-type"))
