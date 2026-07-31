"""Invoicing API client — mirrors public/api/*.php routes."""

from typing import Any, Dict, Optional
import requests

from .exceptions import APIError, AuthenticationError, NotFoundError, ValidationError


class InvoicingClient:
    """HTTP client for DSC Invoicing JSON API."""

    def __init__(self, api_key: str, base_url: str = "https://invoicing.decisionsciencecorp.com"):
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        self.api_base = f"{self.base_url}/api"
        self.session = requests.Session()
        self.session.headers.update({
            "X-API-Key": self.api_key,
            "Content-Type": "application/json",
            "Accept": "application/json",
        })

    def _request(
        self,
        method: str,
        endpoint: str,
        params: Optional[Dict[str, Any]] = None,
        data: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        url = f"{self.api_base}/{endpoint.lstrip('/')}"
        try:
            response = self.session.request(
                method=method, url=url, params=params, json=data, timeout=60
            )
        except requests.exceptions.RequestException as exc:
            raise APIError(f"Request failed: {exc}") from exc
        try:
            body = response.json()
        except ValueError as exc:
            raise APIError(f"Invalid JSON: {response.text[:200]}") from exc
        err = body.get("error") if isinstance(body, dict) else None
        if response.status_code == 401:
            raise AuthenticationError(err or "Unauthorized", 401, body)
        if response.status_code == 404:
            raise NotFoundError(err or "Not found", 404, body)
        if response.status_code in (400, 405, 422):
            raise ValidationError(err or "Validation error", response.status_code, body)
        if response.status_code >= 400:
            raise APIError(err or f"HTTP {response.status_code}", response.status_code, body)
        return body if isinstance(body, dict) else {"data": body}

    def health(self, **params):
        return self._request("GET", "health.php", params=params or None)

    def list_companies(self, **params):
        return self._request("GET", "list-companies.php", params=params or None)

    def get_company(self, id: int, **params):
        params = dict(params)
        params["id"] = id
        return self._request("GET", "get-company.php", params=params)

    def create_company(self, **data):
        return self._request("POST", "create-company.php", data=data)

    def update_company(self, **data):
        return self._request("POST", "update-company.php", data=data)

    def delete_company(self, **data):
        return self._request("POST", "delete-company.php", data=data)

    def list_engagements(self, **params):
        return self._request("GET", "list-engagements.php", params=params or None)

    def get_engagement(self, id: int, **params):
        params = dict(params)
        params["id"] = id
        return self._request("GET", "get-engagement.php", params=params)

    def create_engagement(self, **data):
        return self._request("POST", "create-engagement.php", data=data)

    def update_engagement(self, **data):
        return self._request("POST", "update-engagement.php", data=data)

    def delete_engagement(self, **data):
        return self._request("POST", "delete-engagement.php", data=data)

    def list_time_entries(self, **params):
        return self._request("GET", "list-time-entries.php", params=params or None)

    def get_time_entry(self, id: int, **params):
        params = dict(params)
        params["id"] = id
        return self._request("GET", "get-time-entry.php", params=params)

    def create_time_entry(self, **data):
        return self._request("POST", "create-time-entry.php", data=data)

    def update_time_entry(self, **data):
        return self._request("POST", "update-time-entry.php", data=data)

    def delete_time_entry(self, **data):
        return self._request("POST", "delete-time-entry.php", data=data)

    def list_outbound_invoices(self, **params):
        return self._request("GET", "list-outbound-invoices.php", params=params or None)

    def get_outbound_invoice(self, id: int, **params):
        params = dict(params)
        params["id"] = id
        return self._request("GET", "get-outbound-invoice.php", params=params)

    def publish_combined_invoice(self, **data):
        return self._request("POST", "publish-combined-invoice.php", data=data)

    def refresh_outbound_invoice(self, **data):
        return self._request("POST", "refresh-outbound-invoice.php", data=data)

    def attach_tasks_document(self, **data):
        return self._request("POST", "attach-tasks-document.php", data=data)

    def cancel_outbound_invoice(self, **data):
        return self._request("POST", "cancel-outbound-invoice.php", data=data)

    def list_unpaid_aging(self, **params):
        return self._request("GET", "list-unpaid-aging.php", params=params or None)

    def list_audit_log(self, **params):
        return self._request("GET", "list-audit-log.php", params=params or None)

    def list_config(self, **params):
        return self._request("GET", "list-config.php", params=params or None)

    def list_api_keys(self, **params):
        return self._request("GET", "list-api-keys.php", params=params or None)

    def list_admin_users(self, **params):
        return self._request("GET", "list-admin-users.php", params=params or None)
