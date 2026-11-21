#!/usr/bin/env python3
"""Fetch a complete MSP firewall inventory. Credentials come only from the environment."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import random
import sys
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener, HTTPSHandler
import ssl

DEFAULT_BASE_URL = 'https://pfcentral.example.com/api/v1'


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class InventoryClient:
    def __init__(self, token, base_url=DEFAULT_BASE_URL, opener=None, sleeper=time.sleep):
        self.base_url = base_url.rstrip('/')
        parsed = urlsplit(self.base_url)
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError('API base URL must be an HTTPS URL without credentials, query or fragment.')
        self.token = token.strip()
        if not self.token or '\r' in self.token or '\n' in self.token:
            raise ValueError('Set PFCENTRAL_API_TOKEN to a valid bearer token.')
        self.opener = opener or build_opener(HTTPSHandler(context=ssl.create_default_context()), NoRedirect())
        self.sleep = sleeper

    def get(self, path, parameters=None):
        url = self.base_url + '/' + path.lstrip('/')
        if parameters:
            url += '?' + urlencode(parameters)
        for attempt in range(5):
            request = Request(url, headers={'Authorization': 'Bearer ' + self.token, 'Accept': 'application/json'})
            try:
                with self.opener.open(request, timeout=30) as response:
                    return json.load(response)
            except HTTPError as error:
                status = error.code
                retry_after = error.headers.get('Retry-After', '')
                error.close()
                if status not in (429, 500, 502, 503, 504) or attempt == 4:
                    raise RuntimeError(f'API returned HTTP {status} for {path}; snapshot was not updated.') from None
                delay = max(1.0, float(retry_after)) if retry_after.isdigit() else min(30.0, 2 ** attempt + random.random())
                self.sleep(delay)
            except URLError:
                if attempt == 4:
                    raise RuntimeError('Network or TLS request failed; snapshot was not updated.') from None
                self.sleep(min(30.0, 2 ** attempt + random.random()))

    def fetch_all(self):
        identity = self.get('me')['data']
        if identity.get('all_companies') is not True:
            raise RuntimeError('An all-company MSP token is required for this full inventory job.')
        companies = self.get('companies')['data']
        company_names = {row['id']: row['name'] for row in companies}
        firewalls, seen, expected_total, expected_last_page = [], set(), None, None
        page = 1
        while True:
            result = self.get('firewalls', {'per_page': 100, 'page': page})
            meta = result['meta']
            if meta['current_page'] != page or not isinstance(meta['last_page'], int) or not 1 <= meta['last_page'] <= 1000000:
                raise RuntimeError('Unexpected pagination metadata; snapshot was not updated.')
            if expected_total is None:
                expected_total, expected_last_page = meta['total'], meta['last_page']
            if meta['total'] != expected_total or meta['last_page'] != expected_last_page:
                raise RuntimeError('Inventory changed during pagination; retry a full sync.')
            for row in result['data']:
                if row['id'] in seen:
                    raise RuntimeError('Duplicate inventory ID during pagination; retry a full sync.')
                seen.add(row['id'])
                firewalls.append({**row, 'company_name': company_names.get(row['company_id'])})
            if page >= meta['last_page']:
                break
            page += 1
        if len(firewalls) != expected_total:
            raise RuntimeError('Incomplete inventory retrieval; snapshot was not updated.')
        return {'source': 'PFCentral', 'source_instance': self.base_url,
                'inventory_scope': 'registered_firewalls_only',
                'retrieved_at': datetime.now(timezone.utc).isoformat(),
                'counts': {'companies': len(companies), 'firewalls': len(firewalls)},
                'companies': companies, 'firewalls': firewalls}


def save_snapshot(snapshot, destination):
    destination = Path(destination)
    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=destination.parent,
                                         prefix='.' + destination.name + '.', delete=False) as output:
            temporary = Path(output.name)
            json.dump(snapshot, output, indent=2, ensure_ascii=False)
            output.write('\n')
        os.replace(temporary, destination)
    finally:
        if temporary and temporary.exists():
            temporary.unlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default='inventory.json', help='Snapshot file to replace only after a complete sync')
    args = parser.parse_args()
    try:
        client = InventoryClient(os.environ.get('PFCENTRAL_API_TOKEN', ''), os.environ.get('PFCENTRAL_BASE_URL', DEFAULT_BASE_URL))
        snapshot = client.fetch_all()
        save_snapshot(snapshot, args.output)
        print(f"Saved {snapshot['counts']['companies']} companies and {snapshot['counts']['firewalls']} firewalls to {args.output}")
        return 0
    except (ValueError, RuntimeError, KeyError, TypeError, OSError) as error:
        print(str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
