# JSON-Schema Exports

Subnet Calculator publishes JSON-Schema documents for any payload format that
round-trips through persistent storage. This lets third-party clients validate
input client-side before posting, and gives schema-aware tooling (IDE
autocomplete, contract testing) a stable contract to bind to.

All schemas are served with `Content-Type: application/schema+json` and
declare the JSON-Schema [Draft 2020-12](https://json-schema.org/draft/2020-12/schema)
dialect.

## Available schemas

| Name | URL | Used by |
|---|---|---|
| `vlsm-session` | `GET /api/v1/schemas/vlsm-session` | `POST /api/v1/sessions`, `GET /api/v1/sessions/{id}` |

## VLSM session payload

The `vlsm-session` schema (**v2** as of v3.0.0, #315) is a `oneOf` over three
discriminated branches via a `type` field: `'ipv4'`, `'ipv6'`, or `'tree'`.
The `type` field is optional on the IPv4 branch — when omitted, it defaults
to `'ipv4'` so payloads written by v2.x callers continue to validate.

The published `$id` is now
`https://subnetcalculator.app/api/schemas/vlsm-session.v2.schema.json`. v1
consumers that pinned the prior `$id` are unaffected; the v1 document remains
the historical record of what shipped before v3.0.0.

```json
// IPv4 (legacy & v3.0.0)
{
  "type": "ipv4",
  "network": "10.0.0.0",
  "cidr": "24",
  "requirements": [
    {"name": "LAN",  "hosts": 50},
    {"name": "DMZ",  "hosts": 14},
    {"name": "Mgmt", "hosts": 2}
  ]
}

// IPv6 — `hosts` can be an integer OR the "2^N" string form for sizings
// that overflow a signed 64-bit integer (N = 0–128).
{
  "type": "ipv6",
  "network": "2001:db8::",
  "cidr": "48",
  "requirements": [
    {"name": "Site-A", "hosts": "2^16"},
    {"name": "Site-B", "hosts": 100}
  ]
}

// Tree (v3.0.0 #302 — interactive subnet tree editor; lands in PR3)
{
  "type": "tree",
  "root": {
    "cidr":     "10.0.0.0/16",
    "name":     "Corp HQ",
    "children": [
      {"cidr": "10.0.0.0/17",   "name": "Production"},
      {"cidr": "10.0.128.0/17", "name": "Lab"}
    ]
  }
}
```

### Validation example (Node.js, `ajv`)

```js
import Ajv from 'ajv/dist/2020.js';

const schema = await fetch('https://your-host/api/v1/schemas/vlsm-session')
  .then(r => r.json());

const ajv = new Ajv();
const validate = ajv.compile(schema);

const payload = {
  network: '10.0.0.0',
  cidr: '24',
  requirements: [{ name: 'LAN', hosts: 50 }],
};

if (!validate(payload)) {
  console.error(validate.errors);
}
```

### Validation example (Python, `jsonschema`)

```python
import json, urllib.request
from jsonschema import Draft202012Validator

with urllib.request.urlopen('https://your-host/api/v1/schemas/vlsm-session') as r:
    schema = json.load(r)

payload = {
    'network': '10.0.0.0',
    'cidr': '24',
    'requirements': [{'name': 'LAN', 'hosts': 50}],
}

Draft202012Validator(schema).validate(payload)
```

## Stability and versioning

The `$id` URL is stable per major API version (`v1`). New optional fields are
non-breaking for clients validating against the **current live** `v1` schema.
Clients that pin a specific snapshot of a schema document may need to refresh
it when fields are added: the published schemas set
`additionalProperties: false`, so a pinned older copy will reject any new
field as invalid. Always re-fetch the schema from
`GET /api/v1/schemas/{name}` rather than embedding it in your build, or accept
that your CI will fail-closed when the server adds a field.

Field removals or type changes are breaking; they will only ship under
`/api/v2/` with a new `$id`.
