# 6rd Address Tool

Compute the customer-delegated IPv6 prefix produced by a 6rd
service-provider domain, defined by [RFC 5969][rfc5969]. The tool also
runs the reverse: given a 6rd IPv6 address and the SP parameters, it
extracts the customer's IPv4.

!!! note "Service-provider configuration required"
    Unlike 6to4 (which uses the well-known `2002::/16` prefix and
    embeds the full IPv4), 6rd uses the SP's own globally-routed IPv6
    prefix. The **SP IPv6 prefix** and **SP IPv4 mask length** are
    out-of-band configuration — typically delivered to CPE via DHCPv6
    OPTION_6RD. The tool cannot guess them from the address alone, so
    this tool does not auto-detect 6rd from the embedded-v4 detector.

## How a 6rd delegation is constructed

The customer-delegated IPv6 prefix is the concatenation of:

1. The SP IPv6 prefix (`sp_ipv6_prefix`, e.g. `2001:db8::/32`).
2. All but the first `sp_ipv4_mask_len` bits of the customer IPv4
   address. The masked-off bits are constant across the SP's footprint
   and therefore not embedded.

The new prefix length is `sp_prefix_length + (32 - sp_ipv4_mask_len)`.

## Worked examples

### Full v4 (mask length 0)

| Field            | Value                  |
| ---------------- | ---------------------- |
| SP IPv6 prefix   | `2001:db8::/32`        |
| SP IPv4 mask len | `0`                    |
| Customer IPv4    | `192.0.2.1`            |
| Delegated prefix | `2001:db8:c000:201::/64` |

The full 32 bits of the customer v4 follow the /32 SP prefix, giving
a /64 delegation.

### Truncated v4 (mask length 8)

| Field            | Value                |
| ---------------- | -------------------- |
| SP IPv6 prefix   | `2001:db8::/32`      |
| SP IPv4 mask len | `8`                  |
| Customer IPv4    | `192.0.2.1`          |
| Delegated prefix | `2001:db8:2:100::/56` |

The top 8 bits of the v4 (`0xC0`) are discarded; the remaining 24 bits
(`0x000201`) are appended to the /32 SP prefix, yielding a /56 with
the customer-contributed bits packed at IPv6 bits 32..55.

## Decoding (6rd address → customer IPv4)

The decoder verifies the address starts with the SP prefix bits, then
reads the customer-contributed bits from the position immediately
following. The masked-off region (top `sp_ipv4_mask_len` bits of the
v4) is treated as zero — that portion is SP-side configuration and
cannot be recovered from the address alone.

## Shareable URLs

The drawer state is fully captured in the URL:

```
/ipv6/6rd?sixrd_mode=encode&sixrd_sp_prefix=2001:db8::/32&sixrd_mask_len=0&sixrd_ipv4=192.0.2.1
/ipv6/6rd?sixrd_mode=decode&sixrd_sp_prefix=2001:db8::/32&sixrd_mask_len=0&sixrd_ipv6=2001:db8:c000:201::1
```

## REST API

`POST /api/v1/6rd` mirrors the UI. See the [REST API](api.md) page or
the OpenAPI spec at `/api/openapi.yaml` for the full request/response
schema.

```bash
curl -X POST https://example.com/api/v1/6rd \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"mode":"encode","sp_ipv6_prefix":"2001:db8::/32","sp_ipv4_mask_len":0,"ipv4":"192.0.2.1"}'
```

[rfc5969]: https://www.rfc-editor.org/rfc/rfc5969
