# Subnet Splitter

The subnet splitter divides a network into equal-sized sub-networks.

## IPv4

After calculating an IPv4 result, the **Split Subnet** panel appears. Enter the desired sub-prefix (e.g. `/26` to split a `/24` into four `/26s`) and click **Split**.

Results are displayed as a list of CIDRs with:

- A per-row copy button
- **Copy All** — copies all CIDRs as newline-separated text
- **Export ASCII** — builds a plaintext tree and copies it to the clipboard

The maximum number of results is controlled by `$split_max_subnets` in `config.php` (default `16`). When the total exceeds this limit a note is shown. The value is clamped to the range 1–256.

## IPv6

The IPv6 splitter works identically and appears after an IPv6 result. For splits where the prefix difference is ≥ 63, the count is displayed as `2^N` to avoid integer overflow.

## Export ASCII format

```text
10.0.0.0/24
├─ 10.0.0.0/26
├─ 10.0.0.64/26
├─ 10.0.0.128/26
└─ 10.0.0.192/26
```
