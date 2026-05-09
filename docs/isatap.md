# ISATAP Interface-ID Helper

Build and decode the 64-bit ISATAP interface ID defined by
[RFC 5214][rfc5214].

!!! warning "Limited deployment"
    ISATAP saw limited deployment outside specific enterprise
    transition scenarios. Native IPv6 connectivity has displaced it
    across the public internet. This tool is provided for analysis of
    legacy traffic captures and audits of addresses that still appear
    in old configuration files and route tables.

## How an ISATAP IID is structured

The 64-bit interface ID packs the embedded IPv4 plus a 2-bit "is
globally unique?" indicator into four 16-bit hexadectets:

```
| 32 bits     | 32 bits          |
| 0000:5efe   | V4ADDR (raw)     |   locally-administered
| 0200:5efe   | V4ADDR (raw)     |   globally-unique (u-bit set)
```

- The **`5efe`** magic in the second hexadectet is the IANA-assigned
  OUI-style value that identifies this IID as ISATAP.
- The **first hexadectet** carries the universal/local (u) bit per
  RFC 4291 §2.5.1: `0x0000` for locally-administered (the embedded
  IPv4 is from a private/reserved range), `0x0200` for
  globally-unique (the embedded IPv4 is publicly routable).
- The **bottom 32 bits** are the IPv4 address, stored raw with no
  obfuscation.

The IID is appended to any /64 prefix to form a complete IPv6 address.

## Encoding (IPv4 → ISATAP IID)

The encoder accepts any IPv4 literal and produces the colon-grouped
IID with leading zeros stripped per hexadectet (`inet_ntop`-style
canonical form):

| IPv4          | Mode             | IID                     |
| ------------- | ---------------- | ----------------------- |
| `192.0.2.1`   | globally-unique  | `200:5efe:c000:201`     |
| `192.0.2.1`   | locally-admin    | `0:5efe:c000:201`       |
| `10.0.0.1`    | auto (private)   | `0:5efe:a00:1`          |

When the universal/local bit is set to **Auto-detect**, the helper
classifies the IPv4 by `filter_var` private/reserved checks: public
addresses become globally-unique; private (RFC 1918) and reserved
(loopback, link-local, etc.) become locally-administered. You can
override either way explicitly via the **Force** options.

## Decoding (ISATAP IID → IPv4)

The decoder accepts both the canonical compressed form
(`0:5efe:c000:201`) and the fully zero-padded form
(`0000:5efe:c000:0201`). It pulls the embedded IPv4 back out and
reports the universal/local bit as a `globally_unique` boolean.

The decoder rejects any IID whose magic is not `0000:5efe` or
`0200:5efe`. It does **not** accept full 128-bit IPv6 addresses —
pass just the bottom-64 IID.

## Shareable URLs

The drawer state is fully captured in the URL:

```
/ipv6/isatap?isatap_mode=encode&isatap_ipv4=192.0.2.1
/ipv6/isatap?isatap_mode=decode&isatap_iid=200:5efe:c000:201
```

## Embedded-v4 detector deep link

The [Embedded IPv4 Detector](embedded-v4.md) recognises ISATAP
addresses (`*0000:5efe:V4` or `*0200:5efe:V4`) and deep-links into
this tool with the IID pre-filled.

## REST API

`POST /api/v1/isatap` — see the [API reference](api.md) and
[OpenAPI spec][openapi] for the full request/response shape.

[rfc5214]: https://datatracker.ietf.org/doc/html/rfc5214
[openapi]: https://github.com/seanmousseau/Subnet-Calculator/blob/main/Subnet-Calculator/api/openapi.yaml
