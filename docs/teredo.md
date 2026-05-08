# Teredo Address Decoder

Decode and encode IPv6 addresses in the `2001:0::/32` Teredo prefix
defined by [RFC 4380][rfc4380].

!!! warning "Legacy transition mechanism"
    Microsoft retired the public Teredo service for consumer Windows
    in 2019. Native IPv6 connectivity has displaced Teredo across the
    public internet. This tool is provided for analysis of historical
    traffic captures and audits of addresses that still appear in
    legacy logs.

## How Teredo addresses are structured

A Teredo address packs four pieces of information into 128 bits:

```
| 32 bits | 32 bits      | 16 bits | 16 bits      | 32 bits          |
| 2001:0  | server IPv4  | flags   | port (XOR)   | client IPv4 (XOR)|
```

- The **server IPv4** is the public address of the Teredo server that
  terminates the relay tunnel for the client. Stored raw, with no
  obfuscation, because it does not need to traverse a NAT in plain
  view.
- The **flags** field is a 16-bit value whose high bit (`0x8000`) is
  the **cone** indicator. Set to `0x8000` when the client is behind a
  cone NAT (any inbound packet from any source can reach the mapped
  port); cleared (`0x0000`) for restricted / port-restricted NATs.
- The **UDP port** is the client's mapped port at its NAT, XOR-ed with
  `0xFFFF` so that NAT devices on the wire don't rewrite it when they
  see what looks like a port number embedded in an IPv6 address.
- The **client IPv4** is the client's mapped public IPv4 address,
  XOR-ed byte-by-byte with `0xFF` for the same reason.

## RFC 4380 §4 worked example

```
server  = 65.54.227.120
client  = 192.0.2.45
port    = 40000
flags   = 0x8000   (cone NAT)

Teredo address: 2001:0:4136:e378:8000:63bf:3fff:fdd2
```

The decoder reverses the XOR obfuscation on the port and client v4 to
recover their plain values; the encoder applies it.

## Decoding (Teredo IPv6 → parts)

| Field        | Value                          |
| ------------ | ------------------------------ |
| Server IPv4  | `65.54.227.120` (raw)          |
| Flags        | `0x8000` (cone)                |
| UDP port     | `40000` (XOR'd `0x63bf`)       |
| Client IPv4  | `192.0.2.45` (XOR'd `3fff:fdd2`) |

The decoder rejects any address that does not start with the
`2001:0::/32` prefix. Use the
[Embedded IPv4 Detector](embedded-v4.md) as the front door if you do
not yet know whether an address is Teredo, 6to4, NAT64, ISATAP, or
something else.

## Encoding (parts → Teredo IPv6)

Provide the four parts; the encoder packs them into the canonical form:

- Server IPv4 → bytes 4–7 (raw).
- Flags → bytes 8–9 (big-endian uint16). Default `0x8000` (cone).
- Port XOR `0xFFFF` → bytes 10–11.
- Client IPv4 XOR `0xFF` byte-by-byte → bytes 12–15.

The encoder rejects malformed IPv4 addresses and out-of-range ports.

## REST API

`POST /api/v1/teredo` with one of two request bodies:

```json
{ "mode": "decode", "ipv6": "2001:0:4136:e378:8000:63bf:3fff:fdd2" }
```

```json
{
  "mode": "encode",
  "server_ipv4": "65.54.227.120",
  "client_ipv4": "192.0.2.45",
  "port": 40000,
  "flags": 32768
}
```

See [REST API → Teredo](api.md) and the OpenAPI spec for the complete
response schemas and error envelopes.

## Shareable URL

Direct-link to a pre-filled decode:

```
?tab=ipv6&tool=teredo&teredo_mode=decode&teredo_input=2001:0:4136:e378:8000:63bf:3fff:fdd2
```

Or an encode:

```
?tab=ipv6&tool=teredo&teredo_mode=encode&teredo_server=65.54.227.120&teredo_client=192.0.2.45&teredo_port=40000&teredo_cone=1
```

The `/ipv6/teredo` per-tool route accepts the same query parameters
and auto-opens the drawer with the result rendered.

[rfc4380]: https://datatracker.ietf.org/doc/html/rfc4380
