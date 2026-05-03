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

The `vlsm-session` schema describes the JSON shape a VLSM planner session is
persisted in. Today the calculator only persists IPv4 VLSM state; the schema
will be expanded if/when IPv6 VLSM session save lands.

```json
{
  "network": "10.0.0.0",
  "cidr": "24",
  "requirements": [
    {"name": "LAN",  "hosts": 50},
    {"name": "DMZ",  "hosts": 14},
    {"name": "Mgmt", "hosts": 2}
  ]
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
