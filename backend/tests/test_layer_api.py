"""Backend API tests for the CoreFlux LayerFi Sandbox embed module."""
import os
import pytest
import requests

BASE_URL = "https://64f26f5e-ae8a-4fdd-8d69-21dbdfae4220.preview.emergentagent.com"


def _dev_token(tenant_id: int, role: str):
    r = requests.get(f"{BASE_URL}/api/dev/token", params={"tenant_id": tenant_id, "role": role}, timeout=15)
    assert r.status_code == 200, r.text
    return r.json()


@pytest.fixture(scope="module")
def admin_t1():
    return _dev_token(1, "master_admin")


@pytest.fixture(scope="module")
def admin_t2():
    return _dev_token(2, "master_admin")


@pytest.fixture(scope="module")
def employee_t1():
    return _dev_token(1, "employee")


def _auth(token):
    return {"Authorization": f"Bearer {token}"}


# --- /api/dev/token ---
class TestDevToken:
    def test_dev_token_master_admin(self, admin_t1):
        assert "token" in admin_t1 and isinstance(admin_t1["token"], str)
        assert admin_t1["user"]["role"] == "master_admin"
        assert admin_t1["tenant"]["id"] == 1


# --- smoke ---
class TestSmoke:
    def test_smoke_master_admin(self, admin_t1):
        r = requests.get(f"{BASE_URL}/api/accounting/layer-smoke-test", headers=_auth(admin_t1["token"]), timeout=15)
        assert r.status_code == 200, r.text
        data = r.json()
        assert data.get("provider") == "layer"
        assert data.get("environment") == "sandbox"
        assert data.get("ok") is True
        assert data.get("stub") is True


# --- status ---
class TestStatus:
    def test_status_master_admin(self, admin_t1):
        r = requests.get(f"{BASE_URL}/api/accounting/layer-status", headers=_auth(admin_t1["token"]), timeout=15)
        assert r.status_code == 200, r.text
        data = r.json()
        assert data.get("provider") == "layer"
        assert data.get("enabled") is True
        assert "configured" in data and isinstance(data["configured"], bool)
        assert data.get("environment") == "sandbox"

    def test_status_no_auth_401(self):
        r = requests.get(f"{BASE_URL}/api/accounting/layer-status", timeout=15)
        assert r.status_code == 401, r.text

    def test_status_employee_403(self, employee_t1):
        r = requests.get(f"{BASE_URL}/api/accounting/layer-status", headers=_auth(employee_t1["token"]), timeout=15)
        assert r.status_code == 403, r.text
        # ensure no token leakage
        assert "businessAccessToken" not in r.text


# --- setup-tenant + idempotency + isolation ---
class TestSetupTenant:
    def test_setup_tenant1_and_idempotent(self, admin_t1):
        body = {"legalName": "Acme Corp LLC"}
        r1 = requests.post(f"{BASE_URL}/api/accounting/layer-setup-tenant", headers=_auth(admin_t1["token"]), json=body, timeout=20)
        assert r1.status_code == 200, r1.text
        d1 = r1.json()
        assert d1.get("layerBusinessId")
        assert d1.get("layerExternalId") == "coreflux:sandbox:tenant:1"

        # second call: idempotent
        r2 = requests.post(f"{BASE_URL}/api/accounting/layer-setup-tenant", headers=_auth(admin_t1["token"]), json=body, timeout=20)
        assert r2.status_code == 200, r2.text
        d2 = r2.json()
        assert d2.get("layerBusinessId") == d1.get("layerBusinessId")
        assert d2.get("created") is False

    def test_setup_tenant2_isolated(self, admin_t1, admin_t2):
        body1 = {"legalName": "Acme Corp LLC"}
        r1 = requests.post(f"{BASE_URL}/api/accounting/layer-setup-tenant", headers=_auth(admin_t1["token"]), json=body1, timeout=20)
        assert r1.status_code == 200
        biz1 = r1.json().get("layerBusinessId")

        body2 = {"legalName": "Beta Industries LLC"}
        r2 = requests.post(f"{BASE_URL}/api/accounting/layer-setup-tenant", headers=_auth(admin_t2["token"]), json=body2, timeout=20)
        assert r2.status_code == 200, r2.text
        d2 = r2.json()
        assert d2.get("layerExternalId") == "coreflux:sandbox:tenant:2"
        assert d2.get("layerBusinessId") and d2.get("layerBusinessId") != biz1


# --- business-token ---
class TestBusinessToken:
    def test_business_token_master_admin(self, admin_t1):
        # ensure setup first
        requests.post(f"{BASE_URL}/api/accounting/layer-setup-tenant", headers=_auth(admin_t1["token"]),
                      json={"legalName": "Acme Corp LLC"}, timeout=20)
        r = requests.post(f"{BASE_URL}/api/accounting/layer-business-token", headers=_auth(admin_t1["token"]), timeout=20)
        assert r.status_code == 200, r.text
        data = r.json()
        assert data.get("businessId")
        assert isinstance(data.get("businessAccessToken"), str) and data["businessAccessToken"]
        assert "expiresIn" in data
        assert data.get("environment") == "sandbox"

    def test_business_token_employee_403(self, employee_t1):
        r = requests.post(f"{BASE_URL}/api/accounting/layer-business-token", headers=_auth(employee_t1["token"]), timeout=15)
        assert r.status_code == 403, r.text
        assert "businessAccessToken" not in r.text


# --- client-error ---
class TestClientError:
    def test_client_error_master_admin(self, admin_t1):
        body = {"type": "api", "scope": "BankTransactions", "payload": {"message": "x"}}
        r = requests.post(f"{BASE_URL}/api/accounting/layer-client-error", headers=_auth(admin_t1["token"]), json=body, timeout=15)
        assert r.status_code == 200, r.text
        assert r.json().get("ok") is True
