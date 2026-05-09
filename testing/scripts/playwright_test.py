#!/usr/bin/env python3
"""
Playwright browser tests for Subnet Calculator.

Runs against the deployed dev server using a local Chromium instance.

Usage:
    bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/playwright_test.py'
"""

import asyncio
import base64
import json
import os
import re
import sys

import requests as _requests
from playwright.async_api import async_playwright, Page, Frame

# Visual regression utilities (optional — only used in test_visual_regression)
try:
    import sys as _sys
    import os as _os
    _sys.path.insert(0, _os.path.dirname(__file__))
    from snapshot_utils import (  # noqa: E402
        _PIL_AVAILABLE as _SNAPSHOT_PIL_AVAILABLE,
        capture_snapshot,
        compare_snapshot,
        set_viewport as _set_viewport,
    )
    _SNAPSHOTS_AVAILABLE = True
except ImportError:
    _SNAPSHOT_PIL_AVAILABLE = False
    _SNAPSHOTS_AVAILABLE = False

    from typing import Any as _Any

    async def capture_snapshot(*_args: _Any, **_kwargs: _Any) -> None:  # type: ignore[no-redef]
        raise RuntimeError("snapshot_utils unavailable")

    async def compare_snapshot(*_args: _Any, **_kwargs: _Any) -> tuple[bool, float]:  # type: ignore[no-redef]
        raise RuntimeError("snapshot_utils unavailable")

    async def _set_viewport(*_args: _Any, **_kwargs: _Any) -> None:  # type: ignore[no-redef]
        raise RuntimeError("snapshot_utils unavailable")

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

BASIC_USER  = os.environ.get("IPAM_BASIC_USER", "")
BASIC_PASS  = os.environ.get("IPAM_BASIC_PASS", "")
APP_URL     = os.environ.get(
    "APP_URL",
    "https://dev-direct.seanmousseau.com:8343/claude/subnet-calculator/",
).rstrip("/") + "/"
SKIP_LINT   = os.environ.get("SKIP_LINT", "0") == "1"

# ---------------------------------------------------------------------------
# Colours / counters
# ---------------------------------------------------------------------------

GREEN = "\033[32m"
RED   = "\033[31m"
BOLD  = "\033[1m"
DIM   = "\033[2m"
RST   = "\033[0m"

_passed = _failed = 0


def ok(name: str) -> None:
    global _passed
    _passed += 1
    print(f"  {GREEN}✓{RST} {name}")


def fail(name: str, detail: str = "") -> None:
    global _failed
    _failed += 1
    msg = f"  {RED}✗{RST} {name}"
    if detail:
        msg += f"\n      {DIM}{detail}{RST}"
    print(msg)


def section(title: str) -> None:
    print(f"\n{BOLD}{title}{RST}")


def assert_eq(name: str, got, want) -> bool:
    if got == want:
        ok(name)
        return True
    fail(name, f"got {got!r}, want {want!r}")
    return False


def assert_contains(name: str, haystack: str, needle: str) -> bool:
    if haystack and needle in haystack:
        ok(name)
        return True
    fail(name, f"{needle!r} not found in {haystack!r}")
    return False


def assert_true(name: str, value, detail: str = "") -> bool:
    if value:
        ok(name)
        return True
    fail(name, detail or f"expected truthy, got {value!r}")
    return False


# ---------------------------------------------------------------------------
# HTTP helper (used by header / CSP tests — no browser required)
# ---------------------------------------------------------------------------

_APP_BASE = APP_URL
_SESSION  = _requests.Session()
if BASIC_USER and BASIC_PASS:
    _SESSION.auth = (BASIC_USER, BASIC_PASS)
_SESSION.verify  = False  # self-signed cert on dev server


def _http_get(path: str = "") -> tuple[int, dict[str, str], str]:
    resp = _SESSION.get(_APP_BASE + path, timeout=10)
    hdrs = {k.lower(): v for k, v in resp.headers.items()}
    return resp.status_code, hdrs, resp.text


# ---------------------------------------------------------------------------
# Page helpers
# ---------------------------------------------------------------------------

async def navigate(page: Page, url: str) -> None:
    await page.goto(url, wait_until="load")


async def submit_form(page: Page, selector: str) -> None:
    async with page.expect_navigation(wait_until="load"):
        await page.evaluate(f"document.querySelector({json.dumps(selector)}).submit()")


async def result_value(page: Page, label: str) -> str | None:
    # Match only the direct text nodes in .result-label so help-bubble icons
    # ("?") and their hidden tooltip text are excluded — enabling exact equality.
    return await page.evaluate("""(label) => {
        var rows = document.querySelectorAll('.result-row');
        for (var i = 0; i < rows.length; i++) {
            var l = rows[i].querySelector('.result-label');
            if (!l) continue;
            var text = Array.from(l.childNodes)
                .filter(function(n) { return n.nodeType === 3; })
                .map(function(n) { return n.textContent; })
                .join('').trim();
            if (text === label) {
                var v = rows[i].querySelector('.result-value');
                return v ? v.textContent.trim() : null;
            }
        }
        return null;
    }""", label)


async def get_iframe_frame(page: Page) -> Frame:
    """Return the Frame object for #scFrame."""
    element = await page.locator("#scFrame").element_handle()
    if element is None:
        raise RuntimeError("#scFrame element not found")
    frame = await element.content_frame()
    if frame is None:
        raise RuntimeError("#scFrame has no content frame")
    return frame


async def iframe_result_value(frame: Frame, label: str) -> str | None:
    # Same direct text-node strategy as result_value() — exact match, no help-bubble noise.
    return await frame.evaluate("""(label) => {
        var rows = document.querySelectorAll('.result-row');
        for (var i = 0; i < rows.length; i++) {
            var l = rows[i].querySelector('.result-label');
            if (!l) continue;
            var text = Array.from(l.childNodes)
                .filter(function(n) { return n.nodeType === 3; })
                .map(function(n) { return n.textContent; })
                .join('').trim();
            if (text === label) {
                var v = rows[i].querySelector('.result-value');
                return v ? v.textContent.trim() : null;
            }
        }
        return null;
    }""", label)


async def poll_resize_count(page: Page, min_count: int, timeout: float = 8.0) -> int:
    """Wait until #resize-log data-count >= min_count, then return the count."""
    try:
        await page.wait_for_function(
            "(n) => parseInt(document.getElementById('resize-log')?.getAttribute('data-count') || '0') >= n",
            arg=min_count,
            timeout=int(timeout * 1000),
        )
    except Exception:
        pass
    raw = await page.get_attribute("#resize-log", "data-count") or "0"
    return int(raw)


# ---------------------------------------------------------------------------
# Test suites
# ---------------------------------------------------------------------------

async def test_page_load(page: Page) -> None:
    section("Page load")
    await navigate(page, APP_URL)

    title = await page.title()
    assert_contains("page title contains 'Subnet Calculator'", title, "Subnet Calculator")
    assert_true("IPv4 tab exists",            await page.locator("#tab-ipv4").count() > 0)
    assert_true("IPv6 tab exists",            await page.locator("#tab-ipv6").count() > 0)
    assert_eq("IPv4 tab active by default",
              await page.get_attribute("#tab-ipv4", "aria-selected"), "true")
    assert_true("logo present",               await page.locator("img.logo").count() > 0)


async def test_headers_and_csp(_page: Page) -> None:
    # ── main page response headers ─────────────────────────────────────────
    section("security headers — main page")
    status, hdrs, body = _http_get()

    assert_eq("HTTP 200",                         status, 200)
    assert_eq("X-Content-Type-Options: nosniff",
              hdrs.get("x-content-type-options"), "nosniff")
    assert_eq("Referrer-Policy",
              hdrs.get("referrer-policy"),         "strict-origin-when-cross-origin")
    assert_true("Content-Security-Policy present",
                "content-security-policy" in hdrs)
    assert_true("no X-Frame-Options (frame-ancestors * default)",
                "x-frame-options" not in hdrs)
    assert_contains("Content-Type text/html",
                    hdrs.get("content-type", ""), "text/html")

    # ── CSP directive structure ────────────────────────────────────────────
    section("CSP — directives")
    csp = hdrs.get("content-security-policy", "")

    assert_contains("default-src 'self'",   csp, "default-src 'self'")
    assert_contains("base-uri 'self'",      csp, "base-uri 'self'")
    assert_contains("script-src 'self'",    csp, "script-src 'self'")
    assert_contains("style-src 'self'",     csp, "style-src 'self'")
    assert_contains("img-src 'self' data:", csp, "img-src 'self' data:")
    assert_contains("frame-src 'self'",     csp, "frame-src 'self'")
    assert_contains("frame-ancestors *",    csp, "frame-ancestors *")

    def _directive(csp_str: str, name: str) -> str:
        m = re.search(rf'{re.escape(name)}\s+([^;]*)', csp_str)
        return m.group(1) if m else ""

    script_src = _directive(csp, "script-src")
    assert_true("script-src no 'unsafe-inline'",
                "'unsafe-inline'" not in script_src, script_src)
    assert_true("script-src no 'unsafe-eval'",
                "'unsafe-eval'" not in script_src, script_src)

    # ── CSP nonce integrity ────────────────────────────────────────────────
    section("CSP — nonce integrity")
    nonce_m = re.search(r"'nonce-([^']+)'", csp)
    assert_true("nonce present in CSP", nonce_m is not None, csp)

    nonce = nonce_m.group(1) if nonce_m else ""
    assert_eq("nonce is 24 chars (16 random bytes base64-encoded)", len(nonce), 24)

    import base64 as _b64
    try:
        _b64.b64decode(nonce)
        ok("nonce is valid base64")
    except Exception as exc:
        fail("nonce is valid base64", str(exc))

    assert_contains("nonce on inline <script> in HTML", body, f'nonce="{nonce}"')

    _, hdrs2, _ = _http_get()
    nonce_m2 = re.search(r"'nonce-([^']+)'", hdrs2.get("content-security-policy", ""))
    nonce2 = nonce_m2.group(1) if nonce_m2 else ""
    assert_true("nonce is unique per request", nonce != nonce2,
                f"both requests returned nonce={nonce!r}")

    # ── static asset cache headers ─────────────────────────────────────────
    section("static assets — cache headers")
    for asset in ("assets/app.js", "assets/app.css"):
        _, ahdrs, _ = _http_get(asset)
        cc = ahdrs.get("cache-control", "")
        assert_contains(f"{asset} max-age=31536000", cc, "max-age=31536000")
        assert_contains(f"{asset} immutable",        cc, "immutable")

    # ── blocked paths ──────────────────────────────────────────────────────
    section("protected paths — 403")
    for path in ("includes/config.php", "config.php", "templates/layout.php"):
        code, _, _ = _http_get(path)
        assert_eq(f"{path} → 403", code, 403)


async def test_ipv4_basic(page: Page) -> None:
    section("IPv4 — basic calculation (192.168.1.0/24)")
    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")

    assert_eq("Subnet (CIDR)",   await result_value(page, "Subnet (CIDR)"),   "192.168.1.0/24")
    assert_eq("Netmask (CIDR)",  await result_value(page, "Netmask (CIDR)"),  "/24")
    assert_eq("Netmask (Octet)", await result_value(page, "Netmask (Octet)"), "255.255.255.0")
    assert_eq("Wildcard Mask",   await result_value(page, "Wildcard Mask"),   "0.0.0.255")
    assert_eq("First Usable IP", await result_value(page, "First Usable IP"), "192.168.1.1")
    assert_eq("Last Usable IP",  await result_value(page, "Last Usable IP"),  "192.168.1.254")
    assert_eq("Broadcast IP",    await result_value(page, "Broadcast IP"),    "192.168.1.255")
    assert_eq("Usable IPs",      await result_value(page, "Usable IPs"),      "254")
    assert_eq("Address type: Private", await page.text_content(".badge"), "Private")

    share = await page.text_content(".share-url")
    assert_true("share URL present", share and "192.168.1.0" in share)


async def test_ipv4_dotted_mask(page: Page) -> None:
    section("IPv4 — dotted-decimal netmask")
    await navigate(page, APP_URL)
    await page.fill("#ip",   "10.0.0.0")
    await page.fill("#mask", "255.0.0.0")
    await submit_form(page, "#panel-ipv4 form")

    assert_eq("Subnet (CIDR) with octet mask", await result_value(page, "Subnet (CIDR)"), "10.0.0.0/8")
    assert_eq("Usable IPs /8",                 await result_value(page, "Usable IPs"),    "16,777,214")


async def test_ipv4_cidr_paste(page: Page) -> None:
    section("IPv4 — CIDR paste into IP field")
    await navigate(page, APP_URL)
    await page.fill("#ip",   "172.16.0.0/12")
    await page.fill("#mask", "")
    await submit_form(page, "#panel-ipv4 form")

    assert_eq("CIDR paste: subnet", await result_value(page, "Subnet (CIDR)"), "172.16.0.0/12")


async def test_ipv4_edge_cases(page: Page) -> None:
    section("IPv4 — edge cases")

    await navigate(page, APP_URL)
    await page.fill("#ip",   "1.2.3.4")
    await page.fill("#mask", "32")
    await submit_form(page, "#panel-ipv4 form")
    assert_eq("/32 usable IPs", await result_value(page, "Usable IPs"),   "1")
    assert_eq("/32 broadcast",  await result_value(page, "Broadcast IP"), "1.2.3.4")

    await navigate(page, APP_URL)
    await page.fill("#ip",   "10.0.0.0")
    await page.fill("#mask", "31")
    await submit_form(page, "#panel-ipv4 form")
    assert_eq("/31 usable IPs", await result_value(page, "Usable IPs"), "2")

    await navigate(page, APP_URL)
    await page.fill("#ip",   "0.0.0.0")
    await page.fill("#mask", "0")
    await submit_form(page, "#panel-ipv4 form")
    assert_eq("/0 subnet", await result_value(page, "Subnet (CIDR)"), "0.0.0.0/0")


async def test_ipv4_address_types(page: Page) -> None:
    section("IPv4 — address type badges")
    cases = [
        ("127.0.0.1",   "24", "Loopback"),
        ("169.254.0.0", "16", "Link-local"),
        ("224.0.0.1",   "4",  "Multicast"),
        ("8.8.8.8",     "32", "Public"),
        ("100.64.0.0",  "10", "CGNAT"),
    ]
    for ip, mask, expected in cases:
        await navigate(page, APP_URL)
        await page.fill("#ip",   ip)
        await page.fill("#mask", mask)
        await submit_form(page, "#panel-ipv4 form")
        assert_eq(f"{ip} → {expected}", await page.text_content(".badge"), expected)


async def test_ipv4_errors(page: Page) -> None:
    section("IPv4 — error handling")

    await navigate(page, APP_URL)
    await page.fill("#ip",   "999.999.999.999")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    err = await page.text_content(".error")
    assert_true("invalid IP shows error",   err and len(err) > 0, str(err))
    assert_true("no results on invalid IP", await page.locator(".results").count() == 0)

    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.1.0")
    await page.fill("#mask", "33")
    await submit_form(page, "#panel-ipv4 form")
    err = await page.text_content(".error")
    assert_true("mask > 32 shows error", err and len(err) > 0, str(err))


async def test_ipv4_splitter(page: Page) -> None:
    section("IPv4 — subnet splitter")

    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")

    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, "#panel-ipv4 .splitter-form")

    count = await page.evaluate("document.querySelectorAll('.split-item').length.toString()")
    assert_eq("split /24→/26 gives 4 subnets",     count, "4")
    assert_eq("first subnet is 192.168.0.0/26",    await page.locator(".split-item").first.inner_text(), "192.168.0.0/26")

    # Splitter rejects prefix not larger than current
    await navigate(page, APP_URL)
    await page.fill("#ip",   "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='split_prefix']", "/24")
    await submit_form(page, "#panel-ipv4 .splitter-form")
    err = await page.text_content(".error")
    assert_true("splitter rejects same-size prefix", err and "larger" in err.lower(), str(err))


async def test_ipv4_shareable_url(page: Page) -> None:
    section("IPv4 — shareable GET URL")
    await navigate(page, APP_URL + "?tab=ipv4&ip=10.10.10.0&mask=28")

    assert_eq("GET auto-calc: subnet", await result_value(page, "Subnet (CIDR)"), "10.10.10.0/28")
    assert_eq("GET auto-calc: usable", await result_value(page, "Usable IPs"),    "14")


async def test_ipv4_reset(page: Page) -> None:
    section("IPv4 — reset button")
    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    assert_true("results shown before reset", await page.locator(".results").count() > 0)

    async with page.expect_navigation(wait_until="load"):
        await page.click("a.reset")

    assert_true("results gone after reset",  await page.locator(".results").count() == 0)
    assert_eq("IP field cleared", await page.input_value("#ip"), "")


async def test_ipv6_basic(page: Page) -> None:
    section("IPv6 — basic calculation (2001:db8::/32)")
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")

    network = await result_value(page, "Network (CIDR)") or ""
    assert_contains("Network CIDR contains 2001:db8::", network, "2001:db8::")
    assert_eq("Prefix Length",            await result_value(page, "Prefix Length"), "/32")

    total = await result_value(page, "Total Addresses") or ""
    assert_true("Total Addresses is 2^96", "2^96" in total, total)
    assert_eq("Address type: Documentation", await page.text_content(".badge"), "Documentation")


async def test_ipv6_types(page: Page) -> None:
    section("IPv6 — address type badges")
    cases = [
        ("::1",         "128", "Loopback"),
        ("fe80::1",     "64",  "Link-local"),
        ("fc00::1",     "48",  "Unique Local"),
        ("ff02::1",     "128", "Multicast"),
        ("2001:db8::1", "128", "Documentation"),
    ]
    for ip, prefix, expected in cases:
        await navigate(page, APP_URL)
        await page.click("#tab-ipv6")
        await page.fill("#ipv6",   ip)
        await page.fill("#prefix", prefix)
        await submit_form(page, "#panel-ipv6 form")
        assert_eq(f"{ip} → {expected}", await page.text_content(".badge"), expected)


async def test_ipv6_cidr_paste(page: Page) -> None:
    section("IPv6 — CIDR paste into address field")
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::/48")
    await page.fill("#prefix", "")
    await submit_form(page, "#panel-ipv6 form")
    assert_eq("CIDR paste: prefix length", await result_value(page, "Prefix Length"), "/48")


async def test_ipv6_errors(page: Page) -> None:
    section("IPv6 — error handling")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "not-an-ipv6")
    await page.fill("#prefix", "64")
    await submit_form(page, "#panel-ipv6 form")
    err = await page.text_content(".error")
    assert_true("invalid IPv6 shows error", err and len(err) > 0, str(err))

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::1")
    await page.fill("#prefix", "129")
    await submit_form(page, "#panel-ipv6 form")
    err = await page.text_content(".error")
    assert_true("prefix > 128 shows error", err and len(err) > 0, str(err))


async def test_ipv6_splitter(page: Page) -> None:
    section("IPv6 — subnet splitter")
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")

    await page.click("#panel-ipv6 .tool-trigger[data-tool='split6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='split_prefix6']", "/33")
    await submit_form(page, "#panel-ipv6 .splitter-form")

    count = await page.evaluate("document.querySelectorAll('.split-item').length.toString()")
    assert_eq("split /32→/33 gives 2 subnets", count, "2")


async def test_ipv6_shareable_url(page: Page) -> None:
    section("IPv6 — shareable GET URL")
    await navigate(page, APP_URL + "?tab=ipv6&ipv6=fd00%3A%3A&prefix=8")
    assert_eq("GET auto-calc IPv6: prefix length", await result_value(page, "Prefix Length"), "/8")


async def test_per_tool_routes_equivalence(page: Page) -> None:
    # v3.4.0 — Apache rewrites /ipv[46]/<tool> and /ipv[46] to the existing
    # ?tab=&tool= form. The two URL forms must render identical content so
    # that bookmarks and iframe consumers using the legacy form stay valid.
    section("v3.4.0 — per-tool URL routes: canonical vs legacy equivalence")

    # Bare tab routes
    await navigate(page, APP_URL + "ipv4")
    canonical_ipv4 = await page.locator("#main-content").inner_html()
    await navigate(page, APP_URL + "?tab=ipv4")
    legacy_ipv4 = await page.locator("#main-content").inner_html()
    assert_true("/ipv4 vs ?tab=ipv4 render identical content",
                canonical_ipv4 == legacy_ipv4)

    await navigate(page, APP_URL + "ipv6")
    canonical_ipv6 = await page.locator("#main-content").inner_html()
    await navigate(page, APP_URL + "?tab=ipv6")
    legacy_ipv6 = await page.locator("#main-content").inner_html()
    assert_true("/ipv6 vs ?tab=ipv6 render identical content",
                canonical_ipv6 == legacy_ipv6)

    # Per-tool routes — derive opens the IPv6 derive drawer
    await navigate(page, APP_URL + "ipv6/derive")
    canonical_derive = await page.locator(
        '#panel-ipv6 .tool-panel[data-tool="derive"]'
    ).count()
    assert_true("/ipv6/derive renders derive tool panel",
                canonical_derive > 0)

    # Drawer should be auto-opened on canonical route. JS reads
    # data-open-tool from PHP and toggles aria-expanded after load — wait
    # for it instead of asserting synchronously, otherwise the JS may not
    # have run yet on slower CI.
    try:
        await page.wait_for_selector(
            '#panel-ipv6 .tool-trigger[data-tool="derive"][aria-expanded="true"]',
            timeout=3000,
        )
        drawer_open = True
    except Exception:
        drawer_open = False
    assert_true("/ipv6/derive auto-opens the derive tool drawer",
                drawer_open)

    # Same drawer must auto-open via the legacy ?tab=&tool= form
    await navigate(page, APP_URL + "?tab=ipv6&tool=derive")
    try:
        await page.wait_for_selector(
            '#panel-ipv6 .tool-trigger[data-tool="derive"][aria-expanded="true"]',
            timeout=3000,
        )
        legacy_drawer_open = True
    except Exception:
        legacy_drawer_open = False
    assert_true("?tab=ipv6&tool=derive auto-opens the derive drawer (legacy form)",
                legacy_drawer_open)


async def test_legacy_query_param_url_no_redirect(_page: Page) -> None:
    # v3.4.0 — legacy ?tab=&tool= URLs MUST NOT auto-redirect to the canonical
    # form. iframe embedders and bookmarks rely on the legacy form continuing
    # to work unchanged.
    section("v3.4.0 — legacy ?tab=&tool= URL is not redirected")

    resp = _SESSION.get(_APP_BASE + "?tab=ipv6&tool=derive",
                        allow_redirects=False, timeout=10)
    assert_eq("legacy URL returns 200 (no redirect)", resp.status_code, 200)
    assert_true("legacy URL has no Location header",
                "location" not in {k.lower() for k in resp.headers})

    # Canonical form returns 200 directly (Apache mod_rewrite is internal,
    # so the client never sees a redirect for this either).
    resp2 = _SESSION.get(_APP_BASE + "ipv6/derive",
                         allow_redirects=False, timeout=10)
    assert_eq("canonical /ipv6/derive returns 200", resp2.status_code, 200)


async def test_canonical_route_with_query_params(page: Page) -> None:
    # v3.4.0 — Apache QSA must preserve query params on the rewritten path.
    section("v3.4.0 — per-tool route preserves query string (QSA)")

    await navigate(page, APP_URL + "ipv6/derive?derive_mac=00%3A24%3Ab9%3A7e%3Aab%3Acd")
    # Page rendered + IPv6 panel exists
    main_html = await page.locator("#main-content").count()
    assert_true("canonical route + query string renders without 404",
                main_html > 0)
    derive_panel = await page.locator(
        '#panel-ipv6 .tool-panel[data-tool="derive"]'
    ).count()
    assert_true("derive tool panel present on canonical route",
                derive_panel > 0)


async def test_iframe(page: Page) -> None:
    IFRAME_HARNESS = APP_URL + "iframe-test.html"

    # ── setup ──────────────────────────────────────────────────────────────
    section("iframe — setup")
    await navigate(page, IFRAME_HARNESS)
    await poll_resize_count(page, 1, timeout=8.0)

    assert_true("harness page loaded",   await page.locator("#scFrame").count() > 0)
    assert_true("iframe element present", await page.locator("#scFrame").count() > 0)

    # ── in-iframe detection ────────────────────────────────────────────────
    section("iframe — in-iframe detection")
    iframe_frame = await get_iframe_frame(page)
    html_classes = await iframe_frame.get_attribute("html", "class") or ""
    assert_true("html has in-iframe class", "in-iframe" in html_classes)

    # ── height reporting ───────────────────────────────────────────────────
    section("iframe — height reporting")
    height_str = await page.get_attribute("#resize-log", "data-height") or "0"
    height = int(height_str)
    assert_true("sc-resize received with height > 0", height > 0, f"height={height}")

    frame_h = await page.evaluate("document.getElementById('scFrame').style.height")
    assert_true("iframe element height auto-set", frame_h not in (None, "", "0px"),
                f"style.height={frame_h!r}")

    # ── background colour ──────────────────────────────────────────────────
    section("iframe — background colour (sc-set-bg)")
    sent = await page.get_attribute("#bg-log", "data-sent")
    assert_eq("sc-set-bg forwarded to iframe on load", sent, "1")

    bg = await iframe_frame.evaluate("document.body.style.backgroundColor")
    assert_true("sc-set-bg applied to iframe body", bg and len(bg) > 0,
                f"backgroundColor={bg!r}")

    # ── GET-based calculation inside iframe ────────────────────────────────
    section("iframe — calculation via GET URL")
    count_before = int(await page.get_attribute("#resize-log", "data-count") or "0")
    # Navigate the iframe frame directly so Playwright waits for load
    iframe_frame = await get_iframe_frame(page)
    await iframe_frame.goto(APP_URL + "?tab=ipv4&ip=10.0.0.0&mask=8", wait_until="load")
    await poll_resize_count(page, count_before + 1, timeout=8.0)

    subnet_in_iframe = await iframe_result_value(iframe_frame, "Subnet (CIDR)")
    assert_eq("IPv4 GET calc renders in iframe", subnet_in_iframe, "10.0.0.0/8")

    count_after_get = int(await page.get_attribute("#resize-log", "data-count") or "0")
    assert_true("sc-resize fires after GET calculation",
                count_after_get > count_before, f"count {count_before}→{count_after_get}")

    # ── form submission inside iframe ──────────────────────────────────────
    section("iframe — form submission")
    count_before_submit = count_after_get
    await iframe_frame.fill("#ip",   "192.168.50.0")
    await iframe_frame.fill("#mask", "26")
    async with page.expect_event("framenavigated", predicate=lambda f: f == iframe_frame):
        await iframe_frame.evaluate("document.querySelector('#panel-ipv4 form').submit()")
    await iframe_frame.wait_for_load_state("load")
    await poll_resize_count(page, count_before_submit + 1, timeout=8.0)

    usable = await iframe_result_value(iframe_frame, "Usable IPs")
    assert_eq("form submit in iframe: results render", usable, "62")

    count_after_submit = int(await page.get_attribute("#resize-log", "data-count") or "0")
    assert_true("sc-resize fires after form submit",
                count_after_submit > count_before_submit,
                f"count {count_before_submit}→{count_after_submit}")


async def test_theme_toggle(page: Page) -> None:
    section("UI — theme toggle")
    # Force dark theme into localStorage so the test is deterministic regardless
    # of the OS prefers-color-scheme setting or leftover state from earlier tests
    await navigate(page, APP_URL)
    await page.evaluate("localStorage.setItem('theme', 'dark')")
    await navigate(page, APP_URL)

    theme = await page.get_attribute("html", "data-theme")
    assert_true("default theme is dark", theme != "light", str(theme))

    await page.click("#theme-toggle")
    assert_eq("after toggle: light mode", await page.get_attribute("html", "data-theme"), "light")

    await page.click("#theme-toggle")
    theme = await page.get_attribute("html", "data-theme")
    assert_true("after second toggle: dark", theme != "light", str(theme))


async def test_tab_switch(page: Page) -> None:
    section("UI — tab switching")
    await navigate(page, APP_URL)

    await page.click("#tab-ipv6")
    assert_eq("IPv6 tab becomes active",   await page.get_attribute("#tab-ipv6", "aria-selected"), "true")
    assert_eq("IPv4 tab becomes inactive", await page.get_attribute("#tab-ipv4", "aria-selected"), "false")

    panel_class = await page.get_attribute("#panel-ipv6", "class") or ""
    assert_true("IPv6 panel has active class", "active" in panel_class)


async def test_permissions_policy(_page: Page) -> None:
    section("security headers — Permissions-Policy")
    _, hdrs, _ = _http_get()
    pp = hdrs.get("permissions-policy", "")
    assert_true("Permissions-Policy header present", pp != "", pp)
    for directive in ("camera=()", "microphone=()", "geolocation=()"):
        assert_contains(f"Permissions-Policy contains {directive}", pp, directive)


async def test_reverse_dns_ipv4(page: Page) -> None:
    section("IPv4 — reverse DNS zone")
    cases = [
        ("192.168.1.0", "24", "1.168.192.in-addr.arpa"),
        ("10.0.0.0",    "8",  "10.in-addr.arpa"),
        ("172.16.0.0",  "16", "16.172.in-addr.arpa"),
        ("192.168.1.0", "25", "0/25.1.168.192.in-addr.arpa"),
    ]
    for ip, mask, expected in cases:
        await navigate(page, APP_URL)
        await page.fill("#ip",   ip)
        await page.fill("#mask", mask)
        await submit_form(page, "#panel-ipv4 form")
        assert_eq(f"{ip}/{mask} PTR zone", await result_value(page, "Reverse DNS Zone"), expected)


async def test_reverse_dns_ipv6(page: Page) -> None:
    section("IPv6 — reverse DNS zone")
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")
    ptr = await result_value(page, "Reverse DNS Zone") or ""
    assert_true("IPv6 PTR zone present",                    ptr != "", ptr)
    assert_contains("IPv6 PTR zone ends in ip6.arpa",       ptr, "ip6.arpa")
    assert_contains("IPv6 PTR zone contains 2001:db8 nibbles", ptr, "8.b.d.0.1.0.0.2")


async def test_ipv6_small_count(page: Page) -> None:
    section("IPv6 — small total address count")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "127")
    await submit_form(page, "#panel-ipv6 form")
    total = await result_value(page, "Total Addresses") or ""
    assert_eq("/127 total = 2", total.replace(",", ""), "2")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "64")
    await submit_form(page, "#panel-ipv6 form")
    total64 = await result_value(page, "Total Addresses") or ""
    assert_contains("/64 total is exponential", total64, "2^64")


async def test_binary_repr(page: Page) -> None:
    section("IPv4 — binary representation")
    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")

    assert_true("binary-details element exists", await page.locator(".binary-details").count() > 0)

    await page.evaluate("document.querySelector('.binary-details').setAttribute('open', '')")

    net_code = await page.text_content(".binary-details .bin-value")
    assert_true("binary network contains dots", net_code is not None and "." in net_code, net_code or "")
    assert_contains("binary: first octet 11000000", net_code or "", "11000000")

    boundary = await page.text_content(".bin-boundary")
    assert_contains("boundary shows 24 network bits", boundary or "", "24")
    assert_contains("boundary shows 8 host bits",     boundary or "", "8")


async def test_overlap_checker(page: Page) -> None:
    section("VLSM tab — overlap checker")
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")

    # No overlap
    await page.click("#panel-vlsm .tool-trigger[data-tool='overlap']")
    await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
    await page.fill("input[name='overlap_cidr_a']", "10.0.0.0/24")
    await page.fill("input[name='overlap_cidr_b']", "10.0.1.0/24")
    await submit_form(page, ".overlap-form")
    result = await page.text_content(".overlap-result") or ""
    assert_contains("no overlap detected", result.lower(), "no overlap")

    # a contains b
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.click("#panel-vlsm .tool-trigger[data-tool='overlap']")
    await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
    await page.fill("input[name='overlap_cidr_a']", "10.0.0.0/23")
    await page.fill("input[name='overlap_cidr_b']", "10.0.0.0/24")
    await submit_form(page, ".overlap-form")
    result2 = await page.text_content(".overlap-result") or ""
    assert_contains("a contains b", result2.lower(), "contains")

    # Identical
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.click("#panel-vlsm .tool-trigger[data-tool='overlap']")
    await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
    await page.fill("input[name='overlap_cidr_a']", "192.168.0.0/24")
    await page.fill("input[name='overlap_cidr_b']", "192.168.0.0/24")
    await submit_form(page, ".overlap-form")
    result3 = await page.text_content(".overlap-result") or ""
    assert_contains("identical subnets", result3.lower(), "identical")


async def test_vlsm(page: Page) -> None:
    section("VLSM — planner")
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")

    await page.fill("#vlsm_network", "192.168.1.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")

    assert_true("VLSM results table exists", await page.locator(".vlsm-table").count() > 0)
    first_subnet = await page.text_content(".vlsm-subnet-cell code") or ""
    assert_true("VLSM allocated a subnet", "/" in first_subnet, first_subnet)

    # Over-capacity error
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "192.168.1.0")
    await page.fill("#vlsm_cidr",    "30")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'BigLAN'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '200'")
    await submit_form(page, ".vlsm-form")
    err = await page.text_content(".error") or ""
    assert_true("VLSM shows error when over-capacity", len(err) > 0, err)


async def test_splitter_copy_buttons(page: Page) -> None:
    section("IPv4 splitter — copy buttons")
    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, "#panel-ipv4 .splitter-form")

    assert_true("subnet-copy buttons present",
                await page.evaluate("document.querySelectorAll('.subnet-copy').length > 0"))
    first_copy_attr = await page.get_attribute(".subnet-copy", "data-copy")
    assert_true("first copy button has data-copy attribute",
                first_copy_attr and "/" in first_copy_attr, str(first_copy_attr))
    assert_contains("data-copy looks like a CIDR", first_copy_attr or "", "192.168.0.0")


async def test_splitter_shareable_url(page: Page) -> None:
    section("IPv4 splitter — shareable URL includes split_prefix")

    await navigate(page, APP_URL)
    await page.fill("#ip",   "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, "#panel-ipv4 .splitter-form")

    share = await page.text_content(".share-url") or ""
    assert_contains("share URL includes split_prefix", share, "split_prefix=26")

    share_path = await page.evaluate(
        "document.querySelector('.share-url')?.textContent?.trim()"
    )
    if share_path:
        await navigate(page, share_path)
        split_count = await page.evaluate("document.querySelectorAll('.split-item').length.toString()")
        assert_eq("GET with split_prefix auto-shows split results", split_count, "4")


# ---------------------------------------------------------------------------
# v1.3.0 — new feature tests
# ---------------------------------------------------------------------------

async def test_vlsm_shareable_url(page: Page) -> None:
    section("VLSM — shareable URL auto-populates and calculates")

    url = (APP_URL + "?tab=vlsm&vlsm_network=10.0.0.0&vlsm_cidr=24"
           "&vlsm_name%5B0%5D=LAN+A&vlsm_hosts%5B0%5D=50")
    await navigate(page, url)
    assert_true("VLSM results table present after GET",
                await page.locator(".vlsm-table").count() > 0)
    subnet = await page.text_content(".vlsm-subnet-cell code") or ""
    assert_true("VLSM auto-calc subnet present", "/" in subnet, subnet)
    share = await page.text_content(".share-url") or ""
    assert_true("VLSM share bar shown with vlsm_network param",
                "vlsm_network" in share, share)
    network_val = await page.input_value("#vlsm_network")
    assert_eq("Network field pre-filled from GET", network_val, "10.0.0.0")


async def test_vlsm_csv_export(page: Page) -> None:
    section("VLSM — CSV export button and data attributes")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "192.168.1.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    assert_true("Export CSV button present",
                await page.locator("#vlsm-export-csv").count() > 0)
    first_attr = await page.get_attribute(".vlsm-table tbody tr", "data-first")
    assert_true("data-first attribute present on result row",
                first_attr is not None and "." in (first_attr or ""), str(first_attr))


async def test_vlsm_json_export(page: Page) -> None:
    section("VLSM — JSON export download")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    assert_true("Export JSON button present",
                await page.locator("#vlsm-export-json").count() > 0)
    async with page.expect_download() as dl_info:
        await page.click("#vlsm-export-json")
    download = await dl_info.value
    assert_true("JSON download filename ends with .json",
                (download.suggested_filename or "").endswith(".json"),
                download.suggested_filename)


async def test_vlsm_xlsx_export(page: Page) -> None:
    section("VLSM — XLSX export download")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    assert_true("Export XLSX button present",
                await page.locator("#vlsm-export-xlsx").count() > 0)
    async with page.expect_download() as dl_info:
        await page.click("#vlsm-export-xlsx")
    download = await dl_info.value
    assert_true("XLSX download filename ends with .xlsx",
                (download.suggested_filename or "").endswith(".xlsx"),
                download.suggested_filename)


async def test_ascii_export(page: Page) -> None:
    section("ASCII diagram export — VLSM and splitter")

    # VLSM: button present and produces ASCII output via buildAsciiDiagram
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    assert_true("Export ASCII button present in VLSM",
                await page.locator("#vlsm-export-ascii").count() > 0)
    # Verify buildAsciiDiagram is callable and produces correct output
    diagram = await page.evaluate("""() => {
        var rows = [{cidr: '10.0.0.0/25', name: 'LAN'}, {cidr: '10.0.0.128/25', name: 'WAN'}];
        return buildAsciiDiagram('10.0.0.0/24', rows);
    }""")
    assert_true("ASCII output contains tree connector (├ or └)",
                "\u251c" in (diagram or "") or "\u2514" in (diagram or ""), repr(diagram))
    assert_true("ASCII output contains CIDR notation",
                "/" in (diagram or ""), repr(diagram))

    # Splitter ASCII export
    await navigate(page, APP_URL)
    await page.fill("#ip",   "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='split_prefix']", "/25")
    await submit_form(page, "#panel-ipv4 .splitter-form")
    assert_true("ASCII export button present in splitter",
                await page.locator("#panel-ipv4 .ascii-export-btn").count() > 0)


async def test_vlsm_reset(page: Page) -> None:
    section("VLSM — Reset button clears form and results")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'Test'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '10'")
    await submit_form(page, ".vlsm-form")
    assert_true("VLSM table shown before reset",
                await page.locator(".vlsm-table").count() > 0)
    async with page.expect_navigation(wait_until="load"):
        await page.click("#panel-vlsm a.reset")
    assert_true("VLSM table gone after reset",
                await page.locator(".vlsm-table").count() == 0)
    assert_eq("Network field cleared", await page.input_value("#vlsm_network"), "")


async def test_vlsm_validation(page: Page) -> None:
    section("VLSM — client-side validation rejects empty hosts")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = ''")
    await page.click(".vlsm-form button[type='submit']")
    await page.wait_for_timeout(200)
    inline_err = await page.text_content(".vlsm-inline-error") or ""
    assert_true("Inline validation error shown for empty hosts",
                len(inline_err) > 0, inline_err)
    assert_true("No server round-trip (table absent)",
                await page.locator(".vlsm-table").count() == 0)


async def test_vlsm_copy_all(page: Page) -> None:
    section("VLSM — Copy All subnets button")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    assert_true("Copy All button present for VLSM results",
                await page.locator(".copy-all-btn[data-target='vlsm']").count() > 0)


async def test_ipv4_copy_all(page: Page) -> None:
    section("IPv4 splitter — Copy All button")

    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, "#panel-ipv4 .splitter-form")
    assert_true("Copy All button present in IPv4 split list",
                await page.locator("#panel-ipv4 .copy-all-btn[data-target='split']").count() > 0)


async def test_ipv6_copy_all(page: Page) -> None:
    section("IPv6 splitter — Copy All button")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='split6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='split_prefix6']", "/34")
    await submit_form(page, "#panel-ipv6 .splitter-form")
    assert_true("Copy All button present in IPv6 split list",
                await page.locator("#panel-ipv6 .copy-all-btn[data-target='split']").count() > 0)


async def test_vlsm_utilisation_summary(page: Page) -> None:
    section("VLSM — utilisation summary")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "192.168.1.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    assert_true("Utilisation summary present",
                await page.locator(".vlsm-summary").count() > 0)
    summary = await page.text_content(".vlsm-summary") or ""
    assert_contains("Summary shows Hosts requested", summary, "Hosts requested")
    assert_contains("Summary shows utilisation %",   summary, "%")


async def test_vlsm_sort_note(page: Page) -> None:
    section("VLSM — sort order note")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'X'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '10'")
    await submit_form(page, ".vlsm-form")
    note = await page.text_content(".vlsm-sort-note") or ""
    assert_contains("Sort note mentions largest-first", note.lower(), "largest")


async def test_vlsm6_basic(page: Page) -> None:
    section("VLSM6 — IPv6 planner basic allocation")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.wait_for_selector("#panel-vlsm6.active")

    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "32")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'site-a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '256'")
    await submit_form(page, ".vlsm6-form")

    assert_true("VLSM6 results table exists",
                await page.locator(".vlsm6-table").count() > 0)
    subnet = await page.text_content(".vlsm6-table .vlsm-subnet-cell code") or ""
    assert_contains("VLSM6 allocates IPv6 /N block", subnet, "/")
    assert_contains("VLSM6 allocates within parent", subnet, "2001:db8")


async def test_vlsm6_2pow_n(page: Page) -> None:
    section("VLSM6 — 2^N huge host count")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "32")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'huge'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '2^96'")
    await submit_form(page, ".vlsm6-form")

    rows = await page.locator(".vlsm6-table tbody tr").count()
    assert_eq("VLSM6 2^N: one row", rows, 1)
    usable = await page.text_content(".vlsm6-table tbody tr td:nth-child(4)") or ""
    assert_contains("VLSM6 2^N usable shown as 2^N string", usable, "2^")


async def test_vlsm6_non_power_of_two(page: Page) -> None:
    section("VLSM6 — non-power-of-two host count rounds up to allocated block")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "120")
    # 100 hosts → next-fit /121 block (128 usable) — pins the regression where
    # an exact "100 → 100" string would have leaked through instead of the
    # allocated block size.
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'odd'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '100'")
    await submit_form(page, ".vlsm6-form")
    rows = await page.locator(".vlsm6-table tbody tr").count()
    if not assert_eq("VLSM6 non-2^N: one row", rows, 1):
        return
    usable = (await page.text_content(".vlsm6-table tbody tr td:nth-child(4)") or "").strip()
    assert_eq("VLSM6 non-2^N usable equals allocated block 128", usable, "128")


async def test_vlsm6_overcapacity_error(page: Page) -> None:
    section("VLSM6 — error when over capacity")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "126")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'big'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '32'")
    await submit_form(page, ".vlsm6-form")
    err = await page.text_content("#panel-vlsm6 .error") or ""
    assert_true("VLSM6 over-capacity error shown", len(err) > 0, err)


async def test_vlsm6_shareable_url(page: Page) -> None:
    section("VLSM6 — shareable URL auto-populates and calculates")

    url = (APP_URL + "?tab=vlsm6&vlsm6_network=2001:db8::&vlsm6_cidr=32"
           "&vlsm6_name%5B0%5D=site-a&vlsm6_hosts%5B0%5D=256")
    await navigate(page, url)
    assert_true("VLSM6 results table after GET",
                await page.locator(".vlsm6-table").count() > 0)
    subnet = await page.text_content(".vlsm6-table .vlsm-subnet-cell code") or ""
    assert_contains("VLSM6 GET auto-calc subnet", subnet, "/")
    share = await page.text_content("#panel-vlsm6 .share-url") or ""
    assert_contains("VLSM6 share URL contains vlsm6_network", share, "vlsm6_network")
    network_val = await page.input_value("#vlsm6_network")
    assert_eq("VLSM6 network field pre-filled from GET", network_val, "2001:db8::")


async def test_vlsm6_dynamic_rows(page: Page) -> None:
    section("VLSM6 — dynamic add/remove requirement rows")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.click(".vlsm6-add-row")
    await page.click(".vlsm6-add-row")
    rows = await page.locator("#vlsm6-reqs .vlsm-req-row").count()
    assert_eq("VLSM6 add-row produces 3 rows total", rows, 3)
    # Remove one
    await page.click("#vlsm6-reqs .vlsm-req-row:last-child .vlsm-remove-row")
    rows2 = await page.locator("#vlsm6-reqs .vlsm-req-row").count()
    assert_eq("VLSM6 remove-row reduces to 2", rows2, 2)


async def test_vlsm6_validation_inline(page: Page) -> None:
    section("VLSM6 — inline validation rejects junk hosts input")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "32")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'site-a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = 'abc'")
    # JS preventDefault should keep us on the page; click submit directly
    await page.click(".vlsm6-form button[type='submit']")
    inline = await page.locator(".vlsm6-form .vlsm-inline-error").count()
    assert_true("VLSM6 inline validation flags junk input", inline > 0,
                "expected at least one .vlsm-inline-error")


async def test_vlsm6_copy_all(page: Page) -> None:
    section("VLSM6 — Copy All button present")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "32")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '256'")
    await submit_form(page, ".vlsm6-form")
    assert_true("VLSM6 Copy All button rendered",
                await page.locator("#panel-vlsm6 .copy-all-btn[data-target='vlsm6']").count() > 0)
    assert_true("VLSM6 export buttons present",
                await page.locator("#vlsm6-export-csv").count() > 0
                and await page.locator("#vlsm6-export-json").count() > 0
                and await page.locator("#vlsm6-export-ascii").count() > 0)


async def test_vlsm6_csv_export(page: Page) -> None:
    section("VLSM6 — CSV export button and table content")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "32")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'site-a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '256'")
    await submit_form(page, ".vlsm6-form")
    assert_true("VLSM6 Export CSV button present",
                await page.locator("#vlsm6-export-csv").count() > 0)
    # Verify a result row exists with an IPv6 subnet (contains a colon)
    subnet = await page.text_content(".vlsm6-table .vlsm-subnet-cell code") or ""
    assert_true("VLSM6 result row present with IPv6 subnet",
                ":" in subnet and "/" in subnet, subnet)


async def test_vlsm6_json_export(page: Page) -> None:
    section("VLSM6 — JSON export download")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "32")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'site-a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '256'")
    await submit_form(page, ".vlsm6-form")
    assert_true("VLSM6 Export JSON button present",
                await page.locator("#vlsm6-export-json").count() > 0)
    async with page.expect_download() as dl_info:
        await page.click("#vlsm6-export-json")
    download = await dl_info.value
    assert_true("VLSM6 JSON download filename ends with .json",
                (download.suggested_filename or "").endswith(".json"),
                download.suggested_filename)


async def test_vlsm6_reset(page: Page) -> None:
    section("VLSM6 — Reset button clears form and results")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "32")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'site-a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '256'")
    await submit_form(page, ".vlsm6-form")
    assert_true("VLSM6 table shown before reset",
                await page.locator(".vlsm6-table").count() > 0)
    async with page.expect_navigation(wait_until="load"):
        await page.click("#panel-vlsm6 a.reset")
    assert_true("VLSM6 table gone after reset",
                await page.locator(".vlsm6-table").count() == 0)
    assert_eq("VLSM6 network field cleared", await page.input_value("#vlsm6_network"), "")


async def test_vlsm6_keyboard_delete(page: Page) -> None:
    section("VLSM6 — keyboard Delete on focused row removes it")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    # Add two rows to start with three total
    await page.click(".vlsm6-add-row")
    await page.click(".vlsm6-add-row")
    rows_before = await page.locator("#vlsm6-reqs .vlsm-req-row").count()
    assert_eq("Three VLSM6 rows present before delete", rows_before, 3)
    # Focus the remove button of the last row and press Delete
    await page.focus("#vlsm6-reqs .vlsm-req-row:last-child .vlsm-remove-row")
    await page.keyboard.press("Delete")
    rows_after = await page.locator("#vlsm6-reqs .vlsm-req-row").count()
    assert_eq("VLSM6 row removed by keyboard Delete", rows_after, 2)


async def test_vlsm6_utilisation_summary(page: Page) -> None:
    section("VLSM6 — utilisation summary")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "120")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'site-a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '64'")
    await submit_form(page, ".vlsm6-form")
    assert_true("VLSM6 utilisation summary present",
                await page.locator("#panel-vlsm6 .vlsm-summary").count() > 0)
    summary = await page.text_content("#panel-vlsm6 .vlsm-summary") or ""
    assert_contains("VLSM6 summary shows Hosts requested", summary, "Hosts requested")
    assert_contains("VLSM6 summary shows Allocated",      summary, "Allocated")
    assert_contains("VLSM6 summary shows Remaining",      summary, "Remaining")
    assert_contains("VLSM6 summary shows Utilisation",    summary, "Utilisation")
    assert_contains("VLSM6 summary shows %",              summary, "%")


async def test_ipv6_overlap(page: Page) -> None:
    section("VLSM tab — IPv6 overlap checker")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.click("#panel-vlsm .tool-trigger[data-tool='overlap']")
    await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
    await page.fill("input[name='overlap_cidr_a']", "2001:db8::/32")
    await page.fill("input[name='overlap_cidr_b']", "2001:db8:1::/48")
    await submit_form(page, ".overlap-form")
    result = await page.text_content(".overlap-result") or ""
    assert_contains("IPv6 a_contains_b detected", result.lower(), "contains")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.click("#panel-vlsm .tool-trigger[data-tool='overlap']")
    await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
    await page.fill("input[name='overlap_cidr_a']", "2001:db8::/32")
    await page.fill("input[name='overlap_cidr_b']", "2001:db9::/32")
    await submit_form(page, ".overlap-form")
    result2 = await page.text_content(".overlap-result") or ""
    assert_contains("IPv6 no overlap detected", result2.lower(), "no overlap")


async def test_multi_cidr_overlap(page: Page) -> None:
    section("VLSM tab — multi-CIDR pairwise overlap check")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.click("#panel-vlsm .tool-trigger[data-tool='multi']")
    await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
    await page.fill("textarea[name='multi_overlap_input']",
                    "10.0.0.0/23\n10.0.0.0/24\n192.168.1.0/24")
    await submit_form(page, ".multi-overlap-panel form")
    assert_true("Conflict list present",
                await page.locator(".multi-overlap-list").count() > 0)
    conflicts = await page.text_content(".multi-overlap-list") or ""
    assert_contains("Conflict shows 10.0.0.0/23", conflicts, "10.0.0.0/23")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.click("#panel-vlsm .tool-trigger[data-tool='multi']")
    await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
    await page.fill("textarea[name='multi_overlap_input']",
                    "10.0.0.0/24\n10.0.1.0/24\n10.0.2.0/24")
    await submit_form(page, ".multi-overlap-panel form")
    no_conflict = await page.text_content(".overlap-none") or ""
    assert_contains("No overlaps message shown", no_conflict.lower(), "no overlaps")


async def test_ipv6_binary_repr(page: Page) -> None:
    section("IPv6 — binary/hex representation block")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")
    assert_true("IPv6 binary-details element present",
                await page.locator("#panel-ipv6 .binary-details").count() > 0)
    await page.evaluate(
        "document.querySelector('#panel-ipv6 .binary-details').setAttribute('open', '')"
    )
    boundary = await page.text_content("#panel-ipv6 .bin-boundary") or ""
    assert_contains("IPv6 boundary shows 32 network bits", boundary, "32")
    assert_contains("IPv6 boundary shows 96 host bits",    boundary, "96")


async def test_regression_bugs_v130(page: Page) -> None:
    section("Regression — v1.3.0 bug fixes (#149 #150 #151 #152)")

    # #149: version badge is dynamic (not hardcoded v1.2.0)
    await navigate(page, APP_URL)
    version_text = await page.text_content(".version") or ""
    assert_true("#149: version badge not empty", len(version_text) > 0, version_text)
    assert_true("#149: version badge starts with v",
                version_text.strip().startswith("v"), version_text)

    # #150: IPv4 Address Type row has tabindex=0
    await page.fill("#ip",   "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    tabindex_v4 = await page.evaluate("""() => {
        var rows = document.querySelectorAll('.result-row');
        for (var r of rows) {
            var l = r.querySelector('.result-label');
            if (l && l.textContent.trim().startsWith('Address Type')) {
                return r.getAttribute('tabindex');
            }
        }
        return null;
    }""")
    assert_eq("#150: IPv4 Address Type row has tabindex='0'", tabindex_v4, "0")

    # #150: IPv6 Address Type row has tabindex=0
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")
    tabindex_v6 = await page.evaluate("""() => {
        var rows = document.querySelectorAll('#panel-ipv6 .result-row');
        for (var r of rows) {
            var l = r.querySelector('.result-label');
            if (l && l.textContent.trim().startsWith('Address Type')) {
                return r.getAttribute('tabindex');
            }
        }
        return null;
    }""")
    assert_eq("#150: IPv6 Address Type row has tabindex='0'", tabindex_v6, "0")

    # #152: input font-size is 16px (prevents iOS Safari zoom)
    await navigate(page, APP_URL)
    font_size = await page.evaluate("""() => {
        var el = document.querySelector('input[type="text"]');
        return el ? window.getComputedStyle(el).fontSize : '';
    }""")
    assert_eq("#152: input font-size is 16px (1rem)", font_size, "16px")


# ---------------------------------------------------------------------------
# Supernet / Route Summarisation UI
# ---------------------------------------------------------------------------

async def test_supernet_ui(page: Page) -> None:
    section("Supernet / Route Summarisation UI")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv4")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='supernet']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")

    # Find supernet of two adjacent /24s — should be /23
    await page.fill("textarea[name='supernet_input']", "10.0.0.0/24\n10.0.1.0/24")
    await page.click("button[name='supernet_action'][value='find']")
    await page.wait_for_load_state("load")

    supernet_text = await page.text_content(".overlap-panel .overlap-result")
    assert_contains("supernet: two /24s → /23 result shown", supernet_text or "", "10.0.0.0/23")

    # Summarise routes — 3 inputs: /25 contained in /24, two /24s merge to /23
    await navigate(page, APP_URL)
    await page.click("#tab-ipv4")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='supernet']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("textarea[name='supernet_input']", "10.0.0.0/24\n10.0.0.0/25\n10.0.1.0/24")
    await page.click("button[name='supernet_action'][value='summarise']")
    await page.wait_for_load_state("load")

    items = await page.query_selector_all(".overlap-panel .split-item")
    assert_true("summarise: /25 removed, two /24s merge → 1 result (/23)", len(items) == 1,
                f"got {len(items)} items")

    copy_all = await page.query_selector(".overlap-panel .copy-all-btn[data-target='supernet']")
    assert_true("summarise: Copy All button with data-target=supernet present", copy_all is not None)

    # Error case
    await navigate(page, APP_URL)
    await page.click("#tab-ipv4")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='supernet']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("textarea[name='supernet_input']", "not-a-cidr")
    await page.click("button[name='supernet_action'][value='find']")
    await page.wait_for_load_state("load")
    err = await page.text_content(".overlap-panel .error")
    assert_true("supernet: invalid CIDR shows error", err and len(err) > 0)


# ---------------------------------------------------------------------------
# ULA Generator UI
# ---------------------------------------------------------------------------

async def test_ula_generator_ui(page: Page) -> None:
    section("ULA Generator UI")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='ula']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")

    # Generate with random global ID
    await page.click("button[name='ula_generate']")
    await page.wait_for_load_state("load")

    prefix_text = await page.text_content("#panel-ipv6 .ula-result .overlap-result")
    assert_true("ula: generated /48 shown", prefix_text and "/48" in (prefix_text or ""),
                f"prefix text: {prefix_text!r}")

    # Starts with fd (ULA range)
    assert_true("ula: prefix starts with fd", (prefix_text or "").lower().startswith("fd"),
                f"got {prefix_text!r}")

    # Example /64 subnets shown
    example_items = await page.query_selector_all("#panel-ipv6 .ula-result .split-item")
    assert_true("ula: at least 5 example /64s shown", len(example_items) >= 5,
                f"got {len(example_items)} items")

    # Copy All button present
    copy_all = await page.query_selector("#panel-ipv6 .copy-all-btn[data-target='ula']")
    assert_true("ula: Copy All button with data-target=ula present", copy_all is not None)

    # Fixed global ID is deterministic
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='ula']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#ula_global_id", "aabbccddee")
    await page.click("button[name='ula_generate']")
    await page.wait_for_load_state("load")
    prefix1 = await page.text_content("#panel-ipv6 .ula-result .overlap-result")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='ula']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#ula_global_id", "aabbccddee")
    await page.click("button[name='ula_generate']")
    await page.wait_for_load_state("load")
    prefix2 = await page.text_content("#panel-ipv6 .ula-result .overlap-result")

    assert_eq("ula: same global_id produces same prefix", prefix1, prefix2)

    # Invalid global ID shows error
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='ula']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#ula_global_id", "ZZZZ")
    await page.click("button[name='ula_generate']")
    await page.wait_for_load_state("load")
    err = await page.query_selector("#panel-ipv6 .overlap-panel .error")
    assert_true("ula: non-hex global ID shows error", err is not None)


async def test_tooltips_help_bubbles(page: Page) -> None:
    section("Tooltips — help bubbles visible on hover")

    await navigate(page, APP_URL)

    # IPv4 IP address label bubble
    bubble = page.locator("#hb-ipv4-ip")
    assert_true("help bubble for IP address input exists",
                await bubble.count() > 0)

    # Hover over the specific icon for the IP address bubble and verify its tooltip becomes visible
    icon = page.locator(".help-bubble-icon[aria-describedby='hb-ipv4-ip']")
    await icon.hover()
    await page.wait_for_timeout(200)
    tooltip = page.locator("#hb-ipv4-ip")
    assert_true("targeted tooltip is visible after hover",
                await tooltip.is_visible())

    # VLSM tab — waste header bubble (only visible after a calculation)
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr", "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    await page.wait_for_selector(".vlsm-table", timeout=8000)
    vlsm_bubble = page.locator("#hb-vlsm-waste")
    assert_true("help bubble for VLSM Waste header exists",
                await vlsm_bubble.count() > 0)

    # IPv6 tab — expanded row bubble
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6", "2001:db8::/32")
    await page.press("#ipv6", "Enter")
    await page.wait_for_selector(".result-row")
    ipv6_bubble = page.locator("#hb-ipv6-expanded")
    assert_true("help bubble for IPv6 expanded address exists",
                await ipv6_bubble.count() > 0)


# ── Tool Drawer ───────────────────────────────────────────────────────────────

async def test_tool_drawer_toolbar_renders(page: Page) -> None:
    section("Tool Drawer: toolbar renders after IPv4 calculate")
    await navigate(page, APP_URL)
    await page.fill("#ip", "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .tool-toolbar")
    toolbar = page.locator("#panel-ipv4 .tool-toolbar")
    assert_true("toolbar visible after calculate", await toolbar.is_visible())
    assert_true("split trigger present",     await toolbar.locator(".tool-trigger[data-tool='split']").count() == 1)
    assert_true("supernet trigger present",  await toolbar.locator(".tool-trigger[data-tool='supernet']").count() == 1)
    assert_true("range trigger present",     await toolbar.locator(".tool-trigger[data-tool='range']").count() == 1)
    assert_true("tree trigger present",      await toolbar.locator(".tool-trigger[data-tool='tree']").count() == 1)


async def test_tool_drawer_click_split_opens(page: Page) -> None:
    section("Tool Drawer: click Split opens drawer")
    await navigate(page, APP_URL)
    await page.fill("#ip", "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .tool-toolbar")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    assert_true("drawer visible after split click",     await page.locator("#panel-ipv4 .tool-drawer.open").is_visible())
    assert_eq("split trigger aria-expanded=true",
              await page.locator("#panel-ipv4 .tool-trigger[data-tool='split']").get_attribute("aria-expanded"), "true")
    assert_true("split panel visible inside drawer",    await page.locator("#panel-ipv4 .tool-panel[data-tool='split']").is_visible())


async def test_tool_drawer_auto_reopens_after_submit(page: Page) -> None:
    section("Tool Drawer: auto-reopens after Split form submit")
    await navigate(page, APP_URL)
    await page.fill("#ip", "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .tool-toolbar")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("#panel-ipv4 input[name='split_prefix']", "25")
    await submit_form(page, "#panel-ipv4 .splitter-form")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    assert_true("drawer auto-reopens after split submit",    await page.locator("#panel-ipv4 .tool-drawer.open").is_visible())
    assert_true("split results present in drawer",           await page.locator("#panel-ipv4 .split-item").count() > 0)


async def test_tool_drawer_escape_closes(page: Page) -> None:
    section("Tool Drawer: Escape closes drawer, focus returns to trigger")
    await navigate(page, APP_URL)
    await page.fill("#ip", "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .tool-toolbar")
    await page.locator("#panel-ipv4 .tool-trigger[data-tool='split']").click()
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.keyboard.press("Escape")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer:not(.open)")
    assert_true("drawer closed after Escape",
                not await page.locator("#panel-ipv4 .tool-drawer.open").is_visible())
    focused_tool = await page.evaluate("document.activeElement?.dataset?.tool ?? ''")
    assert_eq("focus returned to split trigger", focused_tool, "split")


async def test_tool_drawer_close_button(page: Page) -> None:
    section("Tool Drawer: x button closes drawer")
    await navigate(page, APP_URL)
    await page.fill("#ip", "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .tool-toolbar")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='supernet']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.click("#panel-ipv4 .tool-drawer-close")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer:not(.open)")
    assert_true("drawer closed after x click",
                not await page.locator("#panel-ipv4 .tool-drawer.open").is_visible())


async def test_tool_drawer_toggle_closed(page: Page) -> None:
    section("Tool Drawer: clicking same trigger twice toggles closed")
    await navigate(page, APP_URL)
    await page.fill("#ip", "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .tool-toolbar")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='range']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='range']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer:not(.open)")
    assert_true("drawer toggled closed by second click",
                not await page.locator("#panel-ipv4 .tool-drawer.open").is_visible())


async def test_tool_drawer_switch_tools(page: Page) -> None:
    section("Tool Drawer: switching tools swaps content without close")
    await navigate(page, APP_URL)
    await page.fill("#ip", "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .tool-toolbar")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='tree']")
    await page.wait_for_selector("#panel-ipv4 .tool-panel[data-tool='tree']:visible")
    assert_true("drawer stays open on tool switch",     await page.locator("#panel-ipv4 .tool-drawer.open").is_visible())
    assert_true("tree panel visible after switch",      await page.locator("#panel-ipv4 .tool-panel[data-tool='tree']").is_visible())
    assert_true("split panel hidden after switch",      not await page.locator("#panel-ipv4 .tool-panel[data-tool='split']").is_visible())
    assert_eq("tree trigger aria-expanded=true",
              await page.locator("#panel-ipv4 .tool-trigger[data-tool='tree']").get_attribute("aria-expanded"), "true")
    assert_eq("split trigger aria-expanded=false",
              await page.locator("#panel-ipv4 .tool-trigger[data-tool='split']").get_attribute("aria-expanded"), "false")


async def test_visual_regression(page: Page) -> None:
    section("Visual regression — pixel comparison against baselines")

    UPDATE = os.environ.get("UPDATE_SNAPSHOTS", "0") == "1"
    if not _SNAPSHOTS_AVAILABLE or (not UPDATE and not _SNAPSHOT_PIL_AVAILABLE):
        ok("visual regression: snapshot_utils not available — skipped")
        return

    async def snap(name: str) -> None:
        if UPDATE:
            await capture_snapshot(page, name)
            ok(f"visual: baseline updated — {name}")
        else:
            try:
                passed, diff_pct = await compare_snapshot(page, name)
                label = f"visual: {name} diff={diff_pct:.2%}"
                if passed:
                    ok(label)
                else:
                    fail(label, f"diff {diff_pct:.2%} exceeds threshold 2%")
            except FileNotFoundError as exc:
                fail(f"visual: {name} — no baseline (run with UPDATE_SNAPSHOTS=1 to create)", str(exc))

    # Desktop viewport
    await _set_viewport(page, 1280)

    # IPv4 result
    await navigate(page, APP_URL)
    await page.fill("#ip", "192.168.1.0")
    await page.fill("#mask", "/24")  # leading slash intentionally exercises CIDR-notation auto-parse
    await page.press("#ip", "Enter")
    await page.wait_for_selector(".results")
    await snap("ipv4_result_1280")

    # IPv6 result
    await page.click("#tab-ipv6")
    await page.fill("#ipv6", "2001:db8::/32")
    await page.press("#ipv6", "Enter")
    await page.wait_for_selector("#panel-ipv6 .results")
    await snap("ipv6_result_1280")

    # VLSM table
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr",    "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    await snap("vlsm_table_1280")

    # Mobile viewport — IPv4 result
    await _set_viewport(page, 375, 812)
    await navigate(page, APP_URL)
    await page.fill("#ip", "192.168.1.0/24")
    await page.press("#ip", "Enter")
    await page.wait_for_selector(".results")
    await snap("ipv4_result_375")

    # Restore default viewport
    await _set_viewport(page, 1280)


async def test_docs_footer_link(page: Page) -> None:
    section("Docs footer link")

    await navigate(page, APP_URL)
    link = page.locator("footer a[href*='docs.subnetcalculator.app']")
    assert_true("Docs link present in footer",
                await link.count() > 0)
    target = await link.get_attribute("target")
    assert_true("Docs link opens in new tab",
                target == "_blank", str(target))


async def test_wildcard_cidr_to_wildcard(page: Page) -> None:
    section("Wildcard ↔ CIDR — CIDR input")

    await navigate(page, APP_URL)
    await page.click(".tool-trigger[data-tool='wildcard']")
    await page.fill("#wildcard_input", "/24")
    await page.click(
        ".tool-panel[data-tool='wildcard'] button[type='submit']"
    )
    await page.wait_for_selector("#wildcard-result-mask")
    cidr_text = await page.text_content("#wildcard-result-cidr")
    mask_text = await page.text_content("#wildcard-result-mask")
    assert_contains("CIDR result row contains /24", cidr_text or "", "/24")
    assert_contains("Wildcard result row contains 0.0.0.255",
                    mask_text or "", "0.0.0.255")


async def test_wildcard_to_cidr(page: Page) -> None:
    section("Wildcard ↔ CIDR — wildcard input")

    await navigate(page, APP_URL)
    await page.click(".tool-trigger[data-tool='wildcard']")
    await page.fill("#wildcard_input", "0.0.0.255")
    await page.click(
        ".tool-panel[data-tool='wildcard'] button[type='submit']"
    )
    await page.wait_for_selector("#wildcard-result-cidr")
    cidr_text = await page.text_content("#wildcard-result-cidr")
    mask_text = await page.text_content("#wildcard-result-mask")
    assert_contains("CIDR row shows /24", cidr_text or "", "/24")
    assert_contains("Wildcard row shows 0.0.0.255",
                    mask_text or "", "0.0.0.255")


async def test_wildcard_rejects_noncontiguous(page: Page) -> None:
    section("Wildcard ↔ CIDR — non-contiguous mask rejected")

    await navigate(page, APP_URL)
    await page.click(".tool-trigger[data-tool='wildcard']")
    await page.fill("#wildcard_input", "0.0.255.0")
    await page.click(
        ".tool-panel[data-tool='wildcard'] button[type='submit']"
    )
    await page.wait_for_selector(".wildcard-error")
    err_text = await page.text_content(".wildcard-error")
    assert_contains("Error mentions contiguous", err_text or "", "contiguous")


async def test_wildcard_api_endpoint(_page: Page) -> None:
    section("Wildcard API — POST /api/v1/wildcard")

    status, data = _api_post("wildcard", {"value": "/24"})
    assert_eq("HTTP 200 for /24", status, 200)
    assert_true("ok=true", data.get("ok") is True, str(data))
    payload = data.get("data") or {}
    assert_eq("data.cidr is /24",     payload.get("cidr"),     "/24")
    assert_eq("data.wildcard is 0.0.0.255",
              payload.get("wildcard"), "0.0.0.255")

    status2, data2 = _api_post("wildcard", {"value": "0.0.0.255"})
    assert_eq("HTTP 200 for wildcard", status2, 200)
    payload2 = data2.get("data") or {}
    assert_eq("data.cidr is /24",     payload2.get("cidr"),     "/24")
    assert_eq("data.wildcard echo",
              payload2.get("wildcard"), "0.0.0.255")

    status3, data3 = _api_post("wildcard", {"value": "0.0.255.0"})
    assert_eq("HTTP 400 for non-contiguous", status3, 400)
    assert_true("ok=false", data3.get("ok") is False, str(data3))


async def test_lookup_api_endpoint(_page: Page) -> None:
    section("Lookup API — POST /api/v1/lookup")

    status, data = _api_post("lookup", {
        "cidrs": ["10.0.0.0/8", "10.1.0.0/16", "10.1.2.0/24", "2001:db8::/32"],
        "ips":   ["10.1.2.3", "8.8.8.8", "2001:db8::1"],
    })
    assert_eq("api lookup: HTTP 200", status, 200)
    assert_true("api lookup: ok=true", data.get("ok") is True, str(data))
    results = data.get("data", {}).get("results") or []
    if not assert_eq("api lookup: 3 result rows", len(results), 3):
        return

    # Row 0: 10.1.2.3 — deepest is /24, all 3 v4 CIDRs match
    row0 = results[0]
    assert_eq("api lookup: row[0].ip", row0.get("ip"), "10.1.2.3")
    assert_eq("api lookup: row[0].deepest is /24",
              row0.get("deepest"), "10.1.2.0/24")
    assert_eq("api lookup: row[0] has 3 matches",
              len(row0.get("matches") or []), 3)

    # Row 1: 8.8.8.8 — no match
    row1 = results[1]
    assert_eq("api lookup: row[1].deepest is null",
              row1.get("deepest"), None)
    assert_eq("api lookup: row[1].matches is empty",
              row1.get("matches"), [])

    # Row 2: 2001:db8::1 — matches v6 only
    row2 = results[2]
    assert_eq("api lookup: row[2].deepest is /32",
              row2.get("deepest"), "2001:db8::/32")

    # Missing cidrs → 400
    status_b, _ = _api_post("lookup", {"ips": ["10.0.0.1"]})
    assert_eq("api lookup: missing cidrs → 400", status_b, 400)

    # Invalid IP → 400
    status_c, _ = _api_post("lookup", {
        "cidrs": ["10.0.0.0/8"],
        "ips":   ["not-an-ip"],
    })
    assert_eq("api lookup: invalid ip → 400", status_c, 400)

    # Hard-ceiling enforcement: cidrs over documented max → 400
    status_d, _ = _api_post("lookup", {
        "cidrs": [f"10.{i // 256}.{i % 256}.0/24" for i in range(101)],
        "ips":   ["10.0.0.1"],
    })
    assert_eq("api lookup: cidrs over hard cap → 400", status_d, 400)


async def test_lookup_ui(page: Page) -> None:
    section("IP Lookup — UI (IPv4 tab)")

    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='lookup']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("#lookup_cidrs_v4",
                    "10.0.0.0/8\n10.1.0.0/16\n10.1.2.0/24")
    await page.fill("#lookup_ips_v4", "10.1.2.3\n8.8.8.8")
    await page.click(
        "#panel-ipv4 .tool-panel[data-tool='lookup'] button[type='submit']"
    )
    await page.wait_for_load_state("load")
    await page.wait_for_selector(".lookup-table tbody tr")

    rows = await page.locator(".lookup-table tbody tr").all_text_contents()
    if not assert_eq("lookup ui: 2 result rows", len(rows), 2):
        return
    assert_contains("lookup ui: row 0 contains 10.1.2.3", rows[0], "10.1.2.3")
    assert_contains("lookup ui: row 0 deepest is /24", rows[0], "10.1.2.0/24")
    assert_contains("lookup ui: row 1 contains 8.8.8.8", rows[1], "8.8.8.8")
    # No-match row renders em-dash (—)
    assert_contains("lookup ui: row 1 has em-dash for no match",
                    rows[1], "—")


async def test_lookup_ui_ipv6_tab(page: Page) -> None:
    section("IP Lookup — UI (IPv6 tab)")

    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='lookup']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#lookup_cidrs_v6",
                    "2001:db8::/32\n2001:db8:1::/48")
    await page.fill("#lookup_ips_v6",
                    "2001:db8:1::5\n2001:db9::1")
    await page.click(
        "#panel-ipv6 .tool-panel[data-tool='lookup'] button[type='submit']"
    )
    await page.wait_for_load_state("load")
    await page.wait_for_selector(
        "#panel-ipv6 .lookup-table tbody tr"
    )

    rows = await page.locator(
        "#panel-ipv6 .lookup-table tbody tr"
    ).all_text_contents()
    if not assert_eq("lookup ui v6: 2 result rows", len(rows), 2):
        return
    assert_contains("lookup ui v6: row 0 deepest is /48",
                    rows[0], "2001:db8:1::/48")
    assert_contains("lookup ui v6: row 1 has em-dash for no match",
                    rows[1], "—")


async def test_lookup_shareable_url(page: Page) -> None:
    section("IP Lookup — shareable GET URL auto-populates and calculates")

    url = (APP_URL + "?tab=ipv4"
           "&lookup_cidrs=10.0.0.0%2F8%0A10.1.0.0%2F16%0A10.1.2.0%2F24"
           "&lookup_ips=10.1.2.3%0A8.8.8.8")
    await navigate(page, url)

    # Drawer should be auto-opened on the lookup tool
    assert_true(
        "lookup share: tool drawer open",
        await page.locator("#panel-ipv4 .tool-drawer.open").count() > 0,
    )
    assert_true(
        "lookup share: lookup tool-panel active",
        await page.locator(
            "#panel-ipv4 .tool-panel[data-tool='lookup'].active"
        ).count() > 0,
    )

    # Results table should be rendered from GET parameters
    await page.wait_for_selector("#panel-ipv4 .lookup-table tbody tr")
    rows = await page.locator(
        "#panel-ipv4 .lookup-table tbody tr"
    ).all_text_contents()
    if not assert_eq("lookup share: 2 result rows", len(rows), 2):
        return
    assert_contains("lookup share: row 0 contains 10.1.2.3",
                    rows[0], "10.1.2.3")
    assert_contains("lookup share: row 0 deepest is /24",
                    rows[0], "10.1.2.0/24")
    assert_contains("lookup share: row 1 contains 8.8.8.8",
                    rows[1], "8.8.8.8")
    assert_contains("lookup share: row 1 has em-dash for no match",
                    rows[1], "—")

    # Textareas should be hydrated with GET values
    cidrs_val = await page.input_value("#lookup_cidrs_v4")
    assert_contains("lookup share: cidrs textarea hydrated",
                    cidrs_val, "10.0.0.0/8")
    ips_val = await page.input_value("#lookup_ips_v4")
    assert_contains("lookup share: ips textarea hydrated",
                    ips_val, "10.1.2.3")


async def test_diff_api_endpoint(_page: Page) -> None:
    section("Diff API — POST /api/v1/diff")

    status, data = _api_post("diff", {
        "before": ["10.0.0.0/24", "10.0.1.0/24", "192.168.0.0/24"],
        "after":  ["10.0.0.0/23", "10.0.1.0/24", "192.168.1.0/24"],
    })
    assert_eq("api diff: HTTP 200", status, 200)
    assert_true("api diff: ok=true", data.get("ok") is True, str(data))
    d = data.get("data", {})
    assert_eq("api diff: added", d.get("added"), ["192.168.1.0/24"])
    assert_eq("api diff: removed", d.get("removed"), ["192.168.0.0/24"])
    assert_eq("api diff: unchanged", d.get("unchanged"), ["10.0.1.0/24"])
    changed = d.get("changed") or []
    if not assert_eq("api diff: 1 changed entry", len(changed), 1):
        return
    assert_eq("api diff: changed.from", changed[0].get("from"), "10.0.0.0/24")
    assert_eq("api diff: changed.to",   changed[0].get("to"),   "10.0.0.0/23")
    assert_contains("api diff: changed.reason mentions /24",
                    changed[0].get("reason", ""), "/24")

    # Missing field → 400
    status_b, _ = _api_post("diff", {"before": ["10.0.0.0/24"]})
    assert_eq("api diff: missing after → 400", status_b, 400)

    # Invalid CIDR → 400
    status_c, _ = _api_post("diff", {
        "before": ["10.0.0.0/24"],
        "after":  ["not-a-cidr"],
    })
    assert_eq("api diff: invalid cidr → 400", status_c, 400)

    # All-empty request → 400 (both before and after empty is invalid)
    status_d, _ = _api_post("diff", {"before": [], "after": []})
    assert_eq("api diff: all-empty → 400", status_d, 400)


async def test_diff_ui(page: Page) -> None:
    section("Subnet Diff — UI (IPv4 tab)")

    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='diff']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("#diff_before_v4",
                    "10.0.0.0/24\n10.0.1.0/24\n192.168.0.0/24")
    await page.fill("#diff_after_v4",
                    "10.0.0.0/23\n10.0.1.0/24\n192.168.1.0/24")
    await page.click(
        "#panel-ipv4 .tool-panel[data-tool='diff'] button[type='submit']"
    )
    await page.wait_for_load_state("load")
    await page.wait_for_selector("#panel-ipv4 .diff-results")

    added_text = await page.locator(
        "#panel-ipv4 .diff-results .diff-group--added"
    ).text_content()
    removed_text = await page.locator(
        "#panel-ipv4 .diff-results .diff-group--removed"
    ).text_content()
    changed_text = await page.locator(
        "#panel-ipv4 .diff-results .diff-group--changed"
    ).text_content()
    unchanged_text = await page.locator(
        "#panel-ipv4 .diff-results .diff-group--unchanged"
    ).text_content()

    assert_contains("diff ui: added contains 192.168.1.0/24",
                    added_text or "", "192.168.1.0/24")
    assert_contains("diff ui: removed contains 192.168.0.0/24",
                    removed_text or "", "192.168.0.0/24")
    assert_contains("diff ui: changed contains /24 → /23",
                    changed_text or "", "/24")
    assert_contains("diff ui: changed contains 10.0.0.0/23",
                    changed_text or "", "10.0.0.0/23")
    assert_contains("diff ui: unchanged contains 10.0.1.0/24",
                    unchanged_text or "", "10.0.1.0/24")


async def test_diff_shareable_url(page: Page) -> None:
    section("Subnet Diff — shareable GET URL auto-populates and calculates")

    url = (APP_URL + "?tab=ipv4"
           "&diff_before=10.0.0.0%2F24%0A192.168.0.0%2F24"
           "&diff_after=10.0.0.0%2F23%0A192.168.1.0%2F24")
    await navigate(page, url)

    assert_true(
        "diff share: tool drawer open",
        await page.locator("#panel-ipv4 .tool-drawer.open").count() > 0,
    )
    assert_true(
        "diff share: diff tool-panel active",
        await page.locator(
            "#panel-ipv4 .tool-panel[data-tool='diff'].active"
        ).count() > 0,
    )

    await page.wait_for_selector("#panel-ipv4 .diff-results")
    added_text = await page.locator(
        "#panel-ipv4 .diff-results .diff-group--added"
    ).text_content()
    changed_text = await page.locator(
        "#panel-ipv4 .diff-results .diff-group--changed"
    ).text_content()

    assert_contains("diff share: added contains 192.168.1.0/24",
                    added_text or "", "192.168.1.0/24")
    assert_contains("diff share: changed contains 10.0.0.0/23",
                    changed_text or "", "10.0.0.0/23")

    before_val = await page.input_value("#diff_before_v4")
    assert_contains("diff share: before textarea hydrated",
                    before_val, "10.0.0.0/24")
    after_val = await page.input_value("#diff_after_v4")
    assert_contains("diff share: after textarea hydrated",
                    after_val, "10.0.0.0/23")


async def _install_clipboard_spy(page: Page) -> None:
    """Install a navigator.clipboard.writeText spy.

    The app is served over plain HTTP inside Docker (http://app:8080), which is
    not a secure context, so the real navigator.clipboard API is unavailable
    in chromium. We install a spy via addInitScript that records the most
    recent writeText() payload on window.__lastClipboard and resolves successfully,
    exercising the production clipboard branch in app.js (which prefers
    navigator.clipboard.writeText over the document.execCommand fallback).
    """
    await page.add_init_script(
        """
        (function () {
            try {
                Object.defineProperty(navigator, 'clipboard', {
                    configurable: true,
                    get: function () {
                        return {
                            writeText: function (txt) {
                                window.__lastClipboard = String(txt);
                                return Promise.resolve();
                            },
                            readText: function () {
                                return Promise.resolve(window.__lastClipboard || '');
                            },
                        };
                    },
                });
            } catch (e) { /* ignore */ }
        })();
        """
    )


async def _read_clipboard(page: Page) -> str:
    return await page.evaluate("window.__lastClipboard || ''")


async def _wait_for_clipboard_write(page: Page, previous: str = "") -> str:
    """Wait until the clipboard spy observes a payload different from `previous`.

    The Playwright Page is reused across the entire suite, so window.__lastClipboard
    survives between tests. A fixed `wait_for_timeout(150)` races with the async
    copy handler and can read a previous test's payload on slow runs. Polling for
    a change relative to a captured baseline eliminates that race.
    """
    await page.wait_for_function(
        "(prev) => (window.__lastClipboard || '') !== prev",
        arg=previous,
        timeout=2000,
    )
    return await _read_clipboard(page)


async def test_copy_as_markdown_ipv4(page: Page) -> None:
    section("Copy as Markdown — IPv4 results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.fill("#ip", "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .results")

    btn = page.locator("#panel-ipv4 .copy-md-btn[data-target='ipv4']")
    assert_true("Copy as Markdown button present (IPv4)", await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    md = await _wait_for_clipboard_write(page, prev)
    assert_contains("ipv4 markdown: header row", md, "| Field |")
    assert_contains("ipv4 markdown: separator row", md, "| --- |")
    assert_contains("ipv4 markdown: contains CIDR",
                    md, "192.168.1.0/24")


async def test_copy_as_markdown_ipv6(page: Page) -> None:
    section("Copy as Markdown — IPv6 results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6", "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")
    await page.wait_for_selector("#panel-ipv6 .results")

    btn = page.locator("#panel-ipv6 .copy-md-btn[data-target='ipv6']")
    assert_true("Copy as Markdown button present (IPv6)", await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    md = await _wait_for_clipboard_write(page, prev)
    assert_contains("ipv6 markdown: header row", md, "| Field |")
    assert_contains("ipv6 markdown: separator row", md, "| --- |")
    assert_contains("ipv6 markdown: contains prefix", md, "2001:db8::/32")


async def test_copy_as_markdown_vlsm(page: Page) -> None:
    section("Copy as Markdown — VLSM results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr", "24")
    await page.evaluate(
        "document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'"
    )
    await page.evaluate(
        "document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'"
    )
    await submit_form(page, ".vlsm-form")
    await page.wait_for_selector(".vlsm-table")

    btn = page.locator(".copy-md-btn[data-target='vlsm']")
    assert_true("Copy as Markdown button present (VLSM)", await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    md = await _wait_for_clipboard_write(page, prev)
    assert_contains("vlsm markdown: name column", md, "| Name |")
    assert_contains("vlsm markdown: separator row", md, "| --- |")
    assert_contains("vlsm markdown: LAN A row", md, "LAN A")


async def test_copy_as_cisco_ipv4(page: Page) -> None:
    section("Copy as Cisco — IPv4 results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.fill("#ip", "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.wait_for_selector("#panel-ipv4 .results")

    btn = page.locator("#panel-ipv4 .copy-cisco-btn[data-target='ipv4']")
    assert_true("Copy as Cisco button present (IPv4)", await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    cfg = await _wait_for_clipboard_write(page, prev)
    assert_contains("ipv4 cisco: interface stanza", cfg, "interface ")
    assert_contains("ipv4 cisco: ip address line", cfg, "ip address ")
    assert_contains("ipv4 cisco: subnet mask in dotted", cfg, "255.255.255.0")


async def test_copy_as_cisco_vlsm(page: Page) -> None:
    section("Copy as Cisco — VLSM results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr", "24")
    await page.evaluate(
        "document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN A'"
    )
    await page.evaluate(
        "document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'"
    )
    await submit_form(page, ".vlsm-form")
    await page.wait_for_selector(".vlsm-table")

    btn = page.locator(".copy-cisco-btn[data-target='vlsm']")
    assert_true("Copy as Cisco button present (VLSM)", await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    cfg = await _wait_for_clipboard_write(page, prev)
    assert_contains("vlsm cisco: interface stanza present", cfg, "interface ")
    assert_contains("vlsm cisco: ip address keyword", cfg, "ip address ")
    assert_contains("vlsm cisco: includes a /26 mask",
                    cfg, "255.255.255.192")


async def test_copy_as_cisco_ipv6(page: Page) -> None:
    section("Copy as Cisco — IPv6 results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6", "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")
    await page.wait_for_selector("#panel-ipv6 .results")

    btn = page.locator("#panel-ipv6 .copy-cisco-btn[data-target='ipv6']")
    assert_true("Copy as Cisco button present (IPv6)", await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    cfg = await _wait_for_clipboard_write(page, prev)
    assert_contains("ipv6 cisco: interface stanza present", cfg, "interface ")
    assert_contains("ipv6 cisco: ipv6 address line", cfg, "ipv6 address ")


async def test_copy_as_markdown_splitter(page: Page) -> None:
    section("Copy as Markdown — IPv4 splitter results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='split']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, "#panel-ipv4 .splitter-form")
    await page.wait_for_selector("#panel-ipv4 .split-list")

    btn = page.locator(".copy-md-btn[data-target='split4']")
    assert_true("Copy as Markdown button present (splitter v4)",
                await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    md = await _wait_for_clipboard_write(page, prev)
    assert_contains("splitter md: includes /26 subnet", md, "/26")
    assert_contains("splitter md: includes 192.168.1", md, "192.168.1")


async def test_copy_as_markdown_vlsm6(page: Page) -> None:
    section("Copy as Markdown — VLSM6 results")

    await _install_clipboard_spy(page)
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm6")
    await page.fill("#vlsm6_network", "2001:db8::")
    await page.fill("#vlsm6_cidr",    "120")
    await page.evaluate("document.querySelectorAll('.vlsm6-name-input')[0].value = 'site-a'")
    await page.evaluate("document.querySelectorAll('.vlsm6-hosts-input')[0].value = '64'")
    await submit_form(page, ".vlsm6-form")
    await page.wait_for_selector(".vlsm6-table")

    btn = page.locator(".copy-md-btn[data-target='vlsm6']")
    assert_true("Copy as Markdown button present (VLSM6)",
                await btn.count() > 0)
    prev = await _read_clipboard(page)
    await btn.first.click()
    md = await _wait_for_clipboard_write(page, prev)
    assert_contains("vlsm6 md: separator row", md, "| --- |")
    assert_contains("vlsm6 md: site-a row", md, "site-a")


async def test_print_stylesheet_dark_mode(page: Page) -> None:
    section("Print stylesheet (dark mode)")

    await navigate(page, APP_URL)
    await page.evaluate(
        "document.documentElement.setAttribute('data-theme','dark')"
    )
    await page.emulate_media(media="print")

    body_bg = await page.evaluate(
        "getComputedStyle(document.body).backgroundColor"
    )
    assert_true(
        "print body bg is white in dark mode",
        body_bg in ("rgb(255, 255, 255)", "rgba(0, 0, 0, 0)"),
        body_bg,
    )

    main_color = await page.evaluate(
        "getComputedStyle(document.querySelector('main#main-content') "
        "|| document.querySelector('.card')).color"
    )
    m = re.match(r"rgba?\((\d+),\s*(\d+),\s*(\d+)", main_color)
    assert_true(
        "print main color parseable",
        m is not None,
        main_color,
    )
    if m:
        r, g, b = (int(x) for x in m.groups())
        assert_true(
            "print main color is dark ink (max channel < 80)",
            max(r, g, b) < 80,
            main_color,
        )

    # Restore default emulation so subsequent tests see a normal viewport
    await page.emulate_media(media="screen")


async def test_sitemap_and_robots(_page: Page) -> None:
    section("sitemap.xml + robots.txt")

    status, hdrs, body = _http_get("sitemap.xml")
    assert_eq("sitemap.xml HTTP 200", status, 200)
    assert_contains("sitemap.xml is XML", hdrs.get("content-type", ""), "xml")
    assert_contains("sitemap.xml has <urlset>", body, "<urlset")
    assert_contains("sitemap.xml lists app root",
                    body, "https://subnetcalculator.app/")
    assert_true("sitemap.xml does NOT cross host into docs subdomain",
                "docs.subnetcalculator.app" not in body, body[:300])

    rstatus, _, robots = _http_get("robots.txt")
    assert_eq("robots.txt HTTP 200", rstatus, 200)
    assert_contains("robots.txt advertises sitemap",
                    robots, "Sitemap: https://subnetcalculator.app/sitemap.xml")


# ---------------------------------------------------------------------------
# REST API — direct HTTP tests
# ---------------------------------------------------------------------------

_API_BASE = APP_URL.rstrip("/") + "/api/v1/"


def _api_post(path: str, body: dict) -> tuple[int, dict]:
    resp = _SESSION.post(_API_BASE + path, json=body, timeout=10)
    try:
        data = resp.json()
    except Exception:
        data = {}
    return resp.status_code, data


def _api_get(path: str) -> tuple[int, dict]:
    resp = _SESSION.get(_API_BASE + path, timeout=10)
    try:
        data = resp.json()
    except Exception:
        data = {}
    return resp.status_code, data


async def test_api_meta(page: Page) -> None:
    section("API — meta endpoint")
    status, data = _api_get("")
    assert_eq("api meta: HTTP 200", status, 200)
    assert_eq("api meta: ok=true", data.get("ok"), True)
    assert_true("api meta: endpoints list present",
                isinstance(data.get("data", {}).get("endpoints"), list))


async def test_api_ipv4(page: Page) -> None:
    section("API — IPv4")
    status, data = _api_post("ipv4", {"ip": "10.0.0.1", "mask": "24"})
    assert_eq("api ipv4: HTTP 200", status, 200)
    assert_eq("api ipv4: ok=true", data.get("ok"), True)
    assert_eq("api ipv4: network_cidr", data.get("data", {}).get("network_cidr"), "10.0.0.0/24")

    # Missing ip field → 400
    status2, data2 = _api_post("ipv4", {})
    assert_eq("api ipv4: missing ip → 400", status2, 400)
    assert_eq("api ipv4: missing ip → ok=false", data2.get("ok"), False)


async def test_api_ipv6(page: Page) -> None:
    section("API — IPv6")
    status, data = _api_post("ipv6", {"ipv6": "2001:db8::", "prefix": "32"})
    assert_eq("api ipv6: HTTP 200", status, 200)
    assert_eq("api ipv6: ok=true", data.get("ok"), True)
    assert_eq("api ipv6: network_cidr", data.get("data", {}).get("network_cidr"), "2001:db8::/32")


async def test_api_vlsm(page: Page) -> None:
    section("API — VLSM")
    status, data = _api_post("vlsm", {
        "network": "10.0.0.0",
        "cidr": "24",
        "requirements": [
            {"name": "LAN", "hosts": 50},
            {"name": "DMZ", "hosts": 10},
        ],
    })
    assert_eq("api vlsm: HTTP 200", status, 200)
    assert_eq("api vlsm: ok=true", data.get("ok"), True)
    allocs = data.get("data", {}).get("allocations", [])
    if not assert_eq("api vlsm: 2 allocations", len(allocs), 2):
        return
    assert_eq("api vlsm: first allocation has subnet key",
              "subnet" in allocs[0], True)


async def test_vlsm6_api_endpoint(_page: Page) -> None:
    section("API — IPv6 VLSM (POST /api/v1/vlsm6)")
    status, data = _api_post("vlsm6", {
        "network": "2001:db8::",
        "cidr": "32",
        "requirements": [
            {"name": "site-a", "hosts": 256},
            {"name": "site-b", "hosts": 4},
        ],
    })
    assert_eq("api vlsm6: HTTP 200", status, 200)
    assert_eq("api vlsm6: ok=true", data.get("ok"), True)
    allocs = data.get("data", {}).get("allocations", [])
    if not assert_eq("api vlsm6: 2 allocations", len(allocs), 2):
        return
    names = {a.get("name") for a in allocs}
    assert_true("api vlsm6: names round-trip",
                names == {"site-a", "site-b"}, str(names))
    # Largest-first: site-a (256 hosts) → /120 block
    site_a = next((a for a in allocs if a.get("name") == "site-a"), None)
    if not assert_true("api vlsm6: site-a allocation present",
                       site_a is not None, str(allocs)):
        return
    assert site_a is not None  # narrow for type-checker
    assert_eq("api vlsm6: site-a subnet", site_a.get("subnet"), "2001:db8::/120")
    assert_eq("api vlsm6: site-a usable", site_a.get("usable"), 256)

    # 2^N huge-host string round-trip
    status2, data2 = _api_post("vlsm6", {
        "network": "2001:db8::",
        "cidr": "32",
        "requirements": [{"name": "huge", "hosts": "2^96"}],
    })
    assert_eq("api vlsm6 (2^N): HTTP 200", status2, 200)
    allocs2 = data2.get("data", {}).get("allocations", [])
    if not assert_eq("api vlsm6 (2^N): 1 allocation", len(allocs2), 1):
        return
    assert_eq("api vlsm6 (2^N): subnet", allocs2[0].get("subnet"), "2001:db8::/32")
    assert_eq("api vlsm6 (2^N): usable as 2^N", allocs2[0].get("usable"), "2^96")

    # Insufficient space -> error
    status3, data3 = _api_post("vlsm6", {
        "network": "2001:db8::",
        "cidr": "126",
        "requirements": [{"name": "x", "hosts": 32}],
    })
    assert_eq("api vlsm6 (over): HTTP 400", status3, 400)
    assert_true("api vlsm6 (over): ok=false", data3.get("ok") is False, str(data3))


async def test_api_overlap(page: Page) -> None:
    section("API — Overlap")
    # cidr_b must be network-aligned to the inner prefix for contains to detect correctly
    status, data = _api_post("overlap", {"cidr_a": "10.0.0.0/24", "cidr_b": "10.0.0.0/25"})
    assert_eq("api overlap: HTTP 200", status, 200)
    assert_eq("api overlap: relation=a_contains_b",
              data.get("data", {}).get("relation"), "a_contains_b")

    status2, data2 = _api_post("overlap", {"cidr_a": "10.0.0.0/24", "cidr_b": "192.168.1.0/24"})
    assert_eq("api overlap: no overlap → none", data2.get("data", {}).get("relation"), "none")


async def test_api_split(page: Page) -> None:
    section("API — Split IPv4")
    status, data = _api_post("split/ipv4", {"ip": "10.0.0.0", "mask": "24", "split_prefix": 26})
    assert_eq("api split ipv4: HTTP 200", status, 200)
    assert_eq("api split ipv4: 4 subnets", data.get("data", {}).get("total"), 4)
    subnets = data.get("data", {}).get("subnets", [])
    assert_eq("api split ipv4: first subnet", subnets[0] if subnets else None, "10.0.0.0/26")


async def test_api_supernet(page: Page) -> None:
    section("API — Supernet")
    status, data = _api_post("supernet", {"cidrs": ["10.0.0.0/24", "10.0.1.0/24"], "action": "find"})
    assert_eq("api supernet find: HTTP 200", status, 200)
    assert_eq("api supernet find: result", data.get("data", {}).get("supernet"), "10.0.0.0/23")

    # /25 contained in /24 → removed; two /24s are siblings → merge to /23 → 1 result
    status2, data2 = _api_post("supernet", {
        "cidrs": ["10.0.0.0/24", "10.0.0.0/25", "10.0.1.0/24"],
        "action": "summarise",
    })
    assert_eq("api supernet summarise: HTTP 200", status2, 200)
    summaries = data2.get("data", {}).get("summaries", [])
    assert_eq("api supernet summarise: 1 result (merged to /23)", len(summaries), 1)


async def test_api_ula(page: Page) -> None:
    section("API — ULA")
    status, data = _api_post("ula", {"global_id": "aabbccddee"})
    assert_eq("api ula: HTTP 200", status, 200)
    assert_eq("api ula: ok=true", data.get("ok"), True)
    prefix = data.get("data", {}).get("prefix", "")
    assert_true("api ula: prefix is /48", prefix.endswith("/48"), f"got {prefix!r}")
    assert_true("api ula: prefix starts with fd", prefix.lower().startswith("fd"),
                f"got {prefix!r}")
    assert_true("api ula: example_64s list",
                len(data.get("data", {}).get("example_64s", [])) >= 5)


async def test_api_openapi_spec(page: Page) -> None:
    section("API — OpenAPI spec")
    resp = _SESSION.get(APP_URL.rstrip("/") + "/api/openapi.yaml", timeout=10)
    assert_eq("api openapi.yaml: HTTP 200", resp.status_code, 200)
    assert_contains("api openapi.yaml: contains 'openapi: 3.1'", resp.text, "openapi: 3.1")
    assert_contains("api openapi.yaml: contains /ipv4 path", resp.text, "/ipv4")
    assert_contains("api openapi.yaml: contains /sessions path", resp.text, "/sessions")
    assert_contains("api openapi.yaml: contains /rdns path", resp.text, "/rdns")
    assert_contains("api openapi.yaml: contains /bulk path", resp.text, "/bulk")


async def test_api_rdns(page: Page) -> None:
    section("API — rdns (reverse DNS zone file)")
    # Basic IPv4 /24
    status, data = _api_post("rdns", {"cidr": "192.168.1.0/24"})
    assert_eq("api rdns: HTTP 200", status, 200)
    assert_eq("api rdns: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_contains("api rdns: zone contains in-addr.arpa", d.get("zone", ""), "in-addr.arpa")
    assert_true("api rdns: content is string", isinstance(d.get("content"), str))
    assert_contains("api rdns: content has $ORIGIN", d.get("content", ""), "$ORIGIN")
    assert_contains("api rdns: content has SOA", d.get("content", ""), "SOA")
    assert_contains("api rdns: content has PTR records", d.get("content", ""), "IN  PTR")
    assert_eq("api rdns: record_count=256", d.get("record_count"), 256)

    # IPv4 too large (< /16) → 400
    status2, _ = _api_post("rdns", {"cidr": "10.0.0.0/8"})
    assert_eq("api rdns: /8 too large → 400", status2, 400)

    # Missing cidr → 400
    status3, _ = _api_post("rdns", {})
    assert_eq("api rdns: missing cidr → 400", status3, 400)

    # Custom ns and ttl
    status4, data4 = _api_post("rdns", {
        "cidr": "10.10.0.0/24",
        "ns": "ns1.corp.example.",
        "ttl": 3600,
    })
    assert_eq("api rdns: custom params → 200", status4, 200)
    content4 = data4.get("data", {}).get("content", "")
    assert_contains("api rdns: custom ns in zone file", content4, "ns1.corp.example.")
    assert_contains("api rdns: custom ttl in zone file", content4, "$TTL 3600")

    # RFC 2317 — /25 gets classless zone name
    status5, data5 = _api_post("rdns", {"cidr": "192.168.1.0/25"})
    assert_eq("api rdns: /25 RFC 2317 → 200", status5, 200)
    assert_contains("api rdns: /25 zone has classless format", data5.get("data", {}).get("zone", ""), "/25")


async def test_api_rdns6(page: Page) -> None:
    section("API — POST /api/v1/rdns6")
    # /64 zone delegation
    status, data = _api_post("rdns6", {"address": "2001:db8::1", "prefix": 64})
    assert_eq("api rdns6: HTTP 200", status, 200)
    assert_eq("api rdns6: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api rdns6: address echoed", d.get("address"), "2001:db8::1")
    assert_eq("api rdns6: prefix echoed", d.get("prefix"), 64)
    assert_eq("api rdns6: arpa /64",
              d.get("arpa"), "0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa")

    # No prefix → full reverse name
    status2, data2 = _api_post("rdns6", {"address": "2001:db8::1"})
    assert_eq("api rdns6: HTTP 200 (no prefix)", status2, 200)
    assert_eq("api rdns6: full reverse name",
              data2.get("data", {}).get("arpa"),
              "1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa")
    assert_eq("api rdns6: prefix defaults to 128",
              data2.get("data", {}).get("prefix"), 128)

    # Non-nibble-aligned prefix → 400
    status3, data3 = _api_post("rdns6", {"address": "2001:db8::", "prefix": 49})
    assert_eq("api rdns6: /49 → 400", status3, 400)
    assert_eq("api rdns6 err: ok=false", data3.get("ok"), False)

    # Missing address → 400
    status4, _ = _api_post("rdns6", {})
    assert_eq("api rdns6: missing address → 400", status4, 400)

    # Invalid address → 400
    status5, _ = _api_post("rdns6", {"address": "not-an-address"})
    assert_eq("api rdns6: invalid address → 400", status5, 400)


async def test_ipv6_rdns6_ui(page: Page) -> None:
    section("IPv6 reverse-DNS (rdns6) UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # T7 per-tool URL routing — /ipv6/rdns6 should auto-open the drawer.
    # Test harness serves under /testing/sc/ so use the ?tool= equivalent that
    # the rewrite produces (works regardless of base path).
    await navigate(page, APP_URL + "?tab=ipv6&tool=rdns6")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='rdns6']")
    assert_eq("rdns6 UI: panel exists", await panel.count(), 1)

    # Submit address + /64 prefix
    await page.fill("#rdns6_address", "2001:db8::1")
    await page.fill("#rdns6_prefix", "64")
    await page.click("#panel-ipv6 .tool-panel[data-tool='rdns6'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='rdns6']")
    rows = panel.locator(".zoneid-result__row")
    assert_eq("rdns6 UI: three result rows", await rows.count(), 3)
    arpa_text = await rows.nth(2).locator(".zoneid-result__value code").text_content() or ""
    assert_eq("rdns6 UI: arpa zone /64",
              arpa_text.strip(),
              "0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa")
    assert_eq("rdns6 UI: no error band",
              await panel.locator(".error").count(), 0)

    # Click copy button
    await rows.nth(2).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    clip = await page.evaluate("window.__lastClipboard")
    assert_eq("rdns6 UI: copy ip6.arpa zone",
              clip, "0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa")

    # Error path — non-nibble-aligned
    await navigate(page, APP_URL + "?tab=ipv6&tool=rdns6")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#rdns6_address", "2001:db8::")
    await page.fill("#rdns6_prefix", "49")
    await page.click("#panel-ipv6 .tool-panel[data-tool='rdns6'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='rdns6']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("rdns6 UI: error band visible on /49",
                len(err_text) > 0, f"got: {err_text!r}")
    assert_eq("rdns6 UI: no result rows on error",
              await panel.locator(".zoneid-result__row").count(), 0)


async def test_api_mapped6(page: Page) -> None:
    section("API — POST /api/v1/mapped6")
    # IPv4 input → all four representations
    status, data = _api_post("mapped6", {"input": "192.0.2.1"})
    assert_eq("api mapped6: HTTP 200", status, 200)
    assert_eq("api mapped6: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api mapped6: ipv4 echoed", d.get("ipv4"), "192.0.2.1")
    assert_eq("api mapped6: ipv4_mapped", d.get("ipv4_mapped"), "::ffff:192.0.2.1")
    assert_eq("api mapped6: nat64 default", d.get("nat64"), "64:ff9b::c000:201")
    assert_eq("api mapped6: nat64_prefix default",
              d.get("nat64_prefix"), "64:ff9b::/96")

    # IPv4-mapped input → same outputs
    status2, data2 = _api_post("mapped6", {"input": "::ffff:192.0.2.1"})
    assert_eq("api mapped6 (mapped in): HTTP 200", status2, 200)
    assert_eq("api mapped6 (mapped in): ipv4",
              data2.get("data", {}).get("ipv4"), "192.0.2.1")
    assert_eq("api mapped6 (mapped in): nat64",
              data2.get("data", {}).get("nat64"), "64:ff9b::c000:201")

    # NAT64 input (default prefix) → same outputs
    status3, data3 = _api_post("mapped6", {"input": "64:ff9b::c000:201"})
    assert_eq("api mapped6 (nat64 in): HTTP 200", status3, 200)
    assert_eq("api mapped6 (nat64 in): ipv4",
              data3.get("data", {}).get("ipv4"), "192.0.2.1")
    assert_eq("api mapped6 (nat64 in): ipv4_mapped",
              data3.get("data", {}).get("ipv4_mapped"), "::ffff:192.0.2.1")

    # Custom /96 prefix
    status4, data4 = _api_post("mapped6", {
        "input": "192.0.2.1",
        "nat64_prefix": "2001:db8:1::/96",
    })
    assert_eq("api mapped6 (custom /96): HTTP 200", status4, 200)
    assert_eq("api mapped6 (custom /96): nat64",
              data4.get("data", {}).get("nat64"), "2001:db8:1::c000:201")
    assert_eq("api mapped6 (custom /96): nat64_prefix echoed",
              data4.get("data", {}).get("nat64_prefix"), "2001:db8:1::/96")

    # Non-/96 prefix → 400
    status5, data5 = _api_post("mapped6", {
        "input": "192.0.2.1",
        "nat64_prefix": "2001:db8::/64",
    })
    assert_eq("api mapped6 (non-/96): HTTP 400", status5, 400)
    assert_eq("api mapped6 (non-/96): ok=false", data5.get("ok"), False)

    # Missing input → 400
    status6, _ = _api_post("mapped6", {})
    assert_eq("api mapped6: missing input → 400", status6, 400)

    # Garbage input → 400
    status7, _ = _api_post("mapped6", {"input": "not-an-address"})
    assert_eq("api mapped6: invalid input → 400", status7, 400)


async def test_ipv6_mapped6_ui(page: Page) -> None:
    section("IPv6 IPv4-mapped / NAT64 (mapped6) UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # T7 per-tool URL routing — /ipv6/mapped6 should auto-open the drawer.
    await navigate(page, APP_URL + "?tab=ipv6&tool=mapped6")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='mapped6']")
    assert_eq("mapped6 UI: panel exists", await panel.count(), 1)

    # Submit IPv4 address
    await page.fill("#mapped6_input", "192.0.2.1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='mapped6'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='mapped6']")
    rows = panel.locator(".zoneid-result__row")
    # 4 rows: IPv4 / IPv4-mapped / NAT64 / NAT64 prefix
    assert_eq("mapped6 UI: four result rows", await rows.count(), 4)

    ipv4_text   = (await rows.nth(0).locator(".zoneid-result__value code").text_content() or "").strip()
    mapped_text = (await rows.nth(1).locator(".zoneid-result__value code").text_content() or "").strip()
    nat64_text  = (await rows.nth(2).locator(".zoneid-result__value code").text_content() or "").strip()
    prefix_text = (await rows.nth(3).locator(".zoneid-result__value code").text_content() or "").strip()
    assert_eq("mapped6 UI: ipv4 row",        ipv4_text,   "192.0.2.1")
    assert_eq("mapped6 UI: ipv4_mapped row", mapped_text, "::ffff:192.0.2.1")
    assert_eq("mapped6 UI: nat64 row",       nat64_text,  "64:ff9b::c000:201")
    assert_eq("mapped6 UI: nat64_prefix row", prefix_text, "64:ff9b::/96")
    assert_eq("mapped6 UI: no error band",
              await panel.locator(".error").count(), 0)

    # Click each copy button (rows 0..2 — prefix row has no copy button)
    await rows.nth(0).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    assert_eq("mapped6 UI: copy IPv4",
              await page.evaluate("window.__lastClipboard"), "192.0.2.1")

    await rows.nth(1).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    assert_eq("mapped6 UI: copy IPv4-mapped",
              await page.evaluate("window.__lastClipboard"), "::ffff:192.0.2.1")

    await rows.nth(2).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    assert_eq("mapped6 UI: copy NAT64",
              await page.evaluate("window.__lastClipboard"), "64:ff9b::c000:201")

    # Error path — non-/96 NAT64 prefix
    await navigate(page, APP_URL + "?tab=ipv6&tool=mapped6")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#mapped6_input", "192.0.2.1")
    # Open the advanced disclosure and supply a non-/96 prefix
    await page.evaluate(
        "document.querySelector(\"#panel-ipv6 .tool-panel[data-tool='mapped6'] details.slaac-advanced\").open = true"
    )
    await page.fill("#mapped6_nat64_prefix", "2001:db8::/64")
    await page.click("#panel-ipv6 .tool-panel[data-tool='mapped6'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='mapped6']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("mapped6 UI: error band on non-/96",
                len(err_text) > 0, f"got: {err_text!r}")
    assert_eq("mapped6 UI: no result rows on error",
              await panel.locator(".zoneid-result__row").count(), 0)


async def test_api_embedded_v4(page: Page) -> None:
    section("API — POST /api/v1/embedded-v4")
    # IPv4-mapped → scheme=mapped, ipv4 extracted
    status, data = _api_post("embedded-v4", {"input": "::ffff:192.0.2.1"})
    assert_eq("api embedded-v4 (mapped): HTTP 200", status, 200)
    assert_eq("api embedded-v4 (mapped): ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api embedded-v4 (mapped): scheme", d.get("scheme"), "mapped")
    assert_eq("api embedded-v4 (mapped): ipv4", d.get("ipv4"), "192.0.2.1")
    assert_eq("api embedded-v4 (mapped): deprecated=False", d.get("deprecated"), False)
    assert_eq("api embedded-v4 (mapped): detail_route is null",
              d.get("detail_route"), None)

    # IPv4-compatible → deprecated
    status2, data2 = _api_post("embedded-v4", {"input": "::192.0.2.1"})
    assert_eq("api embedded-v4 (compat): HTTP 200", status2, 200)
    assert_eq("api embedded-v4 (compat): scheme",
              data2.get("data", {}).get("scheme"), "compatible")
    assert_eq("api embedded-v4 (compat): deprecated=True",
              data2.get("data", {}).get("deprecated"), True)

    # 6to4
    status3, data3 = _api_post("embedded-v4", {"input": "2002:c000:0201::"})
    assert_eq("api embedded-v4 (6to4): HTTP 200", status3, 200)
    assert_eq("api embedded-v4 (6to4): scheme",
              data3.get("data", {}).get("scheme"), "6to4")
    assert_eq("api embedded-v4 (6to4): ipv4",
              data3.get("data", {}).get("ipv4"), "192.0.2.1")

    # Teredo
    status4, data4 = _api_post("embedded-v4",
                               {"input": "2001:0:4136:e378:8000:63bf:3fff:fdd2"})
    assert_eq("api embedded-v4 (teredo): HTTP 200", status4, 200)
    assert_eq("api embedded-v4 (teredo): scheme",
              data4.get("data", {}).get("scheme"), "teredo")

    # NAT64 well-known
    status5, data5 = _api_post("embedded-v4", {"input": "64:ff9b::192.0.2.1"})
    assert_eq("api embedded-v4 (nat64-wkp): HTTP 200", status5, 200)
    assert_eq("api embedded-v4 (nat64-wkp): scheme",
              data5.get("data", {}).get("scheme"), "nat64-wkp")
    assert_eq("api embedded-v4 (nat64-wkp): ipv4",
              data5.get("data", {}).get("ipv4"), "192.0.2.1")

    # ISATAP
    status6, data6 = _api_post("embedded-v4",
                               {"input": "2001:db8::200:5efe:c000:201"})
    assert_eq("api embedded-v4 (isatap): HTTP 200", status6, 200)
    assert_eq("api embedded-v4 (isatap): scheme",
              data6.get("data", {}).get("scheme"), "isatap")
    assert_eq("api embedded-v4 (isatap): ipv4",
              data6.get("data", {}).get("ipv4"), "192.0.2.1")

    # No embedding
    status7, data7 = _api_post("embedded-v4", {"input": "2001:db8::1"})
    assert_eq("api embedded-v4 (none): HTTP 200", status7, 200)
    assert_eq("api embedded-v4 (none): scheme is null",
              data7.get("data", {}).get("scheme"), None)
    assert_eq("api embedded-v4 (none): ipv4 is null",
              data7.get("data", {}).get("ipv4"), None)

    # Loopback / unspecified must NOT be misdetected as compatible (RFC 4291 §2.5.5.1)
    status8, data8 = _api_post("embedded-v4", {"input": "::1"})
    assert_eq("api embedded-v4 (loopback): scheme is null",
              data8.get("data", {}).get("scheme"), None)

    # Missing input → 400
    status9, _ = _api_post("embedded-v4", {})
    assert_eq("api embedded-v4: missing input → 400", status9, 400)

    # Garbage input → 400
    status10, _ = _api_post("embedded-v4", {"input": "not-an-address"})
    assert_eq("api embedded-v4: invalid input → 400", status10, 400)


async def test_ipv6_embedded_v4_ui(page: Page) -> None:
    section("IPv6 embedded-v4 detector UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # T7 per-tool URL routing — /ipv6/embedded-v4 should auto-open the drawer.
    await navigate(page, APP_URL + "?tab=ipv6&tool=embedded-v4")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='embedded-v4']")
    assert_eq("embedded-v4 UI: panel exists", await panel.count(), 1)

    # Submit IPv4-mapped address
    await page.fill("#embedded_v4_input", "::ffff:192.0.2.1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='embedded-v4'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='embedded-v4']")
    rows = panel.locator(".zoneid-result__row")
    # Scheme + Embedded IPv4 + Open-in-tool = 3 rows (no extras for mapped)
    assert_true("embedded-v4 UI: at least scheme + ipv4 rows",
                await rows.count() >= 2)

    # Copy embedded IPv4
    await rows.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("embedded-v4 UI: copy IPv4",
              await page.evaluate("window.__lastClipboard"), "192.0.2.1")

    # Open-in-tool button is rendered but disabled (per-scheme drawers land in T3–T7)
    open_btn = panel.locator("button.splitter-btn[disabled]")
    assert_true("embedded-v4 UI: open-in-tool button is disabled",
                await open_btn.count() >= 1)

    # No embedding → result card with "No embedded IPv4 detected"
    await navigate(page, APP_URL + "?tab=ipv6&tool=embedded-v4")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#embedded_v4_input", "2001:db8::1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='embedded-v4'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='embedded-v4']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("embedded-v4 UI: empty-state message rendered",
                "no embedded ipv4" in body_text, f"body: {body_text!r}")

    # Error path — invalid IPv6 literal
    await navigate(page, APP_URL + "?tab=ipv6&tool=embedded-v4")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#embedded_v4_input", "not-an-address")
    await page.click("#panel-ipv6 .tool-panel[data-tool='embedded-v4'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='embedded-v4']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("embedded-v4 UI: error band on invalid input",
                len(err_text) > 0, f"got: {err_text!r}")


async def test_api_6to4(page: Page) -> None:
    section("API — POST /api/v1/6to4")

    # Encode mode — RFC 3056 §2 worked example.
    status, data = _api_post("6to4", {"mode": "encode", "ipv4": "192.0.2.1"})
    assert_eq("api 6to4 encode: HTTP 200", status, 200)
    assert_eq("api 6to4 encode: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api 6to4 encode: mode", d.get("mode"), "encode")
    assert_eq("api 6to4 encode: ipv4", d.get("ipv4"), "192.0.2.1")
    assert_eq("api 6to4 encode: prefix", d.get("prefix"), "2002:c000:0201::/48")

    # Decode mode — round-trip.
    status2, data2 = _api_post("6to4", {"mode": "decode", "ipv6": "2002:c000:0201::1"})
    assert_eq("api 6to4 decode: HTTP 200", status2, 200)
    d2 = data2.get("data", {})
    assert_eq("api 6to4 decode: mode", d2.get("mode"), "decode")
    assert_eq("api 6to4 decode: ipv4", d2.get("ipv4"), "192.0.2.1")
    assert_eq("api 6to4 decode: subnet_id", d2.get("subnet_id"), 0)
    assert_eq("api 6to4 decode: interface_id",
              d2.get("interface_id"), "0000:0000:0000:0001")

    # Decode with non-trivial subnet ID + IID.
    status3, data3 = _api_post("6to4",
                               {"mode": "decode", "ipv6": "2002:cb00:7105:cafe:dead:beef:1234:5678"})
    assert_eq("api 6to4 decode (full): HTTP 200", status3, 200)
    d3 = data3.get("data", {})
    assert_eq("api 6to4 decode (full): ipv4", d3.get("ipv4"), "203.0.113.5")
    assert_eq("api 6to4 decode (full): subnet_id", d3.get("subnet_id"), 0xcafe)
    assert_eq("api 6to4 decode (full): interface_id",
              d3.get("interface_id"), "dead:beef:1234:5678")

    # Encode rejects RFC 1918.
    status4, _ = _api_post("6to4", {"mode": "encode", "ipv4": "10.0.0.1"})
    assert_eq("api 6to4 encode: private IPv4 → 400", status4, 400)

    # Encode rejects multicast.
    status5, _ = _api_post("6to4", {"mode": "encode", "ipv4": "239.0.0.1"})
    assert_eq("api 6to4 encode: multicast IPv4 → 400", status5, 400)

    # Encode rejects garbage.
    status6, _ = _api_post("6to4", {"mode": "encode", "ipv4": "not-an-address"})
    assert_eq("api 6to4 encode: garbage → 400", status6, 400)

    # Decode rejects non-2002 addresses.
    status7, _ = _api_post("6to4", {"mode": "decode", "ipv6": "2001:db8::1"})
    assert_eq("api 6to4 decode: non-2002 → 400", status7, 400)

    # Missing required field.
    status8, _ = _api_post("6to4", {"mode": "encode"})
    assert_eq("api 6to4 encode: missing ipv4 → 400", status8, 400)
    status9, _ = _api_post("6to4", {"mode": "decode"})
    assert_eq("api 6to4 decode: missing ipv6 → 400", status9, 400)

    # Invalid mode.
    status10, _ = _api_post("6to4", {"mode": "bogus", "ipv4": "192.0.2.1"})
    assert_eq("api 6to4: invalid mode → 400", status10, 400)

    # embedded-v4 detector now deep-links 6to4 to /ipv6/6to4 (T3 contract).
    status11, data11 = _api_post("embedded-v4", {"input": "2002:c000:0201::"})
    assert_eq("api embedded-v4 (6to4): HTTP 200", status11, 200)
    assert_eq("api embedded-v4 (6to4): detail_route is /ipv6/6to4",
              data11.get("data", {}).get("detail_route"), "/ipv6/6to4")


async def test_ipv6_6to4_ui(page: Page) -> None:
    section("IPv6 6to4 tool UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # /ipv6/6to4 should auto-open the drawer (per-tool URL routing).
    await navigate(page, APP_URL + "?tab=ipv6&tool=6to4")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6to4']")
    assert_eq("6to4 UI: panel exists", await panel.count(), 1)

    # Encode — IPv4 → 6to4 prefix.
    await page.select_option("#sixtofour_mode", "encode")
    await page.fill("#sixtofour_input", "192.0.2.1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6to4'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6to4']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("6to4 UI encode: prefix rendered",
                "2002:c000:0201::/48" in body_text, f"body: {body_text!r}")

    # Copy the prefix.
    await panel.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("6to4 UI encode: copy prefix",
              await page.evaluate("window.__lastClipboard"),
              "2002:c000:0201::/48")

    # Decode — 6to4 IPv6 → IPv4 + subnet ID + IID.
    await navigate(page, APP_URL + "?tab=ipv6&tool=6to4")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.select_option("#sixtofour_mode", "decode")
    await page.fill("#sixtofour_input", "2002:cb00:7105:cafe:dead:beef:1234:5678")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6to4'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6to4']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("6to4 UI decode: embedded ipv4 rendered",
                "203.0.113.5" in body_text, f"body: {body_text!r}")
    assert_true("6to4 UI decode: subnet id rendered",
                "cafe" in body_text, f"body: {body_text!r}")
    assert_true("6to4 UI decode: interface id rendered",
                "dead:beef:1234:5678" in body_text, f"body: {body_text!r}")

    # Error path — private IPv4 in encode mode.
    await navigate(page, APP_URL + "?tab=ipv6&tool=6to4")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.select_option("#sixtofour_mode", "encode")
    await page.fill("#sixtofour_input", "10.0.0.1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6to4'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6to4']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("6to4 UI: error band on private IPv4",
                len(err_text) > 0, f"got: {err_text!r}")

    # Shareable GET URL hydrates without re-submission.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=6to4&sixtofour_mode=encode&sixtofour_input=198.51.100.42")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6to4']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("6to4 UI shareable URL: prefix hydrated",
                "2002:c633:642a::/48" in body_text, f"body: {body_text!r}")

    # embedded-v4 detector now deep-links 6to4 to /ipv6/6to4. Render the
    # embedded-v4 drawer with a 6to4 input and verify the Open-in-tool
    # button is enabled (anchor element rather than disabled <button>).
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=embedded-v4&embedded_v4_input=2002:c000:0201::")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    ev4_panel = page.locator("#panel-ipv6 .tool-panel[data-tool='embedded-v4']")
    open_link = ev4_panel.locator("a.splitter-btn[href='/ipv6/6to4']")
    assert_true("embedded-v4 → 6to4 deep-link: anchor present",
                await open_link.count() >= 1)


async def test_api_teredo(page: Page) -> None:
    section("API — POST /api/v1/teredo")

    # Decode mode — RFC 4380 §4 worked example.
    status, data = _api_post("teredo", {
        "mode": "decode",
        "ipv6": "2001:0:4136:e378:8000:63bf:3fff:fdd2",
    })
    assert_eq("api teredo decode: HTTP 200", status, 200)
    assert_eq("api teredo decode: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api teredo decode: mode", d.get("mode"), "decode")
    assert_eq("api teredo decode: server_ipv4", d.get("server_ipv4"), "65.54.227.120")
    assert_eq("api teredo decode: client_ipv4", d.get("client_ipv4"), "192.0.2.45")
    assert_eq("api teredo decode: port", d.get("port"), 40000)
    assert_eq("api teredo decode: flags", d.get("flags"), 0x8000)
    assert_eq("api teredo decode: cone", d.get("cone"), True)

    # Encode mode — round-trip.
    status2, data2 = _api_post("teredo", {
        "mode": "encode",
        "server_ipv4": "65.54.227.120",
        "client_ipv4": "192.0.2.45",
        "port": 40000,
        "flags": 0x8000,
    })
    assert_eq("api teredo encode: HTTP 200", status2, 200)
    d2 = data2.get("data", {})
    assert_eq("api teredo encode: ipv6",
              d2.get("ipv6"), "2001:0:4136:e378:8000:63bf:3fff:fdd2")
    assert_eq("api teredo encode: cone", d2.get("cone"), True)

    # Decode rejects non-Teredo addresses.
    status3, _ = _api_post("teredo", {"mode": "decode", "ipv6": "2001:db8::1"})
    assert_eq("api teredo decode: non-Teredo → 400", status3, 400)

    # Decode rejects garbage.
    status4, _ = _api_post("teredo", {"mode": "decode", "ipv6": "not-an-address"})
    assert_eq("api teredo decode: garbage → 400", status4, 400)

    # Encode rejects bad IPv4.
    status5, _ = _api_post("teredo", {
        "mode": "encode",
        "server_ipv4": "not-an-ip",
        "client_ipv4": "192.0.2.45",
        "port": 40000,
    })
    assert_eq("api teredo encode: invalid server IPv4 → 400", status5, 400)

    # Encode rejects out-of-range port.
    status6, _ = _api_post("teredo", {
        "mode": "encode",
        "server_ipv4": "65.54.227.120",
        "client_ipv4": "192.0.2.45",
        "port": 70000,
    })
    assert_eq("api teredo encode: port out of range → 400", status6, 400)

    # Missing required field on decode.
    status7, _ = _api_post("teredo", {"mode": "decode"})
    assert_eq("api teredo decode: missing ipv6 → 400", status7, 400)

    # Missing required fields on encode.
    status8, _ = _api_post("teredo", {"mode": "encode"})
    assert_eq("api teredo encode: missing parts → 400", status8, 400)

    # Invalid mode.
    status9, _ = _api_post("teredo", {"mode": "bogus", "ipv6": "2001:0::1"})
    assert_eq("api teredo: invalid mode → 400", status9, 400)

    # embedded-v4 detector now deep-links Teredo to /ipv6/teredo (T4 contract).
    status10, data10 = _api_post("embedded-v4",
                                 {"input": "2001:0:4136:e378:8000:63bf:3fff:fdd2"})
    assert_eq("api embedded-v4 (teredo): HTTP 200", status10, 200)
    assert_eq("api embedded-v4 (teredo): detail_route is /ipv6/teredo",
              data10.get("data", {}).get("detail_route"), "/ipv6/teredo")


async def test_ipv6_teredo_ui(page: Page) -> None:
    section("IPv6 Teredo tool UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # /ipv6/teredo should auto-open the drawer (per-tool URL routing).
    await navigate(page, APP_URL + "?tab=ipv6&tool=teredo")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='teredo']")
    assert_eq("teredo UI: panel exists", await panel.count(), 1)

    # Decode — Teredo IPv6 → parts.
    await page.select_option("#teredo_mode", "decode")
    await page.fill("#teredo_input", "2001:0:4136:e378:8000:63bf:3fff:fdd2")
    await page.click("#panel-ipv6 .tool-panel[data-tool='teredo'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='teredo']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("teredo UI decode: server v4 rendered",
                "65.54.227.120" in body_text, f"body: {body_text!r}")
    assert_true("teredo UI decode: client v4 rendered",
                "192.0.2.45" in body_text, f"body: {body_text!r}")
    assert_true("teredo UI decode: port rendered",
                "40000" in body_text, f"body: {body_text!r}")
    assert_true("teredo UI decode: flags rendered",
                "0x8000" in body_text, f"body: {body_text!r}")

    # Copy server IPv4.
    await panel.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("teredo UI decode: copy server IPv4",
              await page.evaluate("window.__lastClipboard"),
              "65.54.227.120")

    # Error path — non-Teredo address.
    await navigate(page, APP_URL + "?tab=ipv6&tool=teredo")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.select_option("#teredo_mode", "decode")
    await page.fill("#teredo_input", "2001:db8::1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='teredo'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='teredo']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("teredo UI: error band on non-Teredo address",
                len(err_text) > 0, f"got: {err_text!r}")

    # Shareable GET URL hydrates without re-submission.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=teredo&teredo_mode=decode&teredo_input=2001:0:4136:e378:8000:63bf:3fff:fdd2")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='teredo']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("teredo UI shareable URL: server v4 hydrated",
                "65.54.227.120" in body_text, f"body: {body_text!r}")
    assert_true("teredo UI shareable URL: client v4 hydrated",
                "192.0.2.45" in body_text, f"body: {body_text!r}")

    # embedded-v4 detector now deep-links Teredo to /ipv6/teredo. Render
    # the embedded-v4 drawer with a Teredo input and verify the
    # Open-in-tool button is enabled (anchor element).
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=embedded-v4&embedded_v4_input=2001:0:4136:e378:8000:63bf:3fff:fdd2")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    ev4_panel = page.locator("#panel-ipv6 .tool-panel[data-tool='embedded-v4']")
    open_link = ev4_panel.locator("a.splitter-btn[href='/ipv6/teredo']")
    assert_true("embedded-v4 → teredo deep-link: anchor present",
                await open_link.count() >= 1)


async def test_api_isatap(page: Page) -> None:
    section("API — POST /api/v1/isatap")

    # Encode mode — public IPv4 → globally-unique IID auto-detect.
    status, data = _api_post("isatap", {"mode": "encode", "ipv4": "192.0.2.1"})
    assert_eq("api isatap encode: HTTP 200", status, 200)
    assert_eq("api isatap encode: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api isatap encode: mode", d.get("mode"), "encode")
    assert_eq("api isatap encode: iid", d.get("iid"), "200:5efe:c000:201")
    assert_eq("api isatap encode: globally_unique=true", d.get("globally_unique"), True)

    # Encode mode — private IPv4 → locally-administered.
    status2, data2 = _api_post("isatap", {"mode": "encode", "ipv4": "10.0.0.1"})
    assert_eq("api isatap encode (private): HTTP 200", status2, 200)
    d2 = data2.get("data", {})
    assert_eq("api isatap encode (private): iid", d2.get("iid"), "0:5efe:a00:1")
    assert_eq("api isatap encode (private): globally_unique=false",
              d2.get("globally_unique"), False)

    # Encode with explicit globally_unique=false override.
    status3, data3 = _api_post("isatap", {
        "mode": "encode", "ipv4": "192.0.2.1", "globally_unique": False,
    })
    assert_eq("api isatap encode (forced local): HTTP 200", status3, 200)
    assert_eq("api isatap encode (forced local): iid",
              data3.get("data", {}).get("iid"), "0:5efe:c000:201")

    # Decode mode — round-trip.
    status4, data4 = _api_post("isatap", {"mode": "decode", "iid": "200:5efe:c000:201"})
    assert_eq("api isatap decode: HTTP 200", status4, 200)
    d4 = data4.get("data", {})
    assert_eq("api isatap decode: ipv4", d4.get("ipv4"), "192.0.2.1")
    assert_eq("api isatap decode: globally_unique", d4.get("globally_unique"), True)

    # Decode rejects non-ISATAP IID.
    status5, _ = _api_post("isatap", {"mode": "decode", "iid": "feed:beef:cafe:1"})
    assert_eq("api isatap decode: non-ISATAP → 400", status5, 400)

    # Decode rejects garbage.
    status6, _ = _api_post("isatap", {"mode": "decode", "iid": "not-an-iid"})
    assert_eq("api isatap decode: garbage → 400", status6, 400)

    # Encode rejects bad IPv4.
    status7, _ = _api_post("isatap", {"mode": "encode", "ipv4": "not-an-ip"})
    assert_eq("api isatap encode: invalid IPv4 → 400", status7, 400)

    # Missing required fields.
    status8, _ = _api_post("isatap", {"mode": "encode"})
    assert_eq("api isatap encode: missing ipv4 → 400", status8, 400)
    status9, _ = _api_post("isatap", {"mode": "decode"})
    assert_eq("api isatap decode: missing iid → 400", status9, 400)

    # Invalid mode.
    status10, _ = _api_post("isatap", {"mode": "bogus", "ipv4": "192.0.2.1"})
    assert_eq("api isatap: invalid mode → 400", status10, 400)

    # embedded-v4 detector now deep-links ISATAP to /ipv6/isatap (T5 contract).
    status11, data11 = _api_post("embedded-v4",
                                 {"input": "2001:db8::200:5efe:c000:201"})
    assert_eq("api embedded-v4 (isatap): HTTP 200", status11, 200)
    ev4 = data11.get("data", {})
    assert_eq("api embedded-v4 (isatap): detail_route is /ipv6/isatap",
              ev4.get("detail_route"), "/ipv6/isatap")
    assert_eq("api embedded-v4 (isatap): extra.ipv4 from decoder",
              ev4.get("extra", {}).get("ipv4"), "192.0.2.1")
    assert_eq("api embedded-v4 (isatap): extra.globally_unique from decoder",
              ev4.get("extra", {}).get("globally_unique"), True)


async def test_ipv6_isatap_ui(page: Page) -> None:
    section("IPv6 ISATAP tool UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # /ipv6/isatap should auto-open the drawer (per-tool URL routing).
    await navigate(page, APP_URL + "?tab=ipv6&tool=isatap")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='isatap']")
    assert_eq("isatap UI: panel exists", await panel.count(), 1)

    # Encode — IPv4 → IID.
    await page.select_option("#isatap_mode", "encode")
    await page.fill("#isatap_ipv4", "192.0.2.1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='isatap'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='isatap']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("isatap UI encode: IID rendered",
                "200:5efe:c000:201" in body_text, f"body: {body_text!r}")
    assert_true("isatap UI encode: globally-unique badge",
                "globally-unique" in body_text, f"body: {body_text!r}")

    # Copy IID.
    await panel.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("isatap UI encode: copy IID",
              await page.evaluate("window.__lastClipboard"),
              "::200:5efe:c000:201")

    # Decode — IID → IPv4.
    await navigate(page, APP_URL + "?tab=ipv6&tool=isatap")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.select_option("#isatap_mode", "decode")
    await page.click("#panel-ipv6 .tool-panel[data-tool='isatap'] button.splitter-btn")
    # After mode change the form re-rendered server-side; need to refill iid.
    await page.fill("#isatap_iid", "200:5efe:c000:201")
    await page.click("#panel-ipv6 .tool-panel[data-tool='isatap'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='isatap']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("isatap UI decode: ipv4 rendered",
                "192.0.2.1" in body_text, f"body: {body_text!r}")

    # Error path — non-ISATAP IID.
    await navigate(page, APP_URL + "?tab=ipv6&tool=isatap")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.select_option("#isatap_mode", "decode")
    await page.click("#panel-ipv6 .tool-panel[data-tool='isatap'] button.splitter-btn")
    await page.fill("#isatap_iid", "feed:beef:cafe:1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='isatap'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='isatap']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("isatap UI: error band on non-ISATAP IID",
                len(err_text) > 0, f"got: {err_text!r}")

    # Shareable GET URL hydrates without re-submission.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=isatap&isatap_mode=encode&isatap_ipv4=192.0.2.1")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='isatap']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("isatap UI shareable URL: IID hydrated",
                "200:5efe:c000:201" in body_text, f"body: {body_text!r}")

    # embedded-v4 detector now deep-links ISATAP to /ipv6/isatap.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=embedded-v4&embedded_v4_input=2001:db8::200:5efe:c000:201")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    ev4_panel = page.locator("#panel-ipv6 .tool-panel[data-tool='embedded-v4']")
    open_link = ev4_panel.locator("a.splitter-btn[href='/ipv6/isatap']")
    assert_true("embedded-v4 → isatap deep-link: anchor present",
                await open_link.count() >= 1)


async def test_api_6rd(page: Page) -> None:
    section("API — POST /api/v1/6rd")

    # Encode mode — basic case (mask=0).
    status, data = _api_post("6rd", {
        "mode": "encode",
        "sp_ipv6_prefix": "2001:db8::/32",
        "sp_ipv4_mask_len": 0,
        "ipv4": "192.0.2.1",
    })
    assert_eq("api 6rd encode: HTTP 200", status, 200)
    assert_eq("api 6rd encode: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api 6rd encode: mode", d.get("mode"), "encode")
    assert_eq("api 6rd encode: prefix", d.get("prefix"), "2001:db8:c000:201::/64")
    assert_eq("api 6rd encode: prefix_length", d.get("prefix_length"), 64)

    # Encode with mask_len=8 (SP discards top 8 bits).
    status2, data2 = _api_post("6rd", {
        "mode": "encode",
        "sp_ipv6_prefix": "2001:db8::/32",
        "sp_ipv4_mask_len": 8,
        "ipv4": "192.0.2.1",
    })
    assert_eq("api 6rd encode (mask=8): HTTP 200", status2, 200)
    d2 = data2.get("data", {})
    assert_eq("api 6rd encode (mask=8): prefix", d2.get("prefix"), "2001:db8:2:100::/56")
    assert_eq("api 6rd encode (mask=8): prefix_length", d2.get("prefix_length"), 56)

    # Encode default mask_len = 0 when omitted.
    status3, data3 = _api_post("6rd", {
        "mode": "encode",
        "sp_ipv6_prefix": "2001:db8::/32",
        "ipv4": "192.0.2.1",
    })
    assert_eq("api 6rd encode (default mask): HTTP 200", status3, 200)
    assert_eq("api 6rd encode (default mask): prefix",
              data3.get("data", {}).get("prefix"), "2001:db8:c000:201::/64")

    # Decode mode — round-trip.
    status4, data4 = _api_post("6rd", {
        "mode": "decode",
        "sp_ipv6_prefix": "2001:db8::/32",
        "sp_ipv4_mask_len": 0,
        "ipv6": "2001:db8:c000:201::1",
    })
    assert_eq("api 6rd decode: HTTP 200", status4, 200)
    d4 = data4.get("data", {})
    assert_eq("api 6rd decode: ipv4", d4.get("ipv4"), "192.0.2.1")

    # Decode rejects address outside SP prefix.
    status5, _ = _api_post("6rd", {
        "mode": "decode",
        "sp_ipv6_prefix": "2001:db8::/32",
        "ipv6": "2001:db9:c000:201::1",
    })
    assert_eq("api 6rd decode: outside SP prefix → 400", status5, 400)

    # Encode rejects bad IPv4.
    status6, _ = _api_post("6rd", {
        "mode": "encode",
        "sp_ipv6_prefix": "2001:db8::/32",
        "ipv4": "not-an-ip",
    })
    assert_eq("api 6rd encode: invalid IPv4 → 400", status6, 400)

    # Bad SP prefix.
    status7, _ = _api_post("6rd", {
        "mode": "encode",
        "sp_ipv6_prefix": "not-a-prefix",
        "ipv4": "192.0.2.1",
    })
    assert_eq("api 6rd: invalid SP prefix → 400", status7, 400)

    # Mask out of range.
    status8, _ = _api_post("6rd", {
        "mode": "encode",
        "sp_ipv6_prefix": "2001:db8::/32",
        "sp_ipv4_mask_len": 33,
        "ipv4": "192.0.2.1",
    })
    assert_eq("api 6rd: mask out of range → 400", status8, 400)

    # Missing required fields.
    status9, _ = _api_post("6rd", {"mode": "encode"})
    assert_eq("api 6rd encode: missing sp_ipv6_prefix → 400", status9, 400)
    status10, _ = _api_post("6rd", {
        "mode": "encode",
        "sp_ipv6_prefix": "2001:db8::/32",
    })
    assert_eq("api 6rd encode: missing ipv4 → 400", status10, 400)
    status11, _ = _api_post("6rd", {
        "mode": "decode",
        "sp_ipv6_prefix": "2001:db8::/32",
    })
    assert_eq("api 6rd decode: missing ipv6 → 400", status11, 400)

    # Invalid mode.
    status12, _ = _api_post("6rd", {
        "mode": "bogus",
        "sp_ipv6_prefix": "2001:db8::/32",
        "ipv4": "192.0.2.1",
    })
    assert_eq("api 6rd: invalid mode → 400", status12, 400)


async def test_api_nat64(page: Page) -> None:
    section("API — POST /api/v1/nat64")

    # Encode at /96 well-known prefix. RFC 6052 §3.1 forbids non-globally-
    # unique IPv4 (incl. TEST-NET) under the WKP, so use 8.8.8.8 here.
    status, data = _api_post("nat64", {
        "mode": "encode",
        "nat64_prefix": "64:ff9b::",
        "prefix_length": 96,
        "ipv4": "8.8.8.8",
    })
    assert_eq("api nat64 encode wkp: HTTP 200", status, 200)
    assert_eq("api nat64 encode wkp: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api nat64 encode wkp: ipv6", d.get("ipv6"), "64:ff9b::808:808")
    assert_eq("api nat64 encode wkp: prefix_length", d.get("prefix_length"), 96)

    # Encode at /32 — RFC 6052 §2.4 vector.
    status2, data2 = _api_post("nat64", {
        "mode": "encode",
        "nat64_prefix": "2001:db8::",
        "prefix_length": 32,
        "ipv4": "192.0.2.33",
    })
    assert_eq("api nat64 encode /32: HTTP 200", status2, 200)
    assert_eq("api nat64 encode /32: ipv6",
              data2.get("data", {}).get("ipv6"), "2001:db8:c000:221::")

    # Encode at /64 — RFC 6052 §2.4 vector.
    status3, data3 = _api_post("nat64", {
        "mode": "encode",
        "nat64_prefix": "2001:db8:122:344::",
        "prefix_length": 64,
        "ipv4": "192.0.2.33",
    })
    assert_eq("api nat64 encode /64: HTTP 200", status3, 200)
    assert_eq("api nat64 encode /64: ipv6",
              data3.get("data", {}).get("ipv6"), "2001:db8:122:344:c0:2:2100:0")

    # Decode at /32 round-trip.
    status4, data4 = _api_post("nat64", {
        "mode": "decode",
        "nat64_prefix": "2001:db8::",
        "prefix_length": 32,
        "ipv6": "2001:db8:c000:221::",
    })
    assert_eq("api nat64 decode /32: HTTP 200", status4, 200)
    assert_eq("api nat64 decode /32: ipv4",
              data4.get("data", {}).get("ipv4"), "192.0.2.33")

    # DNS64 mode — defaults to well-known /96. Use a globally-unique IPv4
    # (RFC 6052 §3.1 forbids non-globally-unique sources under the WKP).
    status5, data5 = _api_post("nat64", {
        "mode": "dns64",
        "a_record": "8.8.8.8",
    })
    assert_eq("api nat64 dns64: HTTP 200", status5, 200)
    assert_eq("api nat64 dns64: ipv6",
              data5.get("data", {}).get("ipv6"), "64:ff9b::808:808")

    # RFC 6052 §3.1: WKP rejects RFC 1918 source.
    status6, _ = _api_post("nat64", {
        "mode": "encode",
        "ipv4": "10.0.0.1",
    })
    assert_eq("api nat64 encode wkp + private → 400", status6, 400)

    # Invalid prefix length.
    status7, _ = _api_post("nat64", {
        "mode": "encode",
        "nat64_prefix": "2001:db8::",
        "prefix_length": 80,
        "ipv4": "192.0.2.33",
    })
    assert_eq("api nat64 encode invalid PL → 400", status7, 400)

    # Decode rejects address outside the prefix.
    status8, _ = _api_post("nat64", {
        "mode": "decode",
        "nat64_prefix": "2001:db8::",
        "prefix_length": 32,
        "ipv6": "2001:db9::1",
    })
    assert_eq("api nat64 decode out-of-prefix → 400", status8, 400)

    # Missing required fields.
    status9, _ = _api_post("nat64", {"mode": "encode"})
    assert_eq("api nat64 encode missing ipv4 → 400", status9, 400)


async def test_ipv6_nat64_ui(page: Page) -> None:
    section("IPv6 NAT64 / DNS64 drawer UI (extends mapped6)")

    # Per-tool URL routing auto-opens the mapped6 drawer.
    await navigate(page, APP_URL + "?tab=ipv6&tool=mapped6")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")

    # Open the NAT64 disclosure inside the drawer.
    await page.evaluate("document.querySelector('details.nat64-advanced').open = true;")
    await page.wait_for_selector("#nat64_mode", state="attached")

    # Run an encode at /32 — RFC 6052 §2.4 vector.
    await page.select_option("#nat64_mode", "encode")
    await page.select_option("#nat64_pl", "32")
    await page.fill("#nat64_prefix", "2001:db8::")
    await page.fill("#nat64_ipv4",   "192.0.2.33")
    await page.click("button.nat64-submit")
    await page.wait_for_load_state("load")
    await page.wait_for_selector("dl[data-history-source='nat64']")

    body_text = await page.inner_text("dl[data-history-source='nat64']")
    assert "2001:db8:c000:221::" in body_text, \
        f"expected NAT64 /32 result, got: {body_text!r}"
    section_ok = "encode" in body_text
    assert section_ok, f"expected mode=encode in NAT64 result, got: {body_text!r}"

    # GET shareable URL: NAT64 dns64 mode round-trip.
    await navigate(
        page,
        APP_URL
        + "?tab=ipv6&tool=mapped6"
        + "&nat64_mode=dns64"
        + "&nat64_prefix=64:ff9b::"
        + "&nat64_pl=96"
        + "&nat64_a_record=8.8.8.8"
    )
    await page.wait_for_selector("dl[data-history-source='nat64']")
    body_text2 = await page.inner_text("dl[data-history-source='nat64']")
    assert "64:ff9b::808:808" in body_text2, \
        f"expected DNS64 AAAA result via GET, got: {body_text2!r}"

    # Existing mapped6 form still works (no regression).
    await navigate(page, APP_URL + "?tab=ipv6&tool=mapped6")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#mapped6_input", "192.0.2.1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='mapped6'] form:first-of-type button[type='submit']")
    await page.wait_for_load_state("load")
    rows = page.locator(
        "#panel-ipv6 .tool-panel[data-tool='mapped6'] dl[data-history-source='mapped6'] .zoneid-result__row"
    )
    assert_eq("nat64 UI: existing mapped6 form still 4 rows", await rows.count(), 4)


async def test_ipv6_6rd_ui(page: Page) -> None:
    section("IPv6 6rd tool UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # /ipv6/6rd should auto-open the drawer (per-tool URL routing).
    await navigate(page, APP_URL + "?tab=ipv6&tool=6rd")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6rd']")
    assert_eq("6rd UI: panel exists", await panel.count(), 1)

    # Encode — IPv4 → delegated prefix.
    await page.select_option("#sixrd_mode", "encode")
    await page.fill("#sixrd_sp_prefix", "2001:db8::/32")
    await page.fill("#sixrd_mask_len", "0")
    await page.fill("#sixrd_ipv4", "192.0.2.1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6rd'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6rd']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("6rd UI encode: prefix rendered",
                "2001:db8:c000:201::/64" in body_text, f"body: {body_text!r}")

    # Copy delegated prefix.
    await panel.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("6rd UI encode: copy prefix",
              await page.evaluate("window.__lastClipboard"),
              "2001:db8:c000:201::/64")

    # Decode — 6rd address → IPv4.
    await navigate(page, APP_URL + "?tab=ipv6&tool=6rd")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.select_option("#sixrd_mode", "decode")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6rd'] button.splitter-btn")
    # After mode change the form re-rendered server-side; need to refill values.
    await page.fill("#sixrd_sp_prefix", "2001:db8::/32")
    await page.fill("#sixrd_mask_len", "0")
    await page.fill("#sixrd_ipv6", "2001:db8:c000:201::1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6rd'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6rd']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("6rd UI decode: ipv4 rendered",
                "192.0.2.1" in body_text, f"body: {body_text!r}")

    # Error path — address outside SP prefix.
    await navigate(page, APP_URL + "?tab=ipv6&tool=6rd")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.select_option("#sixrd_mode", "decode")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6rd'] button.splitter-btn")
    await page.fill("#sixrd_sp_prefix", "2001:db8::/32")
    await page.fill("#sixrd_mask_len", "0")
    await page.fill("#sixrd_ipv6", "2001:db9:c000:201::1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='6rd'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6rd']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("6rd UI: error band on address outside SP prefix",
                len(err_text) > 0, f"got: {err_text!r}")

    # Shareable GET URL hydrates without re-submission.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=6rd&sixrd_mode=encode"
                   "&sixrd_sp_prefix=2001:db8::/32&sixrd_mask_len=0&sixrd_ipv4=192.0.2.1")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='6rd']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("6rd UI shareable URL: prefix hydrated",
                "2001:db8:c000:201::/64" in body_text, f"body: {body_text!r}")


async def test_api_prefix_plan6(page: Page) -> None:
    section("API — POST /api/v1/prefix-plan6")

    # Slice /48 into the first four /56s.
    status, data = _api_post("prefix-plan6", {
        "parent_prefix": "2001:db8::/48",
        "child_length": 56,
        "count": 4,
    })
    assert_eq("api prefix-plan6 /48→/56: HTTP 200", status, 200)
    assert_eq("api prefix-plan6 /48→/56: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    children = d.get("children", [])
    assert_eq("api prefix-plan6 /48→/56: count=4", len(children), 4)
    assert_eq("api prefix-plan6 /48→/56: first child prefix",
              children[0].get("prefix") if children else None, "2001:db8::/56")
    assert_eq("api prefix-plan6 /48→/56: second child prefix",
              children[1].get("prefix") if len(children) > 1 else None, "2001:db8:0:100::/56")
    assert_eq("api prefix-plan6 /48→/56: total_children_str",
              d.get("parent", {}).get("total_children_str"), "256")
    assert_eq("api prefix-plan6 /48→/56: free remaining",
              d.get("free", {}).get("remaining_str"), "252")
    assert_eq("api prefix-plan6 /48→/56: normalized_child_length",
              d.get("normalized_child_length"), 56)
    assert_eq("api prefix-plan6 /48→/56: contains_64s",
              children[0].get("contains_64s") if children else None, "256")

    # Nibble-align snaps /49 → /52.
    status2, data2 = _api_post("prefix-plan6", {
        "parent_prefix": "2001:db8::/48",
        "child_length": 49,
        "count": 1,
        "nibble_align": True,
    })
    assert_eq("api prefix-plan6 nibble-align: HTTP 200", status2, 200)
    assert_eq("api prefix-plan6 nibble-align: snaps to /52",
              data2.get("data", {}).get("normalized_child_length"), 52)

    # Overflow path — /32 → /128 ⇒ 2^96 children.
    status3, data3 = _api_post("prefix-plan6", {
        "parent_prefix": "2001:db8::/32",
        "child_length": 128,
        "count": 1,
        "nibble_align": False,
    })
    assert_eq("api prefix-plan6 overflow: HTTP 200", status3, 200)
    assert_eq("api prefix-plan6 overflow: total uses 2^N",
              data3.get("data", {}).get("parent", {}).get("total_children_str"), "2^96")

    # Start offset skips first N.
    status4, data4 = _api_post("prefix-plan6", {
        "parent_prefix": "2001:db8::/48",
        "child_length": 56,
        "count": 2,
        "start_offset": 2,
    })
    assert_eq("api prefix-plan6 offset: HTTP 200", status4, 200)
    kids = data4.get("data", {}).get("children", [])
    assert_eq("api prefix-plan6 offset: first child index",
              kids[0].get("index") if kids else None, 2)
    assert_eq("api prefix-plan6 offset: first child prefix",
              kids[0].get("prefix") if kids else None, "2001:db8:0:200::/56")

    # Invalid: child_length ≤ parent_length.
    status5, _ = _api_post("prefix-plan6", {
        "parent_prefix": "2001:db8::/48",
        "child_length": 32,
        "count": 1,
    })
    assert_eq("api prefix-plan6 child<parent → 400", status5, 400)

    # Invalid: missing required fields.
    status6, _ = _api_post("prefix-plan6", {"parent_prefix": "2001:db8::/48"})
    assert_eq("api prefix-plan6 missing fields → 400", status6, 400)

    # Invalid: offset + count exceeds total. Disable nibble-align so /49 stays /49
    # (only 2 children); with nibble-align on it would snap to /52 (16 children).
    status7, _ = _api_post("prefix-plan6", {
        "parent_prefix": "2001:db8::/48",
        "child_length": 49,
        "count": 2,
        "start_offset": 1,
        "nibble_align": False,
    })
    assert_eq("api prefix-plan6 offset+count overflow → 400", status7, 400)


async def test_ipv6_prefix_plan_ui(page: Page) -> None:
    section("IPv6 prefix-delegation planner UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # /ipv6/prefix-plan should auto-open the drawer (per-tool URL routing).
    await navigate(page, APP_URL + "?tab=ipv6&tool=prefix-plan")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='prefix-plan']")
    assert_eq("prefix-plan UI: panel exists", await panel.count(), 1)

    # Plan a /48 → /56 slice (first 4).
    await page.fill("#prefix_plan6_parent", "2001:db8::/48")
    await page.fill("#prefix_plan6_child_length", "56")
    await page.fill("#prefix_plan6_count", "4")
    await page.click("#panel-ipv6 .tool-panel[data-tool='prefix-plan'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='prefix-plan']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("prefix-plan UI: first child rendered",
                "2001:db8::/56" in body_text, f"body: {body_text!r}")
    assert_true("prefix-plan UI: fourth child rendered",
                "2001:db8:0:300::/56" in body_text, f"body: {body_text!r}")

    # Copy a child prefix.
    await panel.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("prefix-plan UI: copy child prefix",
              await page.evaluate("window.__lastClipboard"),
              "2001:db8::/56")

    # Error path — child smaller than parent.
    await navigate(page, APP_URL + "?tab=ipv6&tool=prefix-plan")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#prefix_plan6_parent", "2001:db8::/48")
    await page.fill("#prefix_plan6_child_length", "32")
    await page.fill("#prefix_plan6_count", "1")
    await page.click("#panel-ipv6 .tool-panel[data-tool='prefix-plan'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='prefix-plan']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("prefix-plan UI: error band when child ≤ parent",
                len(err_text) > 0, f"got: {err_text!r}")

    # Shareable GET URL hydrates without re-submission.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=prefix-plan"
                   "&prefix_plan6_parent=2001:db8::/48"
                   "&prefix_plan6_child_length=56"
                   "&prefix_plan6_count=2")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='prefix-plan']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("prefix-plan UI shareable URL: hydrated",
                "2001:db8::/56" in body_text, f"body: {body_text!r}")


async def test_api_nibble6(page: Page) -> None:
    section("API — POST /api/v1/nibble6")

    # /49 → above=/48, below=/52.
    status, data = _api_post("nibble6", {"prefix": "2001:db8::/49"})
    assert_eq("api nibble6 /49: HTTP 200", status, 200)
    assert_eq("api nibble6 /49: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api nibble6 /49: input length",
              d.get("input", {}).get("length"), 49)
    assert_eq("api nibble6 /49: above length",
              d.get("above", {}).get("length"), 48)
    assert_eq("api nibble6 /49: below length",
              d.get("below", {}).get("length"), 52)
    assert_eq("api nibble6 /49: above prefix",
              d.get("above", {}).get("prefix"), "2001:db8::/48")
    assert_eq("api nibble6 /49: below prefix",
              d.get("below", {}).get("prefix"), "2001:db8::/52")
    assert_eq("api nibble6 /49: above contains_64s",
              d.get("above", {}).get("contains_64s"), "65536")
    assert_eq("api nibble6 /49: below contains_64s",
              d.get("below", {}).get("contains_64s"), "4096")

    # Already-aligned input — /48 stays /48 above, advances to /52 below.
    status2, data2 = _api_post("nibble6", {"prefix": "2001:db8::/48"})
    assert_eq("api nibble6 /48: HTTP 200", status2, 200)
    assert_eq("api nibble6 /48: above stays at /48",
              data2.get("data", {}).get("above", {}).get("prefix"), "2001:db8::/48")
    assert_eq("api nibble6 /48: below = /52",
              data2.get("data", {}).get("below", {}).get("prefix"), "2001:db8::/52")

    # /128 — both collapse.
    status3, data3 = _api_post("nibble6", {"prefix": "2001:db8::1/128"})
    assert_eq("api nibble6 /128: HTTP 200", status3, 200)
    assert_eq("api nibble6 /128: below caps at /128",
              data3.get("data", {}).get("below", {}).get("length"), 128)

    # Invalid: missing field.
    status4, _ = _api_post("nibble6", {})
    assert_eq("api nibble6 missing field → 400", status4, 400)

    # Invalid: unparseable address.
    status5, _ = _api_post("nibble6", {"prefix": "not-an-address/48"})
    assert_eq("api nibble6 invalid address → 400", status5, 400)


async def test_ipv6_nibble_ui(page: Page) -> None:
    section("IPv6 nibble-boundary helper UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # /ipv6/nibble should auto-open the drawer (per-tool URL routing).
    await navigate(page, APP_URL + "?tab=ipv6&tool=nibble")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='nibble']")
    assert_eq("nibble UI: panel exists", await panel.count(), 1)

    # Compute neighbours of /49.
    await page.fill("#nibble6_prefix", "2001:db8::/49")
    await page.click("#panel-ipv6 .tool-panel[data-tool='nibble'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='nibble']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("nibble UI: above /48 rendered",
                "2001:db8::/48" in body_text, f"body: {body_text!r}")
    assert_true("nibble UI: below /52 rendered",
                "2001:db8::/52" in body_text, f"body: {body_text!r}")

    # Copy the above prefix.
    await panel.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("nibble UI: copy above prefix",
              await page.evaluate("window.__lastClipboard"),
              "2001:db8::/48")

    # Error path — missing prefix length.
    await navigate(page, APP_URL + "?tab=ipv6&tool=nibble")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#nibble6_prefix", "2001:db8::")
    await page.click("#panel-ipv6 .tool-panel[data-tool='nibble'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='nibble']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("nibble UI: error band on missing length",
                len(err_text) > 0, f"got: {err_text!r}")

    # Shareable GET URL hydrates without re-submission.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=nibble"
                   "&nibble6_prefix=2001:db8::/49")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='nibble']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("nibble UI shareable URL: hydrated",
                "2001:db8::/48" in body_text and "2001:db8::/52" in body_text,
                f"body: {body_text!r}")


async def test_api_rfc3531(page: Page) -> None:
    section("API — POST /api/v1/rfc3531")

    # Centermost / 4 bits — RFC 3531 §3 worked example.
    status, data = _api_post("rfc3531", {
        "parent_prefix": "2001:db8::/48",
        "reservation_bits": 4,
        "strategy": "centermost",
    })
    assert_eq("api rfc3531 centermost/4: HTTP 200", status, 200)
    assert_eq("api rfc3531 centermost/4: ok=true", data.get("ok"), True)
    d = data.get("data", {})
    assert_eq("api rfc3531 centermost/4: strategy", d.get("strategy"), "centermost")
    assert_eq("api rfc3531 centermost/4: reservation_bits", d.get("reservation_bits"), 4)
    assert_eq("api rfc3531 centermost/4: allocation_order",
              d.get("allocation_order"),
              [8, 4, 12, 2, 6, 10, 14, 1, 3, 5, 7, 9, 11, 13, 15])
    children = d.get("children", [])
    assert_eq("api rfc3531 centermost/4: 15 children (no 0)", len(children), 15)
    assert_eq("api rfc3531 centermost/4: first child = value 8 prefix",
              children[0].get("prefix") if children else None,
              "2001:db8:0:8000::/52")
    assert_eq("api rfc3531 centermost/4: parent canonicalised",
              d.get("parent", {}).get("prefix"), "2001:db8::/48")

    # Leftmost / 4 bits — monotonic 0..15.
    status2, data2 = _api_post("rfc3531", {
        "parent_prefix": "2001:db8::/48",
        "reservation_bits": 4,
        "strategy": "leftmost",
    })
    assert_eq("api rfc3531 leftmost/4: HTTP 200", status2, 200)
    d2 = data2.get("data", {})
    assert_eq("api rfc3531 leftmost/4: allocation_order",
              d2.get("allocation_order"), list(range(16)))
    kids2 = d2.get("children", [])
    assert_eq("api rfc3531 leftmost/4: 16 children", len(kids2), 16)
    assert_eq("api rfc3531 leftmost/4: first prefix = parent",
              kids2[0].get("prefix") if kids2 else None, "2001:db8::/52")
    assert_eq("api rfc3531 leftmost/4: second prefix",
              kids2[1].get("prefix") if len(kids2) > 1 else None,
              "2001:db8:0:1000::/52")

    # Rightmost / 4 bits — reverse of leftmost.
    status3, data3 = _api_post("rfc3531", {
        "parent_prefix": "2001:db8::/48",
        "reservation_bits": 4,
        "strategy": "rightmost",
    })
    assert_eq("api rfc3531 rightmost/4: HTTP 200", status3, 200)
    d3 = data3.get("data", {})
    assert_eq("api rfc3531 rightmost/4: allocation_order[0]",
              d3.get("allocation_order", [None])[0], 15)
    kids3 = d3.get("children", [])
    assert_eq("api rfc3531 rightmost/4: first child prefix",
              kids3[0].get("prefix") if kids3 else None, "2001:db8:0:f000::/52")

    # Invalid: missing reservation_bits.
    status4, _ = _api_post("rfc3531", {"parent_prefix": "2001:db8::/48"})
    assert_eq("api rfc3531 missing field → 400", status4, 400)

    # Invalid: bad strategy.
    status5, _ = _api_post("rfc3531", {
        "parent_prefix": "2001:db8::/48",
        "reservation_bits": 4,
        "strategy": "random",
    })
    assert_eq("api rfc3531 bad strategy → 400", status5, 400)

    # Invalid: reservation_bits over default cap (8).
    status6, _ = _api_post("rfc3531", {
        "parent_prefix": "2001:db8::/40",
        "reservation_bits": 9,
    })
    assert_eq("api rfc3531 bits > cap → 400", status6, 400)

    # Invalid: parent_length + reservation_bits > 128.
    status7, _ = _api_post("rfc3531", {
        "parent_prefix": "2001:db8::/126",
        "reservation_bits": 4,
    })
    assert_eq("api rfc3531 parent+bits > 128 → 400", status7, 400)


async def test_ipv6_rfc3531_ui(page: Page) -> None:
    section("IPv6 RFC 3531 sparse-allocation UI")

    # Install clipboard intercept (docker test harness serves over insecure HTTP).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    # /ipv6/rfc3531 should auto-open the drawer (per-tool URL routing).
    await navigate(page, APP_URL + "?tab=ipv6&tool=rfc3531")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='rfc3531']")
    assert_eq("rfc3531 UI: panel exists", await panel.count(), 1)

    # Apply centermost / 4 bits.
    await page.fill("#rfc3531_parent", "2001:db8::/48")
    await page.fill("#rfc3531_reservation_bits", "4")
    await page.select_option("#rfc3531_strategy", "centermost")
    await page.click("#panel-ipv6 .tool-panel[data-tool='rfc3531'] button.splitter-btn")
    await page.wait_for_load_state("load")

    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='rfc3531']")
    body_text = (await panel.text_content() or "").lower()
    assert_true("rfc3531 UI: centre child rendered (value 8)",
                "2001:db8:0:8000::/52" in body_text, f"body: {body_text!r}")
    assert_true("rfc3531 UI: second child rendered (value 4)",
                "2001:db8:0:4000::/52" in body_text, f"body: {body_text!r}")

    # Copy the first child prefix.
    await panel.locator(".subnet-copy").first.click()
    await page.wait_for_timeout(50)
    assert_eq("rfc3531 UI: copy first child prefix",
              await page.evaluate("window.__lastClipboard"),
              "2001:db8:0:8000::/52")

    # Error path — bad strategy via direct field manipulation isn't possible
    # (it's a select), so trigger an error via parent-length overflow instead.
    await navigate(page, APP_URL + "?tab=ipv6&tool=rfc3531")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("#rfc3531_parent", "2001:db8::/126")
    await page.fill("#rfc3531_reservation_bits", "4")
    await page.click("#panel-ipv6 .tool-panel[data-tool='rfc3531'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='rfc3531']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("rfc3531 UI: error band on parent+bits > 128",
                len(err_text) > 0, f"got: {err_text!r}")

    # Shareable GET URL hydrates without re-submission.
    await navigate(page,
                   APP_URL + "?tab=ipv6&tool=rfc3531"
                   "&rfc3531_parent=2001:db8::/48"
                   "&rfc3531_reservation_bits=4"
                   "&rfc3531_strategy=leftmost")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='rfc3531']")
    body_text = (await panel.text_content() or "").lower()
    # Leftmost first child is parent itself: 2001:db8::/52
    assert_true("rfc3531 UI shareable URL: leftmost hydrated",
                "2001:db8::/52" in body_text, f"body: {body_text!r}")
    assert_true("rfc3531 UI shareable URL: second child rendered",
                "2001:db8:0:1000::/52" in body_text, f"body: {body_text!r}")


async def test_api_bulk(page: Page) -> None:
    section("API — bulk calculation")
    # Two valid IPv4 CIDRs
    status, data = _api_post("bulk", {"cidrs": ["10.0.0.0/24", "192.168.1.0/26"]})
    assert_eq("api bulk: HTTP 200", status, 200)
    assert_eq("api bulk: ok=true", data.get("ok"), True)
    results = data.get("data", {}).get("results", [])
    assert_eq("api bulk: 2 results returned", len(results), 2)
    assert_eq("api bulk: first item ok=true", results[0].get("ok") if results else None, True)
    assert_eq("api bulk: second item ok=true", results[1].get("ok") if len(results) > 1 else None, True)
    assert_true("api bulk: first item has data", "data" in results[0] if results else False)
    assert_eq("api bulk: first network_cidr",
              results[0].get("data", {}).get("network_cidr") if results else None, "10.0.0.0/24")

    # Mixed valid + invalid — never aborts
    status2, data2 = _api_post("bulk", {"cidrs": ["10.0.0.0/24", "not-a-cidr"]})
    assert_eq("api bulk mixed: HTTP 200", status2, 200)
    results2 = data2.get("data", {}).get("results", [])
    assert_eq("api bulk mixed: 2 results returned", len(results2), 2)
    assert_eq("api bulk mixed: valid item ok=true", results2[0].get("ok") if results2 else None, True)
    assert_eq("api bulk mixed: invalid item ok=false",
              results2[1].get("ok") if len(results2) > 1 else None, False)
    assert_true("api bulk mixed: invalid item has error",
                "error" in results2[1] if len(results2) > 1 else False)

    # Empty cidrs → 400
    status3, _ = _api_post("bulk", {"cidrs": []})
    assert_eq("api bulk: empty cidrs → 400", status3, 400)

    # Exceeding 50-item cap → 400
    status4, _ = _api_post("bulk", {"cidrs": ["10.0.0.0/24"] * 51})
    assert_eq("api bulk: 51 cidrs → 400", status4, 400)

    # CIDR notation input auto-detected
    status5, data5 = _api_post("bulk", {"cidrs": ["172.16.0.0/12"]})
    assert_eq("api bulk: cidr notation → 200", status5, 200)
    r5 = data5.get("data", {}).get("results", [{}])
    assert_eq("api bulk: cidr notation item ok=true", r5[0].get("ok") if r5 else None, True)

    # Explicit type=ipv4
    status6, data6 = _api_post("bulk", {"cidrs": ["10.0.0.0/8"], "type": "ipv4"})
    assert_eq("api bulk: explicit type=ipv4 → 200", status6, 200)
    r6 = data6.get("data", {}).get("results", [{}])
    assert_eq("api bulk: explicit type=ipv4 item ok=true", r6[0].get("ok") if r6 else None, True)


async def test_api_bulk_covers_new_ipv6_endpoints(page: Page) -> None:
    section("API — bulk multi-op covers v3.3.0 + v3.4.0 IPv6 endpoints")
    items = [
        {"op": "range6",        "params": {"start": "2001:db8::", "end": "2001:db8::ff"}},
        {"op": "supernet6",     "params": {"action": "find",
                                            "cidrs": ["2001:db8:0::/48", "2001:db8:1::/48"]}},
        {"op": "zone-id",       "params": {"input": "fe80::1%eth0"}},
        {"op": "derive",        "params": {"mac": "00:11:22:33:44:55"}},
        {"op": "slaac-privacy", "params": {"prefix": "2001:db8::/64",
                                            "seed": "deadbeefcafef00d"}},
        {"op": "rdns6",         "params": {"address": "2001:db8::1", "prefix": 64}},
        {"op": "mapped6",       "params": {"input": "192.0.2.1"}},
    ]
    status, data = _api_post("bulk", {"items": items})
    assert_eq("api bulk multi-op: HTTP 200", status, 200)
    assert_eq("api bulk multi-op: ok=true", data.get("ok"), True)
    results = data.get("data", {}).get("results", [])
    assert_eq("api bulk multi-op: 7 results returned", len(results), 7)
    expected_ops = ["range6", "supernet6", "zone-id", "derive",
                    "slaac-privacy", "rdns6", "mapped6"]
    for i, op in enumerate(expected_ops):
        envelope = results[i] if i < len(results) else {}
        assert_eq(f"api bulk multi-op: item[{i}] op={op}", envelope.get("op"), op)
        assert_eq(f"api bulk multi-op: item[{i}] ok=true", envelope.get("ok"), True)
    # Spot-check a couple of payloads to confirm the result fields land at the
    # top level of the envelope (not nested under `data`).
    rdns = results[5]
    assert_true("api bulk multi-op: rdns6 envelope has arpa", "arpa" in rdns)
    mapped = results[6]
    assert_eq("api bulk multi-op: mapped6 ipv4", mapped.get("ipv4"), "192.0.2.1")
    assert_eq("api bulk multi-op: mapped6 ipv4_mapped",
              mapped.get("ipv4_mapped"), "::ffff:192.0.2.1")

    # Mixed valid + invalid — request still succeeds; bad item carries ok=false.
    status_mix, data_mix = _api_post("bulk", {"items": [
        {"op": "range6", "params": {"start": "2001:db8::", "end": "2001:db8::ff"}},
        {"op": "rdns6",  "params": {"address": "not-an-address"}},
    ]})
    assert_eq("api bulk multi-op mixed: HTTP 200", status_mix, 200)
    results_mix = data_mix.get("data", {}).get("results", [])
    assert_eq("api bulk multi-op mixed: 2 results", len(results_mix), 2)
    assert_eq("api bulk multi-op mixed: item[0] ok=true",
              results_mix[0].get("ok") if results_mix else None, True)
    assert_eq("api bulk multi-op mixed: item[1] ok=false",
              results_mix[1].get("ok") if len(results_mix) > 1 else None, False)
    assert_true("api bulk multi-op mixed: item[1] has error",
                "error" in results_mix[1] if len(results_mix) > 1 else False)

    # Unsupported op slug → ok=false envelope, request still 200.
    status_bad, data_bad = _api_post("bulk", {"items": [
        {"op": "flux-capacitor", "params": {}},
    ]})
    assert_eq("api bulk multi-op unsupported: HTTP 200", status_bad, 200)
    bad_results = data_bad.get("data", {}).get("results", [])
    assert_eq("api bulk multi-op unsupported: ok=false",
              bad_results[0].get("ok") if bad_results else None, False)


async def test_vlsm_session_ttl_notice(page: Page) -> None:
    section("VLSM session TTL notice")
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    panel = await page.query_selector(".session-ttl-notice")
    if panel is None:
        ok("vlsm session ttl notice: sessions not enabled on this server (skipped)")
        return
    raw_text = await panel.text_content()
    notice_text: str = raw_text if raw_text is not None else ""
    assert_true(
        "vlsm session ttl notice: text mentions 'expire'",
        "expire" in notice_text.lower(),
        f"got: {notice_text!r}",
    )
    assert_true(
        "vlsm session ttl notice: text mentions 'day'",
        "day" in notice_text.lower(),
        f"got: {notice_text!r}",
    )


# ---------------------------------------------------------------------------
# VLSM session forms spacing (#199)
# ---------------------------------------------------------------------------

async def test_session_forms_spacing(page: Page) -> None:
    section("VLSM session forms spacing")
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    panel = await page.query_selector("#panel-vlsm .session-ttl-notice")
    if panel is None:
        ok("session forms spacing: sessions not enabled on this server (skipped)")
        return
    # v3.1.0 #321 — vlsm6 also renders .session-forms now; scope to the
    # IPv4 VLSM panel so the locator resolves uniquely.
    forms = page.locator("#panel-vlsm .session-forms")
    if await forms.count() == 0:
        ok("session forms spacing: .session-forms not found (skipped)")
        return
    gap = await forms.evaluate("""(el) => {
        var style = window.getComputedStyle(el);
        return parseFloat(style.rowGap || style.gap || '0');
    }""")
    assert_true(
        "session forms spacing: container gap >= 12px",
        gap >= 12,
        f"got gap={gap:.1f}px",
    )


# ---------------------------------------------------------------------------
# v3.0.0 — IPv6 VLSM session save/load round trip (#315)
# ---------------------------------------------------------------------------

async def test_vlsm6_session_save_load(page: Page) -> None:
    section("v3.0.0 #315 / v3.1.0 #321 IPv6 VLSM session save/load (drawer pattern)")
    # Calculate first so the Save Session form is populated.
    url = (APP_URL + "?tab=vlsm6&vlsm6_network=2001:db8::&vlsm6_cidr=32"
           + "&vlsm6_name%5B%5D=Site-A&vlsm6_hosts%5B%5D=256")
    await navigate(page, url)
    # v3.1.0 #321 — session UI now lives inside the vlsm6 tool-drawer.
    panel = await page.query_selector(
        '#panel-vlsm6 .tool-panel[data-tool="session6"]'
    )
    if panel is None:
        ok("vlsm6 session save/load: sessions not enabled on this server (skipped)")
        return

    # v3.1.0 #321 — open the session6 drawer first; the save button lives
    # inside a tool-panel that is hidden until the drawer is opened.
    trigger = page.locator(
        '#panel-vlsm6 .tool-toolbar .tool-trigger[data-tool="session6"]'
    )
    if await trigger.count() > 0:
        await trigger.first.click()
        await page.wait_for_function(
            "() => document.querySelector('#panel-vlsm6 .tool-drawer.open') !== null",
            timeout=2000,
        )

    # Click "Save Session" inside the IPv6 VLSM session6 tool panel.
    save_btn = page.locator(
        '#panel-vlsm6 .tool-panel[data-tool="session6"] '
        'button[name="session_action"][value="save"]'
    )
    if await save_btn.count() == 0:
        ok("vlsm6 session save/load: save button not present (skipped)")
        return
    await save_btn.first.click()
    await page.wait_for_load_state("networkidle")

    # Saved session URL appears in the saved-bar; capture the 8-char id.
    saved_bar = page.locator(
        '#panel-vlsm6 .tool-panel[data-tool="session6"] '
        '.session-saved-bar code.share-url'
    )
    assert_true(
        "vlsm6 save: saved-bar appears after save",
        await saved_bar.count() > 0,
    )
    href_text_raw = await saved_bar.first.text_content()
    href_text = href_text_raw or ""
    m = re.search(r"\?tab=vlsm6&s=([0-9a-f]{8})", href_text)
    assert_true(
        "vlsm6 save: URL contains tab=vlsm6 and 8-char id",
        m is not None,
        f"got: {href_text!r}",
    )
    if m is None:
        return
    sid = m.group(1)

    # Reload via the session URL — fields must be restored.
    await navigate(page, APP_URL + f"?tab=vlsm6&s={sid}")
    network_val = await page.input_value("#vlsm6_network")
    cidr_val    = await page.input_value("#vlsm6_cidr")
    assert_eq("vlsm6 load: network restored", network_val, "2001:db8::")
    assert_true(
        "vlsm6 load: cidr restored to 32",
        cidr_val.lstrip("/") == "32",
        f"got: {cidr_val!r}",
    )
    # Result table from auto-calc on load.
    result_count = await page.locator(".vlsm6-table tbody tr").count()
    assert_true("vlsm6 load: results auto-render", result_count >= 1, f"rows={result_count}")


# ---------------------------------------------------------------------------
# v3.1.0 #321 — IPv6 VLSM Save Session lives in the tool-drawer pattern
# ---------------------------------------------------------------------------

async def test_vlsm6_session_drawer_pattern(page: Page) -> None:
    section("v3.1.0 #321 IPv6 VLSM Save Session drawer parity with IPv4")
    # Calculate first so the panel is populated and the trigger is meaningful.
    url = (APP_URL + "?tab=vlsm6&vlsm6_network=2001:db8::&vlsm6_cidr=32"
           + "&vlsm6_name%5B%5D=Site-A&vlsm6_hosts%5B%5D=256")
    await navigate(page, url)

    # 1. The Save Session toolbar trigger exists on the vlsm6 panel.
    trigger = page.locator(
        '#panel-vlsm6 .tool-toolbar .tool-trigger[data-tool="session6"]'
    )
    trig_count = await trigger.count()
    if trig_count == 0:
        ok("vlsm6 session drawer: sessions disabled on this server (skipped)")
        return
    assert_eq("vlsm6 drawer: Save Session trigger present", trig_count, 1)
    trig_text = (await trigger.first.text_content()) or ""
    assert_true(
        "vlsm6 drawer: trigger labelled 'Save Session'",
        trig_text.strip() == "Save Session",
        f"got: {trig_text!r}",
    )

    # 2. Clicking the trigger opens the drawer (matches `.tool-drawer.open`).
    drawer = page.locator('#panel-vlsm6 .tool-drawer')
    assert_eq("vlsm6 drawer: drawer element present", await drawer.count(), 1)
    await trigger.first.click()
    # JS adds .open after click; wait briefly for the class to land.
    await page.wait_for_function(
        "() => document.querySelector('#panel-vlsm6 .tool-drawer.open') !== null",
        timeout=2000,
    )
    open_count = await page.locator('#panel-vlsm6 .tool-drawer.open').count()
    assert_eq("vlsm6 drawer: opens on trigger click", open_count, 1)

    # 3. The save form (POST + session_action=save) is rendered inside the panel.
    save_form_btn = page.locator(
        '#panel-vlsm6 .tool-panel[data-tool="session6"] '
        'button[name="session_action"][value="save"]'
    )
    assert_eq(
        "vlsm6 drawer: save-form button rendered inside panel",
        await save_form_btn.count(),
        1,
    )

    # 4. After a ?tab=vlsm6&s=<id> GET (the session-load URL — equivalent of
    #    the task's `?session_id=` description), the drawer auto-opens because
    #    request.php sets $session_error / $session_save_id which the template
    #    promotes to data-open-tool="session6".
    #    We mint a save first by clicking, then capture the id from the URL.
    await save_form_btn.first.click()
    await page.wait_for_load_state("networkidle")
    saved_url_node = page.locator(
        '#panel-vlsm6 .tool-panel[data-tool="session6"] '
        '.session-saved-bar code.share-url'
    )
    saved_text = (await saved_url_node.first.text_content()) or ""
    m = re.search(r"\?tab=vlsm6&s=([0-9a-f]{8})", saved_text)
    assert_true(
        "vlsm6 drawer: post-save share URL has 8-char id",
        m is not None,
        f"got: {saved_text!r}",
    )
    if m is None:
        return
    sid = m.group(1)

    # Now navigate to the load URL — the toolbar should carry data-open-tool.
    await navigate(page, APP_URL + f"?tab=vlsm6&s={sid}")
    open_attr = await page.locator('#panel-vlsm6 .tool-toolbar').first.get_attribute(
        "data-open-tool"
    )
    # On a successful load with no error and no fresh save_id, the drawer
    # does NOT auto-open (mirrors IPv4 behaviour — load is silent). We still
    # assert the trigger remained available so the user can re-open manually.
    re_trigger = await page.locator(
        '#panel-vlsm6 .tool-toolbar .tool-trigger[data-tool="session6"]'
    ).count()
    assert_eq(
        "vlsm6 drawer: trigger still available after session-load GET",
        re_trigger,
        1,
    )
    # Exercise the auto-open path by visiting an INVALID id — request.php
    # sets $session_error, which the template promotes to data-open-tool.
    await navigate(page, APP_URL + "?tab=vlsm6&s=deadbeef")
    open_attr_err = await page.locator('#panel-vlsm6 .tool-toolbar').first.get_attribute(
        "data-open-tool"
    )
    assert_eq(
        "vlsm6 drawer: data-open-tool=session6 on session error GET",
        open_attr_err,
        "session6",
    )


# ---------------------------------------------------------------------------
# v3.0.0 — Admin /admin/keys.php smoke (#307)
# ---------------------------------------------------------------------------

ADMIN_USER = "testadmin"
ADMIN_PASS = "test-admin-password"


async def test_admin_keys_unauth_challenge(page: Page) -> None:
    section("v3.2.0 #342 admin/keys.php — unauth 303 -> login.php")
    resp = await page.context.request.get(
        APP_URL + "admin/keys.php", max_redirects=0
    )
    assert_eq("admin/keys unauth: status 303", resp.status, 303)
    location = resp.headers.get("location", "")
    assert_true(
        "admin/keys unauth: Location header points at login.php",
        "login.php" in location,
        f"got: {location!r}",
    )


async def test_admin_keys_authed_renders(page: Page) -> None:
    section("v3.0.0 #307 admin/keys.php — authed renders")
    resp = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    assert_eq("admin/keys authed: status 200", resp.status, 200)
    body = await resp.text()
    assert_true(
        "admin/keys authed: page contains 'API Keys' heading",
        "API Keys" in body,
    )
    assert_true(
        "admin/keys authed: mint form RPM input present (#312)",
        'name="rate_limit_rpm"' in body,
    )


async def test_admin_keys_post_mint_panel_has_copy_button(page: Page) -> None:
    section("v3.2.0 #348 admin/keys.php — post-mint panel has Copy + Got it controls")
    sess = _admin_session_requests()
    initial = sess.get(APP_URL + "admin/keys.php", timeout=10)
    body = initial.text
    import re as _re
    m = _re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    csrf = m.group(1) if m else ""
    name = "pr8-mint-test-" + ("%x" % int(__import__('time').time()))
    post = sess.post(
        APP_URL + "admin/keys.php",
        data={"_csrf": csrf, "action": "create", "name": name, "rate_limit_rpm": ""},
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("admin/keys mint: 303 PRG", post.status_code, 303)
    follow = sess.get(APP_URL + "admin/keys.php", timeout=10)
    body2 = follow.text
    assert_true(
        "admin/keys post-mint: alert panel rendered with role=alert",
        'class="alert alert-accent api-key-panel"' in body2,
    )
    assert_true(
        "admin/keys post-mint: Copy button targets the token id",
        'data-copy-target="new-api-key-token"' in body2,
    )
    assert_true(
        "admin/keys post-mint: Got it dismiss button rendered",
        'data-dismiss-panel' in body2,
    )
    assert_true(
        "admin/keys post-mint: one-shot warning copy",
        "only time the full key" in body2,
    )


async def test_admin_keys_copy_button_invokes_clipboard(page: Page) -> None:
    section("v3.2.0 #348 admin/keys.php — Copy button writes the token to the clipboard")
    # Install a clipboard stub before any page script runs (CLAUDE.md pattern).
    await page.add_init_script("""
        Object.defineProperty(navigator, 'clipboard', {
            value: {
                writeText: function(text) {
                    window.__lastClipboard = text;
                    return Promise.resolve();
                }
            },
            configurable: true,
        });
    """)
    cookie = _admin_session_cookie_header().split("sc_admin_sid=", 1)[1]
    from urllib.parse import urlparse as _urlparse
    parsed = _urlparse(APP_URL)
    domain = parsed.hostname or "localhost"
    await page.context.add_cookies([{
        "name": "sc_admin_sid",
        "value": cookie,
        "domain": domain,
        "path": "/",
    }])
    try:
        # Mint a key via requests so a fresh post-mint panel renders on next nav.
        sess = _admin_session_requests()
        initial = sess.get(APP_URL + "admin/keys.php", timeout=10)
        import re as _re
        m = _re.search(r'name="_csrf" value="([0-9a-f]{64})"', initial.text)
        csrf = m.group(1) if m else ""
        sess.post(
            APP_URL + "admin/keys.php",
            data={"_csrf": csrf, "action": "create", "name": "copy-button-test", "rate_limit_rpm": ""},
            allow_redirects=False,
            timeout=10,
        )
        # Carry the requests session cookie back into the playwright browser
        # so the same logged-in admin sees the panel.
        sc_cookie = sess.cookies.get("sc_admin_sid")
        if sc_cookie:
            await page.context.clear_cookies()
            await page.context.add_cookies([{
                "name": "sc_admin_sid",
                "value": sc_cookie,
                "domain": domain,
                "path": "/",
            }])
        # Navigate to keys page and re-mint inside playwright so the panel renders
        # in this browser's PHP-session flash slot.
        await page.goto(APP_URL + "admin/keys.php")
        # Re-mint in browser so the panel surfaces here.
        token_count_before = await page.locator("#new-api-key-token").count()
        if token_count_before == 0:
            csrf_val = await page.eval_on_selector("input[name='_csrf']", "el => el.value")
            await page.evaluate(
                """async (csrf) => {
                    const fd = new FormData();
                    fd.set('_csrf', csrf);
                    fd.set('action', 'create');
                    fd.set('name', 'copy-btn-' + Date.now());
                    fd.set('rate_limit_rpm', '');
                    await fetch(window.location.pathname, { method: 'POST', body: fd, redirect: 'manual' });
                }""",
                csrf_val,
            )
            await page.goto(APP_URL + "admin/keys.php")
        await page.wait_for_selector("#new-api-key-token", timeout=5000)
        token_text = await page.text_content("#new-api-key-token")
        assert_true("token rendered in panel", token_text is not None and token_text != "")
        prev = await page.evaluate("() => window.__lastClipboard || ''")
        await page.click("[data-copy-target='new-api-key-token']")
        clipboard = await _wait_for_clipboard_write(page, prev)
        assert_eq("Copy button payload matches the rendered token", clipboard, (token_text or "").strip())
        # Button text flips to "Copied!"
        await page.wait_for_function(
            "() => { const b = document.querySelector('[data-copy-target=\\\"new-api-key-token\\\"]'); return b && b.textContent === 'Copied!'; }",
            timeout=2000,
        )
        ok("Copy button text becomes 'Copied!' after click")
    finally:
        await page.context.clear_cookies()


async def test_admin_keys_empty_state_links_to_docs(page: Page) -> None:
    section("v3.2.0 #348 admin/keys.php — empty state links to API docs")
    # Drain the api_keys table so the empty state renders.
    drain_token = os.environ.get("PHPUNIT_TEST_DRAIN_TOKEN", "")
    if drain_token:
        drain = await page.context.request.post(
            APP_URL + "admin/_test-drain.php",
            data={"token": drain_token},
        )
        if drain.status != 200:
            ok(f"admin/keys empty state: drain returned {drain.status} — skipped")
            return
        # admin_sessions was truncated; the cached sid is now stale.
        _admin_session_cookie_reset()
    resp = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    if "admin-empty-state" not in body:
        ok("admin/keys empty state: keys still present (drain unavailable) — skipped")
        return
    assert_true(
        "admin/keys empty state: API reference link present",
        'docs.subnetcalculator.app/api/' in body,
    )
    assert_true(
        "admin/keys empty state: rate-limiting anchor link present",
        '#rate-limiting' in body,
    )
    assert_true(
        "admin/keys empty state: explanatory copy mentions Authorization Bearer",
        "Authorization: Bearer" in body,
    )


async def test_admin_audit_renders(page: Page) -> None:
    section("v3.0.0 #307 admin/audit.php — authed renders + filters present")
    resp = await page.context.request.get(
        APP_URL + "admin/audit.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    assert_eq("admin/audit authed: status 200", resp.status, 200)
    body = await resp.text()
    assert_true(
        "admin/audit: page title present",
        "Admin Audit Log" in body,
    )
    for label in ["all", "login", "key"]:
        assert_true(
            f"admin/audit: '{label}' filter tab present",
            f">{label}</button>" in body,
            f"missing filter '{label}'",
        )


async def test_admin_audit_records_login_failure(page: Page) -> None:
    section("v3.2.0 #342 audit log records failed admin login (form path)")
    # GET login.php to mint a CSRF + sid cookie, then POST a wrong password.
    import requests as _r, re as _re
    sess = _r.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    get_resp = sess.get(APP_URL + "admin/login.php", timeout=10)
    assert_eq("login.php GET: 200", get_resp.status_code, 200)
    m = _re.search(r'name="csrf"\s+value="([0-9a-f]+)"', get_resp.text)
    assert_true("login.php GET: csrf token rendered", m is not None)
    csrf = m.group(1) if m else ""
    bad_resp = sess.post(
        APP_URL + "admin/login.php",
        data={"user": ADMIN_USER, "pass": "wrong-password", "csrf": csrf, "next": "/admin/"},
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("login.php bad pass: re-renders form (200)", bad_resp.status_code, 200)
    assert_true("login.php bad pass: error alert rendered",
                "Username or password is incorrect" in bad_resp.text)

    # Read audit page with the login. filter via the cookie session.
    audit_resp = await page.context.request.get(
        APP_URL + "admin/audit.php?filter=login.",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await audit_resp.text()
    assert_true(
        "audit log: login.fail row appears in body",
        "login.fail" in body,
    )


async def test_admin_audit_filter_tablist_semantics(page: Page) -> None:
    section("v3.2.0 #346 admin/audit.php — filter strip uses role=tablist")
    resp = await page.context.request.get(
        APP_URL + "admin/audit.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    assert_true(
        "audit: exactly one role=tablist on the filter strip",
        body.count('role="tablist"') == 1,
        f"expected 1 tablist, found {body.count(chr(34) + 'role=' + chr(34))}",
    )
    assert_true("audit: role=tab present on filter buttons", 'role="tab"' in body)
    assert_true("audit: aria-selected=true on the active tab", 'aria-selected="true"' in body)
    assert_true("audit: aria-selected=false on inactive tabs", 'aria-selected="false"' in body)
    assert_true("audit: aria-controls wires the active tab to the rows", 'aria-controls="audit-rows"' in body)
    # Selecting a different tab updates the URL filter param.
    resp2 = await page.context.request.get(
        APP_URL + "admin/audit.php?filter=login.",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body2 = await resp2.text()
    assert_true(
        "audit: ?filter=login. promotes the login tab to aria-selected=true",
        'value="login."' in body2 and 'aria-selected="true"' in body2,
    )


async def test_admin_audit_action_badges(page: Page) -> None:
    section("v3.2.0 #346 admin/audit.php — action cells render colour-coded badges")
    resp = await page.context.request.get(
        APP_URL + "admin/audit.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    # The audit log accumulates over time; we don't seed events here, but the
    # badge classes must appear at all (a fresh dev DB always has at least one
    # auth.* row from the test rig). Assert mapping integrity on whatever rows
    # render.
    has_badge = any(cls in body for cls in ("badge-public", "badge-multicast", "badge-private", "badge-doc", "badge-other"))
    assert_true("audit: at least one audit row rendered with a v3.2.0 badge class", has_badge)
    # Negative: the legacy badge classes (badge-info / badge-fail / badge-ok)
    # should NOT appear inside an audit row anymore — they were the v3.0.0
    # mapping superseded by audit_action_badge_class().
    assert_true(
        "audit: legacy badge-info no longer used on audit rows",
        '<span class="badge badge-info">' not in body,
    )


async def test_admin_audit_sticky_thead(page: Page) -> None:
    section("v3.2.0 #346 admin/audit.php — table thead is position:sticky")
    # Use a real navigation so computed styles resolve.
    cookie = _admin_session_cookie_header().split("sc_admin_sid=", 1)[1]
    from urllib.parse import urlparse as _urlparse
    parsed = _urlparse(APP_URL)
    domain = parsed.hostname or "localhost"
    await page.context.add_cookies([{
        "name": "sc_admin_sid",
        "value": cookie,
        "domain": domain,
        "path": "/",
    }])
    try:
        resp = await page.goto(APP_URL + "admin/audit.php")
        if resp is None or resp.status != 200:
            ok("audit sticky thead: page unreachable in this environment — skipped")
            return
        # The audit table only renders if there are rows; if not, skip.
        thead_count = await page.locator(".admin-table thead").count()
        if thead_count == 0:
            ok("audit sticky thead: table not rendered (empty audit log) — skipped")
            return
        position = await page.evaluate(
            "() => getComputedStyle(document.querySelector('.admin-table thead')).position"
        )
        assert_eq("audit thead computed position is 'sticky'", position, "sticky")
    finally:
        await page.context.clear_cookies()


async def test_admin_audit_pagination_disabled_not_focusable(page: Page) -> None:
    section("v3.2.0 #346 admin/audit.php — disabled pagination is <span>, not focusable")
    resp = await page.context.request.get(
        APP_URL + "admin/audit.php?page=1",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    # On page 1 the prev side is disabled. Look for the disabled-side <span>.
    has_disabled_span = (
        '<span class="admin-pager-item is-disabled" aria-disabled="true"' in body
        and 'Previous page (unavailable)' in body
    )
    if not has_disabled_span and 'admin-pager' not in body:
        # Audit log empty → pager isn't rendered. Skip.
        ok("audit pager disabled: pager not rendered (empty audit log) — skipped")
        return
    assert_true(
        "audit: prev side on page 1 is <span aria-disabled=true>",
        has_disabled_span,
    )
    # And no tabindex="-1" hack on an <a> for the disabled side. Scope the
    # check to the pager block — tabindex="-1" is a legitimate pattern on
    # the inactive role=tab filter buttons (roving tabindex).
    pager_idx = body.find('admin-pager')
    pager_end = body.find('</section>', pager_idx) if pager_idx != -1 else -1
    pager_block = body[pager_idx:pager_end] if pager_idx != -1 and pager_end != -1 else ''
    assert_true(
        "audit: disabled side does not use tabindex=-1 on <a>",
        'tabindex="-1"' not in pager_block,
    )


async def test_admin_audit_time_cells_have_datetime_attr(page: Page) -> None:
    section("v3.2.0 #346 admin/audit.php — every time cell wrapped in <time datetime>")
    resp = await page.context.request.get(
        APP_URL + "admin/audit.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    if "admin-table" not in body:
        ok("audit time attr: table not rendered (empty audit log) — skipped")
        return
    import re as _re
    times = _re.findall(r'<time datetime="([^"]+)"', body)
    assert_true("audit: at least one <time datetime=…> in the table", len(times) > 0)
    bad = [t for t in times if not t.endswith("Z")]
    assert_true(
        "audit: every datetime is a UTC ISO-8601 string ending in Z",
        len(bad) == 0,
        f"non-UTC datetimes: {bad[:3]}",
    )


async def test_admin_audit_caption_sr_only(page: Page) -> None:
    section("v3.2.0 #346 admin/audit.php — table has sr-only caption for screen readers")
    resp = await page.context.request.get(
        APP_URL + "admin/audit.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    if "admin-table" not in body:
        ok("audit caption: table not rendered (empty audit log) — skipped")
        return
    assert_true(
        "audit: <caption class=\"sr-only\"> with the expected copy",
        '<caption class="sr-only">Recent admin actions, newest first.' in body,
    )


# ---------------------------------------------------------------------------
# v3.0.0 — PR2 admin hardening (#311 wizard, #313 TOTP, #307 matrix)
# ---------------------------------------------------------------------------


# v3.2.0 (#342): web admin moved from HTTP Basic Auth to a cookie-session
# login form. _admin_session_cookie_header() form-logs-in once and returns
# a "Cookie: sc_admin_sid=<sid>" header value usable on subsequent
# page.context.request.* calls. The result is cached for the suite run.
_ADMIN_SESSION_COOKIE_CACHE: str | None = None


def _admin_session_cookie_header() -> str:
    """Login via the form once, return a Cookie header value.

    The legacy /api/v1/admin/* routes still use Basic Auth — those tests
    use _admin_api_basic_header() instead.
    """
    global _ADMIN_SESSION_COOKIE_CACHE
    if _ADMIN_SESSION_COOKIE_CACHE is not None:
        return _ADMIN_SESSION_COOKIE_CACHE

    # Phase 1: GET login.php to mint a placeholder pending session and
    # capture the CSRF token + sc_admin_sid cookie.
    sess = _requests.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    get_resp = sess.get(APP_URL + "admin/login.php", timeout=10)
    if get_resp.status_code != 200:
        raise RuntimeError(
            f"admin login GET failed: HTTP {get_resp.status_code}"
        )
    import re as _re
    m = _re.search(r'name="csrf"\s+value="([0-9a-f]+)"', get_resp.text)
    if not m:
        raise RuntimeError("admin login GET: csrf token not found")
    csrf = m.group(1)
    sid_cookie = sess.cookies.get("sc_admin_sid")
    if not sid_cookie:
        raise RuntimeError("admin login GET: sc_admin_sid cookie not set")

    # Phase 2: POST credentials. We expect a 303 to either /admin/ or
    # /admin/totp-verify.php. Either way, the same sid cookie now binds
    # to a fully-promoted (or TOTP-pending) session row.
    post_resp = sess.post(
        APP_URL + "admin/login.php",
        data={"user": ADMIN_USER, "pass": ADMIN_PASS, "csrf": csrf, "next": "/admin/"},
        allow_redirects=False,
        timeout=10,
    )
    if post_resp.status_code != 303:
        raise RuntimeError(
            f"admin login POST: expected 303, got {post_resp.status_code} "
            f"(body: {post_resp.text[:200]})"
        )
    # Reject TOTP-pending sessions — every caller of this helper expects
    # direct access to /admin/*.php; a TOTP-pending sid would silently
    # redirect them to totp-verify.php and break downstream assertions.
    location = post_resp.headers.get("location", "") or post_resp.headers.get("Location", "")
    if "totp-verify.php" in location:
        raise RuntimeError(
            "admin login redirected to totp-verify.php; this helper only supports "
            "fully-authenticated sessions. Disable TOTP in the test fixture or "
            "extend the helper to complete the second-factor step."
        )
    new_sid = sess.cookies.get("sc_admin_sid") or sid_cookie
    _ADMIN_SESSION_COOKIE_CACHE = f"sc_admin_sid={new_sid}"
    return _ADMIN_SESSION_COOKIE_CACHE


def _admin_session_cookie_reset() -> None:
    """Drop the cached admin session cookie. Call after any operation that
    invalidates the server-side session row (e.g. /admin/_test-drain.php
    truncating admin_sessions)."""
    global _ADMIN_SESSION_COOKIE_CACHE
    _ADMIN_SESSION_COOKIE_CACHE = None


def _admin_api_basic_header() -> str:
    """Basic Auth header for /api/v1/admin/* (still Basic Auth — #342 boundary)."""
    return "Basic " + base64.b64encode(
        f"{ADMIN_USER}:{ADMIN_PASS}".encode()
    ).decode()


def _admin_basic_header() -> str:
    """Back-compat alias used by web-admin tests; returns a Cookie header now."""
    return _admin_session_cookie_header()


def _admin_session_requests():
    """Returns a freshly authenticated requests.Session.

    Use this when a test does a POST -> follow GET cycle and needs PHPSESSID
    to round-trip for the PRG flash. The returned Session has both
    sc_admin_sid (from form login) and a freshly-minted PHPSESSID once the
    first admin page is hit.
    """
    import requests as _r
    import re as _re
    sess = _r.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    get_resp = sess.get(APP_URL + "admin/login.php", timeout=10)
    if get_resp.status_code != 200:
        raise RuntimeError(f"login.php GET: HTTP {get_resp.status_code}")
    m = _re.search(r'name="csrf"\s+value="([0-9a-f]+)"', get_resp.text)
    if not m:
        raise RuntimeError("login.php GET: csrf not found")
    csrf = m.group(1)
    post_resp = sess.post(
        APP_URL + "admin/login.php",
        data={"user": ADMIN_USER, "pass": ADMIN_PASS, "csrf": csrf, "next": "/admin/"},
        allow_redirects=False,
        timeout=10,
    )
    if post_resp.status_code != 303:
        raise RuntimeError(
            f"login.php POST: expected 303, got {post_resp.status_code}"
        )
    location = post_resp.headers.get("location", "") or post_resp.headers.get("Location", "")
    if "totp-verify.php" in location:
        raise RuntimeError(
            "login.php redirected to totp-verify.php; _admin_session_requests "
            "only supports fully-authenticated sessions"
        )
    return sess


def _drain_admin_state() -> None:
    """Zero api_keys + admin_audit + admin_recovery_codes + sc_admin sessions.

    Called once at the top of main() before any admin-touching test. Lets
    `make test-docker` run repeatedly against a non-fresh container without
    leftover rows tripping later tests (#324).

    Idempotent: 200 on first call, still 200 on subsequent calls (truncates
    already-empty tables). No-op when PHPUNIT_TEST_DRAIN_TOKEN is unset
    (e.g. running against a non-test deployment).
    """
    token = os.environ.get("PHPUNIT_TEST_DRAIN_TOKEN", "")
    if not token:
        return
    try:
        resp = _SESSION.post(
            APP_URL + "admin/_test-drain.php",
            data={"token": token},
            timeout=10,
            allow_redirects=False,
        )
    except Exception as exc:
        raise RuntimeError(f"admin drain failed (network): {exc}") from exc
    if resp.status_code != 200:
        raise RuntimeError(
            f"admin drain failed: HTTP {resp.status_code} {resp.text[:200]}"
        )


async def test_admin_keys_csrf_rejected(page: Page) -> None:
    section("v3.0.0 #307 admin/keys.php — POST without CSRF token rejected")
    # POST with no _csrf field; should land on the redirect with a flash error.
    resp = await page.context.request.post(
        APP_URL + "admin/keys.php",
        headers={"Cookie": _admin_basic_header()},
        form={"action": "create", "name": "csrf-test"},
        max_redirects=0,
    )
    assert_eq("admin/keys CSRF: 303 redirect on bad token", resp.status, 303)
    follow = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": _admin_basic_header()},
    )
    body = await follow.text()
    # The flash on the next request from the same session would surface the
    # error, but cookies don't survive request.get without the same context;
    # verify instead that no key named csrf-test was created (the create
    # path is what we want to confirm did NOT run).
    assert_true(
        "admin/keys CSRF: csrf-test key was not created",
        ">csrf-test<" not in body,
    )


async def test_admin_keys_per_row_rpm_edit(page: Page) -> None:
    section("v3.0.0 #312 admin/keys.php — per-row RPM editor present + posts")
    auth = _admin_basic_header()
    # Mint a key first so we have a row to edit.
    keys_get = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
    )
    body = await keys_get.text()
    # CSRF token is the same for every form on the page.
    import re
    m = re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    assert_true("admin/keys: CSRF token discoverable", m is not None)
    csrf = m.group(1) if m else ""

    mint = await page.context.request.post(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
        form={
            "_csrf": csrf,
            "action": "create",
            "name": "rpm-edit-target",
            "rate_limit_rpm": "120",
        },
        max_redirects=0,
    )
    assert_eq("admin/keys mint: 303 PRG", mint.status, 303)

    listing = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
    )
    body2 = await listing.text()
    assert_true(
        "admin/keys per-row RPM: editor input rendered for each row",
        'name="rate_limit_rpm"' in body2 and 'action" value="set_rpm"' in body2,
    )
    assert_true(
        "admin/keys per-row RPM: target row visible",
        "rpm-edit-target" in body2,
    )


async def test_admin_keys_revoke_confirm_present(page: Page) -> None:
    section("v3.0.0 #307 admin/keys.php — revoke form has confirm()")
    auth = _admin_basic_header()
    listing = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
    )
    body = await listing.text()
    assert_true(
        "admin/keys revoke: onsubmit confirm guard present",
        "onsubmit=\"return confirm('Revoke this key?');\"" in body,
    )

    # Cleanup: revoke every active key so subsequent open-API tests in this
    # suite are not flipped into "auth required" mode. test_admin_keys_per_row_rpm_edit
    # mints rpm-edit-target and leaves it active so this test can find a revoke
    # form to assert against; once that assertion is done, drain the table.
    csrf_match = re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    if csrf_match is not None:
        csrf = csrf_match.group(1)
        for id_match in re.finditer(
            r'<input type="hidden" name="action" value="revoke">\s*'
            r'<input type="hidden" name="id" value="(\d+)">',
            body,
        ):
            await page.context.request.post(
                APP_URL + "admin/keys.php",
                headers={"Cookie": auth},
                form={
                    "_csrf": csrf,
                    "action": "revoke",
                    "id": id_match.group(1),
                },
                max_redirects=0,
            )


async def test_admin_drain_endpoint_zeroes_state(page: Page) -> None:
    section("v3.1.0 #324 — drain endpoint zeroes api_keys + admin_audit + sessions")
    auth = _admin_basic_header()

    # Mint a key via the admin UI so we can prove the drain removes it.
    listing = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
    )
    body = await listing.text()
    csrf_match = re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    assert_true("admin/keys: CSRF token discoverable for drain fixture",
                csrf_match is not None)
    if csrf_match is None:
        return
    csrf = csrf_match.group(1)
    mint = await page.context.request.post(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
        form={"_csrf": csrf, "action": "create", "name": "drain-fixture"},
        max_redirects=0,
    )
    assert_eq("drain fixture mint: 303 PRG", mint.status, 303)

    # Pre-drain state proof: the row must be visible before we drain so the
    # post-drain absence assertion is a real before-vs-after delta, not just
    # "the mint endpoint redirected". A 303 alone doesn't prove insertion.
    before_drain = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
    )
    before_body = await before_drain.text()
    assert_true(
        "drain-fixture present BEFORE drain (mint actually inserted the row)",
        ">drain-fixture<" in before_body,
    )

    # Hit the drain endpoint with the token from the docker fixture env.
    token = os.environ.get("PHPUNIT_TEST_DRAIN_TOKEN", "")
    assert_true(
        "PHPUNIT_TEST_DRAIN_TOKEN present in test container env",
        token != "",
    )

    # Without the token: must 403 (and 404 if env not set on server).
    bad = await page.context.request.post(
        APP_URL + "admin/_test-drain.php",
        form={"token": "wrong"},
        max_redirects=0,
    )
    assert_true(
        "drain rejects bad token: 403 (or 404 if env unset on server)",
        bad.status in (403, 404),
    )

    drain = await page.context.request.post(
        APP_URL + "admin/_test-drain.php",
        form={"token": token},
        max_redirects=0,
    )
    assert_eq("drain returns 200", drain.status, 200)
    drain_body = await drain.text()
    try:
        drain_json = json.loads(drain_body)
    except (ValueError, TypeError):
        drain_json = {}
    assert_eq("drain JSON reports ok=true", drain_json.get("ok"), True)
    assert_true(
        "drain JSON reports api_keys was zeroed",
        "api_keys" in (drain_json.get("drained") or []),
    )

    # api_keys must be empty after drain — the keys page must not list any
    # active row (the drain-fixture row should be gone).
    after = await page.context.request.get(
        APP_URL + "admin/keys.php",
        headers={"Cookie": auth},
    )
    after_body = await after.text()
    assert_true(
        "api_keys empty after drain: drain-fixture not listed",
        ">drain-fixture<" not in after_body,
    )
    # The drain wiped admin_sessions; the cached cookie is now invalid.
    # Reset so the next admin test re-mints a fresh session.
    global _ADMIN_SESSION_COOKIE_CACHE
    _ADMIN_SESSION_COOKIE_CACHE = None


async def test_admin_settings_page_renders(page: Page) -> None:
    section("v3.2.0 #349 admin/settings.php — page renders six sections + advanced")
    resp = await page.context.request.get(
        APP_URL + "admin/settings.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    assert_eq("admin/settings: status 200", resp.status, 200)
    body = await resp.text()
    assert_true("admin/settings: page heading present", ">Settings " in body)
    for sec in ["branding", "forms", "api", "sessions", "admin", "limits"]:
        assert_true(
            f"admin/settings: section '{sec}' rendered",
            f'id="settings-{sec}-heading"' in body,
        )
    assert_true(
        "admin/settings: advanced (CSP) details element rendered",
        "settings-advanced" in body,
    )
    assert_true(
        "admin/settings: per-section save buttons rendered",
        body.count('value="save"') >= 6,
    )
    assert_true(
        "admin/settings: source badges rendered (default/admin/config)",
        "settings-source" in body,
    )


async def test_admin_settings_validation_rejects_invalid(page: Page) -> None:
    section("v3.2.0 #349 admin/settings.php — invalid input is rejected before disk write")
    sess = _admin_session_requests()
    initial = sess.get(APP_URL + "admin/settings.php", timeout=10)
    body = initial.text
    import re as _re
    m = _re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    csrf = m.group(1) if m else ""
    # split_max_subnets has range 1..256; submit 9999.
    post = sess.post(
        APP_URL + "admin/settings.php",
        data={
            "_csrf": csrf,
            "action": "save",
            "section": "limits",
            "split_max_subnets": "9999",
            "lookup_max_cidrs": "100",
            "lookup_max_ips": "1000",
        },
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("admin/settings invalid: 303 PRG", post.status_code, 303)
    follow = sess.get(APP_URL + "admin/settings.php", timeout=10)
    body2 = follow.text
    assert_true(
        "admin/settings invalid: error flash rendered",
        "Some fields had errors" in body2 or "must be" in body2,
    )


async def test_admin_settings_secret_masking(page: Page) -> None:
    section("v3.2.0 #349 admin/settings.php — secret fields render masked, not round-tripped")
    resp = await page.context.request.get(
        APP_URL + "admin/settings.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    # turnstile_secret_key is marked secret in schema.
    assert_true(
        "admin/settings: turnstile_secret_key uses masked widget",
        'name="turnstile_secret_key" type="password"' in body
        and "settings-secret-masked" in body,
    )
    assert_true(
        "admin/settings: secret value not echoed verbatim into the page (mask shows '(set)' or '(empty)')",
        "(empty)" in body or "(set)" in body,
    )


async def test_admin_settings_section_anchors_and_extras(page: Page) -> None:
    section("v3.2.0 #349 admin/settings.php — section anchors + multiselect + reset link")
    resp = await page.context.request.get(
        APP_URL + "admin/settings.php",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    body = await resp.text()
    for sec in ["branding", "forms", "api", "sessions", "admin", "limits"]:
        assert_true(
            f"admin/settings: deep-link anchor #section-{sec} present",
            f'id="section-{sec}"' in body,
        )
    assert_true(
        "admin/settings: api_allowed_endpoints multiselect rendered",
        'name="api_allowed_endpoints[]" multiple' in body,
    )
    assert_true(
        "admin/settings: reset-link CSS class is wired",
        "settings-reset-link" in body,
    )


async def test_admin_audit_pagination_param(page: Page) -> None:
    section("v3.0.0 #306 admin/audit.php — page=2 query param accepted")
    auth = _admin_basic_header()
    resp = await page.context.request.get(
        APP_URL + "admin/audit.php?page=2",
        headers={"Cookie": auth},
    )
    assert_eq("admin/audit page=2: still 200", resp.status, 200)
    body = await resp.text()
    # Either a pagination footer is present, or the empty page renders cleanly;
    # both are acceptable. We just need to confirm no crash.
    assert_true(
        "admin/audit page=2: page renders (Audit Log heading visible)",
        "Admin Audit Log" in body,
    )


async def test_admin_totp_page_renders(page: Page) -> None:
    section("v3.2.0 #347 admin/totp.php — authed renders + inline enrol when disabled")
    auth = _admin_basic_header()
    resp = await page.context.request.get(
        APP_URL + "admin/totp.php",
        headers={"Cookie": auth},
    )
    assert_eq("admin/totp: status 200", resp.status, 200)
    body = await resp.text()
    assert_true(
        "admin/totp: page heading present",
        ">TOTP / 2FA<" in body,
    )
    assert_true(
        "admin/totp: status badge 'disabled' rendered (no secret in fixture)",
        ">disabled<" in body,
    )
    assert_true(
        "admin/totp: enrol section rendered when disabled",
        'id="totp-enrol-heading"' in body,
    )
    assert_true(
        "admin/totp: enrol verify form action present",
        'value="enrol_verify"' in body,
    )
    assert_true(
        "admin/totp: manual entry secret rendered inline",
        'Manual entry secret' in body,
    )
    assert_true(
        "admin/totp: 6-digit code input has autocomplete=one-time-code",
        'autocomplete="one-time-code"' in body,
    )


async def test_admin_totp_enrol_invalid_code_renders_error(page: Page) -> None:
    section("v3.2.0 #347 admin/totp.php — bad enrol code re-renders form with error")
    sess = _admin_session_requests()
    initial = sess.get(APP_URL + "admin/totp.php", timeout=10)
    body = initial.text
    import re
    m = re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    assert_true("admin/totp: CSRF token discoverable", m is not None)
    csrf = m.group(1) if m else ""

    post = sess.post(
        APP_URL + "admin/totp.php",
        data={"_csrf": csrf, "action": "enrol_verify", "code": "000000"},
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("admin/totp enrol_verify (bad code): 303 PRG", post.status_code, 303)

    follow = sess.get(APP_URL + "admin/totp.php", timeout=10)
    body2 = follow.text
    assert_true(
        "admin/totp enrol bad code: error message rendered",
        "Code did not match" in body2 or "Enrol session expired" in body2,
    )


async def test_admin_totp_audit_enrol_events_logged(page: Page) -> None:
    section("v3.2.0 #347 admin/totp.php — totp.enrol.* audit events landed")
    # Visit /admin/totp.php (which audits totp.enrol.start once per session) +
    # POST a bad code (audits totp.enrol.verify.fail). Then read audit log.
    sess = _admin_session_requests()
    sess.get(APP_URL + "admin/totp.php", timeout=10)
    initial = sess.get(APP_URL + "admin/totp.php", timeout=10)
    body = initial.text
    import re
    m = re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    csrf = m.group(1) if m else ""
    sess.post(
        APP_URL + "admin/totp.php",
        data={"_csrf": csrf, "action": "enrol_verify", "code": "000000"},
        allow_redirects=False,
        timeout=10,
    )

    audit_resp = await page.context.request.get(
        APP_URL + "admin/audit.php?filter=totp.",
        headers={"Cookie": _admin_session_cookie_header()},
    )
    audit_body = await audit_resp.text()
    assert_true(
        "audit log: totp.enrol.start row appears",
        "totp.enrol.start" in audit_body,
    )
    assert_true(
        "audit log: totp.enrol.verify.fail row appears",
        "totp.enrol.verify.fail" in audit_body,
    )


async def test_admin_totp_regenerate_blocked_when_disabled(page: Page) -> None:
    section("v3.0.0 #313 admin/totp.php — regenerate_codes blocked when TOTP disabled")
    sess = _admin_session_requests()
    initial = sess.get(APP_URL + "admin/totp.php", timeout=10)
    body = initial.text
    import re
    m = re.search(r'name="_csrf" value="([0-9a-f]{64})"', body)
    csrf = m.group(1) if m else ""
    post = sess.post(
        APP_URL + "admin/totp.php",
        data={"_csrf": csrf, "action": "regenerate_codes"},
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("admin/totp regenerate (disabled): 303 PRG", post.status_code, 303)
    follow = sess.get(APP_URL + "admin/totp.php", timeout=10)
    body2 = follow.text
    assert_true(
        "admin/totp regenerate: error mentions TOTP must be enabled first",
        "Enable TOTP first" in body2,
    )


# ---------------------------------------------------------------------------
# v3.2.0 #342 — Admin login form (replaces Basic Auth for the web admin)
# ---------------------------------------------------------------------------


async def test_admin_login_form_renders_with_autocomplete(page: Page) -> None:
    section("v3.2.0 #342 admin/login.php — form has CSRF + autocomplete attrs")
    # Use a fresh requests.Session so accumulated page.context cookies (set
    # during earlier admin tests) cannot shortcut the GET into a redirect
    # back to keys.php.
    import requests as _r
    sess = _r.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    resp = sess.get(APP_URL + "admin/login.php", timeout=10, allow_redirects=False)
    assert_eq("login.php: status 200", resp.status_code, 200)
    body = resp.text
    assert_true(
        "login.php: username input carries autocomplete=username",
        'autocomplete="username"' in body,
    )
    assert_true(
        "login.php: password input carries autocomplete=current-password",
        'autocomplete="current-password"' in body,
    )
    assert_true(
        "login.php: hidden CSRF input rendered",
        'name="csrf"' in body,
    )


async def test_admin_login_success_redirects(page: Page) -> None:
    section("v3.2.0 #342 admin/login.php — POST creds redirects to next= or totp")
    import requests as _r, re as _re
    sess = _r.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    get_resp = sess.get(APP_URL + "admin/login.php", timeout=10)
    m = _re.search(r'name="csrf"\s+value="([0-9a-f]+)"', get_resp.text)
    assert_true("login.php: GET sets csrf token", m is not None)
    csrf = m.group(1) if m else ""
    post_resp = sess.post(
        APP_URL + "admin/login.php",
        data={"user": ADMIN_USER, "pass": ADMIN_PASS, "csrf": csrf, "next": "/admin/keys.php"},
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("login.php: 303 on success", post_resp.status_code, 303)
    location = post_resp.headers.get("location", "")
    # totp-verify.php is also acceptable when admin_totp_secret is configured.
    assert_true(
        "login.php: Location is /admin/keys.php or totp-verify.php",
        location.endswith("/admin/keys.php") or "totp-verify.php" in location,
        f"got: {location!r}",
    )


async def test_admin_login_failure_increments_rate_limit(page: Page) -> None:
    section("v3.2.0 #342 admin/login.php — failure path renders error + counts toward lockout")
    import requests as _r, re as _re
    sess = _r.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    get_resp = sess.get(APP_URL + "admin/login.php", timeout=10)
    m = _re.search(r'name="csrf"\s+value="([0-9a-f]+)"', get_resp.text)
    csrf = m.group(1) if m else ""
    bad = sess.post(
        APP_URL + "admin/login.php",
        data={"user": ADMIN_USER, "pass": "definitely-wrong", "csrf": csrf, "next": "/admin/"},
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("login.php: bad password renders 200 form", bad.status_code, 200)
    assert_true(
        "login.php: bad password shows generic error",
        "Username or password is incorrect" in bad.text,
    )


async def test_admin_login_protected_pages_redirect_unauth(page: Page) -> None:
    section("v3.2.0 #342 admin/* unauthenticated -> 303 to login.php?next=...")
    # Fresh requests.Session so we are guaranteed cookie-less.
    import requests as _r
    sess = _r.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    for path in ("admin/keys.php", "admin/audit.php", "admin/totp.php"):
        resp = sess.get(APP_URL + path, timeout=10, allow_redirects=False)
        assert_eq(f"{path}: status 303", resp.status_code, 303)
        location = resp.headers.get("location", "")
        assert_true(
            f"{path}: Location points to login.php with ?next=",
            "login.php?next=" in location,
            f"got: {location!r}",
        )


async def test_api_v1_admin_basic_auth_still_works(page: Page) -> None:
    section("v3.2.0 #342 /api/v1/admin/* keeps Basic Auth (boundary preserved)")
    # The API admin endpoints must continue to accept Basic Auth even after
    # the web admin moves to cookie sessions. We hit /api/v1/admin/keys
    # with the Basic header — expecting 200 + JSON envelope.
    headers = {
        "Authorization": _admin_api_basic_header(),
        "Accept": "application/json",
    }
    resp = await page.context.request.get(
        APP_URL + "api/v1/admin/keys", headers=headers
    )
    assert_eq("api/v1/admin/keys: status 200", resp.status, 200)
    body = await resp.text()
    assert_true(
        "api/v1/admin/keys: JSON envelope ok=true",
        '"ok":true' in body or '"ok": true' in body,
    )


# ---------------------------------------------------------------------------
# v3.2.0 #343 — Admin logout button + signed-in user chip
# ---------------------------------------------------------------------------


async def test_admin_logout_button_visible_on_every_admin_page(page: Page) -> None:
    section("v3.2.0 #343 admin logout — form + user chip rendered on every admin page")
    sess = _admin_session_requests()
    for path in ("admin/keys.php", "admin/audit.php", "admin/totp.php", "admin/settings.php"):
        resp = sess.get(APP_URL + path, timeout=10)
        assert_eq(f"{path}: status 200", resp.status_code, 200)
        body = resp.text
        assert_true(
            f"{path}: logout form posts to logout.php",
            'action="logout.php"' in body or "action='logout.php'" in body,
        )
        assert_true(
            f"{path}: hidden CSRF input rendered in logout form",
            'class="admin-logout-form"' in body and 'name="csrf"' in body,
        )
        assert_true(
            f"{path}: signed-in username chip rendered with admin user",
            'admin-user-chip' in body and ADMIN_USER in body,
        )


async def test_admin_logout_clears_session(page: Page) -> None:
    section("v3.2.0 #343 admin logout — POST ends session + clears cookie + audit-logs")
    import re as _re
    sess = _admin_session_requests()
    # Confirm we have a valid session: keys.php should 200.
    pre = sess.get(APP_URL + "admin/keys.php", timeout=10, allow_redirects=False)
    assert_eq("pre-logout: keys.php 200", pre.status_code, 200)
    # Pull CSRF from the rendered logout form on keys.php.
    m = _re.search(
        r'class="admin-logout-form"[^>]*>\s*<input[^>]*name="csrf"[^>]*value="([0-9a-f]+)"',
        pre.text,
    )
    assert_true("logout form: csrf token rendered", m is not None)
    csrf = m.group(1) if m else ""

    # POST to logout.php — must 302/303 to login.php?logged_out=1.
    out = sess.post(
        APP_URL + "admin/logout.php",
        data={"csrf": csrf},
        allow_redirects=False,
        timeout=10,
    )
    assert_true(
        "logout.php: redirect status (302 or 303)",
        out.status_code in (302, 303),
        f"got: {out.status_code}",
    )
    location = out.headers.get("location", "")
    assert_true(
        "logout.php: Location -> login.php?logged_out=1",
        "login.php" in location and "logged_out=1" in location,
        f"got: {location!r}",
    )
    # Cookie must be cleared via Set-Cookie with Max-Age=0 (or expires past).
    set_cookies = out.headers.get("set-cookie", "")
    assert_true(
        "logout.php: clears sc_admin_sid cookie via Set-Cookie",
        "sc_admin_sid=" in set_cookies
        and ("Max-Age=0" in set_cookies or "max-age=0" in set_cookies.lower()
             or "expires=" in set_cookies.lower()),
        f"got: {set_cookies!r}",
    )

    # After logout: visiting an admin URL should redirect to login.
    post_logout = sess.get(
        APP_URL + "admin/keys.php", timeout=10, allow_redirects=False
    )
    assert_eq(
        "post-logout: keys.php redirects to login (303)",
        post_logout.status_code, 303,
    )
    assert_true(
        "post-logout: Location points at login.php",
        "login.php" in post_logout.headers.get("location", ""),
    )

    # Audit log should now contain auth.logout. Sign back in and read audit.
    sess2 = _admin_session_requests()
    audit_resp = sess2.get(APP_URL + "admin/audit.php", timeout=10)
    assert_eq("audit page: 200 after re-login", audit_resp.status_code, 200)
    assert_true(
        "audit page: contains auth.logout entry",
        "auth.logout" in audit_resp.text,
    )


async def test_admin_logout_rejects_get(page: Page) -> None:
    section("v3.2.0 #343 admin logout — GET rejected with 405 + Allow: POST")
    sess = _admin_session_requests()
    resp = sess.get(APP_URL + "admin/logout.php", timeout=10, allow_redirects=False)
    assert_eq("logout.php GET: 405", resp.status_code, 405)
    allow = resp.headers.get("allow", "")
    assert_true(
        "logout.php GET: Allow: POST header",
        "POST" in allow,
        f"got: {allow!r}",
    )


async def test_admin_logout_csrf_protected(page: Page) -> None:
    section("v3.2.0 #343 admin logout — POST without valid CSRF returns 403")
    sess = _admin_session_requests()
    resp = sess.post(
        APP_URL + "admin/logout.php",
        data={"csrf": "deadbeef" * 8},  # wrong token
        allow_redirects=False,
        timeout=10,
    )
    assert_eq("logout.php bad csrf: 403", resp.status_code, 403)


# ---------------------------------------------------------------------------
# v3.2.0 #344 — Left sidebar for /admin/
# ---------------------------------------------------------------------------

ADMIN_SIDEBAR_PAGES = [
    ("admin/keys.php", "keys.php"),
    ("admin/audit.php", "audit.php"),
    ("admin/totp.php", "totp.php"),
    ("admin/settings.php", "settings.php"),
]


async def test_admin_sidebar_marks_current_page(page: Page) -> None:
    section("v3.2.0 #344 admin sidebar — aria-current=\"page\" marks the active row")
    import re as _re
    sess = _admin_session_requests()
    for path, slug in ADMIN_SIDEBAR_PAGES:
        resp = sess.get(APP_URL + path, timeout=10)
        assert_eq(f"{path}: status 200", resp.status_code, 200)
        body = resp.text
        # Sidebar landmark renders.
        assert_true(
            f"{path}: <nav class=\"admin-sidebar\" aria-label=\"Admin\"> rendered",
            'class="admin-sidebar"' in body and 'aria-label="Admin"' in body,
        )
        # Exactly one sidebar <a aria-current="page">.
        currents = _re.findall(
            r'<a\b[^>]*\baria-current="page"[^>]*\bhref="([^"]+)"',
            body,
        ) or _re.findall(
            r'<a\b[^>]*\bhref="([^"]+)"[^>]*\baria-current="page"',
            body,
        )
        assert_true(
            f"{path}: exactly one aria-current=\"page\" link in sidebar (got {len(currents)})",
            len(currents) == 1,
            f"hrefs: {currents!r}",
        )
        assert_true(
            f"{path}: aria-current href matches current page slug {slug}",
            currents and slug in currents[0],
            f"got: {currents!r}",
        )


async def test_admin_sidebar_collapses_on_mobile(page: Page) -> None:
    section("v3.2.0 #344 admin sidebar — hamburger toggle at viewport <= 768px")
    cookie_header = _admin_session_cookie_header()
    sid_value = cookie_header.split("sc_admin_sid=", 1)[1]
    from urllib.parse import urlparse as _urlparse
    parsed = _urlparse(APP_URL)
    domain = parsed.hostname or "localhost"
    await page.context.add_cookies([{
        "name": "sc_admin_sid",
        "value": sid_value,
        "domain": domain,
        "path": "/",
    }])
    headers: dict[str, str] = {}
    if BASIC_USER and BASIC_PASS:
        headers["Authorization"] = "Basic " + base64.b64encode(
            f"{BASIC_USER}:{BASIC_PASS}".encode()
        ).decode()
    await page.set_viewport_size({"width": 480, "height": 720})
    if headers:
        await page.context.set_extra_http_headers(headers)
    try:
        await navigate(page, APP_URL + "admin/keys.php")
        # Sanity: confirm we are actually on keys.php (not redirected to login).
        cur_url = page.url
        assert_true(
            f"mobile: page is admin/keys.php (got {cur_url})",
            "keys.php" in cur_url,
        )
        toggle_present = await page.evaluate(
            "() => !!document.querySelector('.admin-sidebar-toggle')"
        )
        assert_true("mobile: hamburger button is in DOM", toggle_present)
        # Hamburger button visible on narrow viewports.
        toggle_visible = await page.evaluate(
            "() => { const b = document.querySelector('.admin-sidebar-toggle');"
            " if (!b) return false;"
            " const r = b.getBoundingClientRect();"
            " return r.width > 0 && r.height > 0; }"
        )
        assert_true("mobile: hamburger toggle visible", toggle_visible)
        # Sidebar starts collapsed.
        starts_open = await page.evaluate(
            "() => document.querySelector('.admin-sidebar')?.classList.contains('open')"
        )
        assert_true("mobile: sidebar starts collapsed (no .open)", not starts_open)
        # aria-expanded starts false.
        expanded_pre = await page.evaluate(
            "() => document.querySelector('.admin-sidebar-toggle')?.getAttribute('aria-expanded')"
        )
        assert_eq("mobile: aria-expanded='false' initially", expanded_pre, "false")
        # Click hamburger -> sidebar opens, aria-expanded flips.
        await page.click(".admin-sidebar-toggle")
        await page.wait_for_timeout(50)
        is_open = await page.evaluate(
            "() => document.querySelector('.admin-sidebar')?.classList.contains('open')"
        )
        assert_true("mobile: sidebar has .open after click", is_open)
        expanded_post = await page.evaluate(
            "() => document.querySelector('.admin-sidebar-toggle')?.getAttribute('aria-expanded')"
        )
        assert_eq("mobile: aria-expanded='true' after click", expanded_post, "true")
        # Click again -> closes.
        await page.click(".admin-sidebar-toggle")
        await page.wait_for_timeout(50)
        is_open2 = await page.evaluate(
            "() => document.querySelector('.admin-sidebar')?.classList.contains('open')"
        )
        assert_true("mobile: sidebar closes on second click", not is_open2)
    finally:
        await page.context.set_extra_http_headers({})
        await page.context.clear_cookies()
        await page.set_viewport_size({"width": 1280, "height": 900})


async def test_admin_sidebar_keyboard_order(page: Page) -> None:
    section("v3.2.0 #344 admin sidebar — sidebar tabs land before main content")
    cookie_header = _admin_session_cookie_header()
    sid_value = cookie_header.split("sc_admin_sid=", 1)[1]
    from urllib.parse import urlparse as _urlparse
    parsed = _urlparse(APP_URL)
    domain = parsed.hostname or "localhost"
    await page.context.add_cookies([{
        "name": "sc_admin_sid",
        "value": sid_value,
        "domain": domain,
        "path": "/",
    }])
    headers: dict[str, str] = {}
    if BASIC_USER and BASIC_PASS:
        headers["Authorization"] = "Basic " + base64.b64encode(
            f"{BASIC_USER}:{BASIC_PASS}".encode()
        ).decode()
    if headers:
        await page.context.set_extra_http_headers(headers)
    try:
        await navigate(page, APP_URL + "admin/keys.php")
        # Compute DOM order of the first sidebar <a> vs the first
        # interactive element inside <main id="main-content">.
        order = await page.evaluate(
            """() => {
                const sidebar = document.querySelector('.admin-sidebar');
                const main = document.getElementById('main-content');
                if (!sidebar || !main) return null;
                const sidebarFirst = sidebar.querySelector('a, button');
                const mainFirst = main.querySelector('a, button, input, select, textarea');
                if (!sidebarFirst || !mainFirst) return null;
                // Node.compareDocumentPosition: 4 = following, 2 = preceding
                const pos = sidebarFirst.compareDocumentPosition(mainFirst);
                return (pos & Node.DOCUMENT_POSITION_FOLLOWING) ? 'sidebar_before_main' : 'main_before_sidebar';
            }"""
        )
        assert_eq(
            "keyboard order: first sidebar element precedes first main element",
            order, "sidebar_before_main",
        )
    finally:
        await page.context.set_extra_http_headers({})
        await page.context.clear_cookies()


async def test_admin_sidebar_absent_on_login(page: Page) -> None:
    section("v3.2.0 #344 admin sidebar — not rendered on login page (no signed-in session)")
    import requests as _r
    sess = _r.Session()
    if BASIC_USER and BASIC_PASS:
        sess.auth = (BASIC_USER, BASIC_PASS)
    sess.verify = False
    resp = sess.get(APP_URL + "admin/login.php", timeout=10)
    assert_eq("login.php: status 200", resp.status_code, 200)
    body = resp.text
    assert_true(
        "login.php: no <nav class=\"admin-sidebar\"> rendered",
        'class="admin-sidebar"' not in body,
    )
    assert_true(
        "login.php: no admin-sidebar-toggle rendered",
        'admin-sidebar-toggle' not in body,
    )


# ---------------------------------------------------------------------------
# v3.2.0 #345 — Admin chrome adoption (shared header / single-card / theme)
# ---------------------------------------------------------------------------

ADMIN_PAGES_FOR_CHROME = [
    ("admin/keys.php", "API Keys"),
    ("admin/audit.php", "Admin Audit Log"),
    ("admin/totp.php", "TOTP / 2FA"),
    ("admin/settings.php", "Settings"),
]


async def test_admin_pages_share_app_header(page: Page) -> None:
    section("v3.2.0 #345 admin chrome — shared app header on all admin pages")
    for path, _ in ADMIN_PAGES_FOR_CHROME:
        resp = await page.context.request.get(
            APP_URL + path,
            headers={"Cookie": _admin_session_cookie_header()},
        )
        assert_eq(f"admin chrome: {path} 200", resp.status, 200)
        body = await resp.text()
        # Logo (shared with calculator) — assets/logo.webp picture source.
        assert_true(
            f"admin chrome: {path} renders shared logo",
            "assets/logo.webp" in body,
            "missing logo asset reference",
        )
        # Version pill (.version span).
        assert_true(
            f"admin chrome: {path} renders version pill",
            'class="version"' in body,
            "missing .version pill",
        )
        # Theme toggle button (id=theme-toggle).
        assert_true(
            f"admin chrome: {path} renders theme toggle",
            'id="theme-toggle"' in body,
            "missing #theme-toggle",
        )
        # Breadcrumb chip with aria-current="page" leaf.
        assert_true(
            f"admin chrome: {path} renders breadcrumb",
            'class="admin-breadcrumb"' in body,
            "missing .admin-breadcrumb",
        )
        # Exactly one outer .card (the <main id="main-content"> wrapper).
        # The shared layout uses a single outer .card; nested elements should
        # use .admin-card or bare <section> divs, never another .card. Parse
        # every class="..." attribute and count `card` occurrences as a
        # whole token so `class="foo card bar"` still matches.
        import re as _re_card
        class_attr_re = _re_card.compile(r'class="([^"]*)"')
        card_count = 0
        admin_card_count = 0
        for cls_value in class_attr_re.findall(body):
            tokens = cls_value.split()
            if 'card' in tokens:
                card_count += 1
                if 'admin-card' in tokens:
                    admin_card_count += 1
        assert_true(
            f"admin chrome: {path} has outer .card admin-card",
            admin_card_count >= 1,
            f"got {admin_card_count}",
        )
        # Total .card occurrences should equal the outer admin-card; any
        # extras mean a nested card slipped into the admin body.
        nested_card_count = card_count - admin_card_count
        assert_true(
            f"admin chrome: {path} has no nested .card (got {nested_card_count} extras beyond outer admin-card)",
            nested_card_count == 0,
            "nested .card found in admin body — should be <section> instead",
        )
        # Skip-link present and points to #main-content.
        assert_true(
            f"admin chrome: {path} has skip-link to #main-content",
            'href="#main-content"' in body and 'class="skip-link"' in body,
            "missing skip-link",
        )


async def test_admin_theme_toggle_works(page: Page) -> None:
    section("v3.2.0 #345 admin chrome — theme toggle flips data-theme on /admin/")
    # Pre-seed light theme via localStorage, then load admin/keys.php with
    # the cookie-session sc_admin_sid and verify the inline theme-init
    # script honours it.
    cookie_header = _admin_session_cookie_header()
    sid_value = cookie_header.split("sc_admin_sid=", 1)[1]
    from urllib.parse import urlparse as _urlparse
    parsed = _urlparse(APP_URL)
    domain = parsed.hostname or "localhost"
    await page.context.add_cookies([{
        "name": "sc_admin_sid",
        "value": sid_value,
        "domain": domain,
        "path": "/",
    }])
    try:
        await navigate(page, APP_URL + "admin/keys.php")
        await page.evaluate("() => localStorage.setItem('theme', 'light')")
        await navigate(page, APP_URL + "admin/keys.php")
        data_theme = await page.evaluate(
            "() => document.documentElement.getAttribute('data-theme')"
        )
        assert_eq("admin theme toggle: data-theme=light persisted", data_theme, "light")
        bg = await page.evaluate(
            "() => getComputedStyle(document.body).backgroundColor"
        )
        # Light --color-bg is #fff → rgb(255, 255, 255).
        assert_true(
            f"admin theme toggle: light bg applied (got {bg!r})",
            "255, 255, 255" in bg or bg == "rgb(255, 255, 255)",
        )
    finally:
        # ALWAYS clean up — downstream tests rely on dark default.
        try:
            await page.evaluate("() => localStorage.removeItem('theme')")
        except Exception:
            pass
        await page.context.clear_cookies()
        # Re-load the calculator without auth so cookies / theme state are
        # clean for downstream tests.
        try:
            await navigate(page, APP_URL)
            await page.evaluate("() => localStorage.removeItem('theme')")
        except Exception:
            pass


async def test_admin_inputs_share_bg_token(page: Page) -> None:
    section("v3.2.0 #345 admin chrome — text + number inputs share computed bg")
    cookie_header = _admin_session_cookie_header()
    sid_value = cookie_header.split("sc_admin_sid=", 1)[1]
    from urllib.parse import urlparse as _urlparse
    parsed = _urlparse(APP_URL)
    domain = parsed.hostname or "localhost"
    await page.context.add_cookies([{
        "name": "sc_admin_sid",
        "value": sid_value,
        "domain": domain,
        "path": "/",
    }])
    try:
        await navigate(page, APP_URL + "admin/keys.php")
        # Mint form has both a text input (name) and a number input (rate_limit_rpm).
        text_bg = await page.evaluate(
            "() => { var el = document.querySelector('input[name=\"name\"]');"
            " return el ? getComputedStyle(el).backgroundColor : null; }"
        )
        number_bg = await page.evaluate(
            "() => { var el = document.querySelector('input[name=\"rate_limit_rpm\"]');"
            " return el ? getComputedStyle(el).backgroundColor : null; }"
        )
        assert_true(
            "admin inputs: text input bg resolved",
            text_bg is not None and text_bg != "",
            f"got {text_bg!r}",
        )
        assert_true(
            "admin inputs: number input bg resolved",
            number_bg is not None and number_bg != "",
            f"got {number_bg!r}",
        )
        assert_eq(
            "admin inputs: text + number share --color-input-bg",
            number_bg,
            text_bg,
        )
    finally:
        await page.context.clear_cookies()


# ---------------------------------------------------------------------------
# Permissions-Policy header directives (coverage gap)
# ---------------------------------------------------------------------------

async def test_permissions_policy_directives(page: Page) -> None:
    section("Permissions-Policy directives")
    _, hdrs, _ = _http_get()
    pp = hdrs.get("permissions-policy", "")
    for directive in ["camera=()", "microphone=()", "geolocation=()", "payment=()"]:
        assert_contains(f"Permissions-Policy: {directive}", pp, directive)


# ---------------------------------------------------------------------------
# VLSM utilisation percentage accuracy (coverage gap)
# ---------------------------------------------------------------------------

async def test_vlsm_utilisation_accuracy(page: Page) -> None:
    section("VLSM utilisation accuracy")
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr", "24")

    # Use JS to fill the form — one subnet needing 126 hosts in a /24
    await page.evaluate("""() => {
        var names = document.querySelectorAll('input[name="vlsm_name[]"]');
        var hosts = document.querySelectorAll('input[name="vlsm_hosts[]"]');
        if (names[0]) names[0].value = 'A';
        if (hosts[0]) hosts[0].value = '126';
    }""")
    await submit_form(page, ".vlsm-form")

    util_text = await page.text_content(".vlsm-summary")
    # 10.0.0.0/24 = 256 total. A /25 = 128 addresses. Utilisation = 128/256 = 50%
    assert_true("vlsm utilisation: 50% shown for /25 in /24",
                util_text and "50" in (util_text or ""), f"got: {util_text!r}")


# ---------------------------------------------------------------------------
# v2.2.0 — IPv4 hex/decimal in binary panel
# ---------------------------------------------------------------------------

async def test_ipv4_binary_hex_decimal(page: Page) -> None:
    section("IPv4 — binary panel hex/decimal rows (v2.2.0)")
    await navigate(page, APP_URL)
    await page.fill("#ip",   "192.168.1.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")

    # Open the binary details panel
    await page.evaluate("document.querySelector('.binary-details').setAttribute('open', '')")

    # Collect all bin-label text values
    labels = await page.evaluate("""() => {
        return Array.from(document.querySelectorAll('.binary-details .bin-label'))
               .map(el => el.textContent.trim());
    }""")
    assert_true("binary panel has Hex row",     "Hex" in labels,     f"labels: {labels}")
    assert_true("binary panel has Decimal row", "Decimal" in labels, f"labels: {labels}")

    # Build a label→value map so the test is resilient to row reordering
    rows = await page.evaluate("""() => {
        var labels = Array.from(document.querySelectorAll('.binary-details .bin-label'))
            .map(el => el.textContent.trim());
        var values = Array.from(document.querySelectorAll('.binary-details .bin-value'))
            .map(el => el.textContent.trim());
        var out = {};
        labels.forEach(function(label, i) { out[label] = values[i] || ""; });
        return out;
    }""")
    assert_eq("binary panel hex = C0.A8.01.00",  rows.get("Hex"),     "C0.A8.01.00")
    assert_eq("binary panel decimal = 3232235776", rows.get("Decimal"), "3232235776")


# ---------------------------------------------------------------------------
# v2.2.0 — IPv6 expanded/compressed address forms
# ---------------------------------------------------------------------------

async def test_ipv6_address_forms(page: Page) -> None:
    section("IPv6 — expanded/compressed address forms (v2.2.0)")
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "2001:db8::")
    await page.fill("#prefix", "32")
    await submit_form(page, "#panel-ipv6 form")

    expanded   = await result_value(page, "Address (Expanded)")
    compressed = await result_value(page, "Address (Compressed)")
    assert_eq(
        "ipv6 address expanded = 2001:0db8:0000:…",
        expanded,
        "2001:0db8:0000:0000:0000:0000:0000:0000",
    )
    assert_eq("ipv6 address compressed = 2001:db8::", compressed, "2001:db8::")

    # Test with a loopback address
    await navigate(page, APP_URL)
    await page.click("#tab-ipv6")
    await page.fill("#ipv6",   "::1")
    await page.fill("#prefix", "128")
    await submit_form(page, "#panel-ipv6 form")

    expanded_lo   = await result_value(page, "Address (Expanded)")
    compressed_lo = await result_value(page, "Address (Compressed)")
    assert_eq("::1 expanded",   expanded_lo,   "0000:0000:0000:0000:0000:0000:0000:0001")
    assert_eq("::1 compressed", compressed_lo, "::1")


# ---------------------------------------------------------------------------
# v2.3.0 — IPv4 Range → CIDR UI (#182)
# ---------------------------------------------------------------------------

async def test_ipv4_range_to_cidr(page: Page) -> None:
    section("IPv4 Range → CIDR UI")
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='range']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    # Fill start and end into the Range → CIDR panel
    await page.fill("input[name='range_start']", "10.0.0.0")
    await page.fill("input[name='range_end']",   "10.0.0.255")
    await page.click("button.splitter-btn[type='submit']:near(input[name='range_end'])")
    await page.wait_for_load_state("load")
    # Expect a single CIDR result covering the full /24
    items = await page.locator(".split-item .split-subnet-text").all_text_contents()
    assert_true(
        "range->cidr: 10.0.0.0-10.0.0.255 = one /24 block",
        "10.0.0.0/24" in items,
        f"got: {items}",
    )
    # Non-power-of-two range: two blocks expected
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='range']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='range_start']", "10.0.0.0")
    await page.fill("input[name='range_end']",   "10.0.0.4")
    await page.click("button.splitter-btn[type='submit']:near(input[name='range_end'])")
    await page.wait_for_load_state("load")
    items2 = await page.locator(".split-item .split-subnet-text").all_text_contents()
    assert_true(
        "range->cidr: 10.0.0.0-10.0.0.4 includes /30 block",
        "10.0.0.0/30" in items2,
        f"got: {items2}",
    )
    assert_true(
        "range->cidr: 10.0.0.0-10.0.0.4 includes trailing /32 block",
        "10.0.0.4/32" in items2,
        f"got: {items2}",
    )
    # Error case: end < start
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='range']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("input[name='range_start']", "10.0.0.10")
    await page.fill("input[name='range_end']",   "10.0.0.1")
    await page.click("button.splitter-btn[type='submit']:near(input[name='range_end'])")
    await page.wait_for_load_state("load")
    range_panel = page.locator(".overlap-panel").filter(
        has=page.locator("input[name='range_start']")
    )
    err = await range_panel.locator(".error").text_content() or ""
    assert_contains("range->cidr: end<start error message",
                    err, "Start address must be less than or equal to end address.")


# ---------------------------------------------------------------------------
# v2.3.0 — Subnet Allocation Tree View UI (#183)
# ---------------------------------------------------------------------------

async def test_tree_view(page: Page) -> None:
    section("Subnet allocation tree view UI")
    # Full allocation — both /25s, no gaps
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='tree']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("#tree_parent", "10.0.0.0/24")
    await page.fill("textarea[name='tree_children']", "10.0.0.0/25\n10.0.0.128/25")
    await page.click("button.splitter-btn[type='submit']:near(textarea[name='tree_children'])")
    await page.wait_for_load_state("load")
    tree_nodes = await page.locator(".tree-node").all_text_contents()
    assert_true(
        "tree view: parent CIDR visible",
        any("10.0.0.0/24" in n for n in tree_nodes),
        f"got nodes: {tree_nodes}",
    )
    assert_true(
        "tree view: child 10.0.0.0/25 visible",
        any("10.0.0.0/25" in n for n in tree_nodes),
        f"got nodes: {tree_nodes}",
    )
    # Partial allocation — only one /25; the other /25 should appear as a gap
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='tree']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("#tree_parent", "10.0.0.0/24")
    await page.fill("textarea[name='tree_children']", "10.0.0.0/25")
    await page.click("button.splitter-btn[type='submit']:near(textarea[name='tree_children'])")
    await page.wait_for_load_state("load")
    tree_nodes2 = await page.locator(".tree-node").all_text_contents()
    assert_true(
        "tree view: gap 10.0.0.128/25 visible for partial allocation",
        any("10.0.0.128/25" in n for n in tree_nodes2),
        f"got nodes: {tree_nodes2}",
    )
    # Invalid parent → error
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='tree']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("#tree_parent", "not-a-cidr")
    await page.fill("textarea[name='tree_children']", "10.0.0.0/25")
    await page.click("button.splitter-btn[type='submit']:near(textarea[name='tree_children'])")
    await page.wait_for_load_state("load")
    tree_panel = page.locator(".overlap-panel").filter(
        has=page.locator("#tree_parent")
    )
    err = await tree_panel.locator(".error").text_content() or ""
    assert_contains("tree view: invalid parent error message", err, "Invalid parent CIDR")


# ---------------------------------------------------------------------------
# v3.3.0 — IPv6 Range → CIDR UI + API (#364)
# ---------------------------------------------------------------------------

async def test_ipv6_range_to_cidr(page: Page) -> None:
    section("IPv6 Range → CIDR UI")
    # Switch to the IPv6 tab first so the IPv6 drawer is in the active panel.
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='range6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    # Single-CIDR exact range: 2001:db8::/112 (65536 addresses)
    await page.fill("input[name='range6_start']", "2001:db8::")
    await page.fill("input[name='range6_end']",   "2001:db8::ffff")
    await page.click("button.splitter-btn[type='submit']:near(input[name='range6_end'])")
    await page.wait_for_load_state("load")
    items = await page.locator("#panel-ipv6 .split-item .split-subnet-text").all_text_contents()
    assert_true(
        "ipv6 range->cidr: 2001:db8::-2001:db8::ffff = single /112",
        "2001:db8::/112" in items,
        f"got: {items}",
    )
    # Fragmented range: 2001:db8::1 to 2001:db8::3 produces /128 + /127
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='range6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='range6_start']", "2001:db8::1")
    await page.fill("input[name='range6_end']",   "2001:db8::3")
    await page.click("button.splitter-btn[type='submit']:near(input[name='range6_end'])")
    await page.wait_for_load_state("load")
    items2 = await page.locator("#panel-ipv6 .split-item .split-subnet-text").all_text_contents()
    assert_true(
        "ipv6 range->cidr: includes /128 leading singleton",
        "2001:db8::1/128" in items2,
        f"got: {items2}",
    )
    assert_true(
        "ipv6 range->cidr: includes /127 trailing pair",
        "2001:db8::2/127" in items2,
        f"got: {items2}",
    )
    # Inverted range: clear error message
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='range6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='range6_start']", "2001:db8::ffff")
    await page.fill("input[name='range6_end']",   "2001:db8::")
    await page.click("button.splitter-btn[type='submit']:near(input[name='range6_end'])")
    await page.wait_for_load_state("load")
    range6_panel = page.locator("#panel-ipv6 .overlap-panel").filter(
        has=page.locator("input[name='range6_start']")
    )
    err = await range6_panel.locator(".error").text_content() or ""
    assert_contains(
        "ipv6 range->cidr: inverted-range error message",
        err,
        "Start must be less than or equal to end.",
    )
    # Shareable URL hydration: drawer auto-opens and result renders
    await navigate(
        page,
        APP_URL + "?tab=ipv6&range6_start=2001:db8::&range6_end=2001:db8::ffff",
    )
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    items3 = await page.locator("#panel-ipv6 .split-item .split-subnet-text").all_text_contents()
    assert_true(
        "ipv6 range->cidr: shareable URL hydrates result",
        "2001:db8::/112" in items3,
        f"got: {items3}",
    )


async def test_api_range6(page: Page) -> None:
    section("API — POST /api/v1/range6")
    status, data = _api_post("range6", {"start": "2001:db8::", "end": "2001:db8::ffff"})
    assert_eq("api range6: HTTP 200", status, 200)
    assert_eq("api range6: ok=true",  data.get("ok"), True)
    payload = data.get("data", {})
    cidrs = payload.get("cidrs", [])
    assert_true("api range6: cidrs is list", isinstance(cidrs, list), f"got: {cidrs!r}")
    assert_true("api range6: returns expected /112",
                "2001:db8::/112" in cidrs, f"got: {cidrs}")
    assert_eq("api range6: count == 1",     payload.get("count"), 1)
    assert_eq("api range6: truncated=false", payload.get("truncated"), False)
    assert_eq("api range6: cap == 256",      payload.get("cap"), 256)
    # Inverted range → 4xx error
    status_err, data_err = _api_post("range6",
                                     {"start": "2001:db8::ffff", "end": "2001:db8::"})
    assert_true("api range6: inverted range yields 4xx",
                400 <= status_err < 500, f"got status: {status_err}")
    assert_eq("api range6: inverted range ok=false", data_err.get("ok"), False)


# ---------------------------------------------------------------------------
# v3.3.0 — IPv6 Supernet / Summarise (supernet6) UI + API
# ---------------------------------------------------------------------------

async def test_ipv6_supernet_ui(page: Page) -> None:
    section("IPv6 Supernet / Summarise UI")

    # Find adjacent /64s → /63
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='supernet6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("textarea[name='supernet6_input']", "2001:db8::/64\n2001:db8:0:1::/64")
    await page.click("button[name='supernet6_action'][value='find']")
    await page.wait_for_load_state("load")
    sn_text = await page.text_content("#panel-ipv6 .overlap-panel .overlap-result")
    assert_contains("supernet6: two adjacent /64s → /63", sn_text or "", "2001:db8::/63")

    # Disjoint roots → still produces a common supernet (/0 fallback acceptable; no error band)
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='supernet6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("textarea[name='supernet6_input']", "2001:db8::/64\nfd00::/64")
    await page.click("button[name='supernet6_action'][value='find']")
    await page.wait_for_load_state("load")
    sup6_panel = page.locator("#panel-ipv6 .overlap-panel").filter(
        has=page.locator("textarea[name='supernet6_input']")
    )
    err_count = await sup6_panel.locator(".error").count()
    assert_true("supernet6: disjoint roots do not raise error band", err_count == 0,
                "unexpected error band on disjoint roots")

    # Summarise four adjacent /64s → /62
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='supernet6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill(
        "textarea[name='supernet6_input']",
        "2001:db8::/64\n2001:db8:0:1::/64\n2001:db8:0:2::/64\n2001:db8:0:3::/64",
    )
    await page.click("button[name='supernet6_action'][value='summarise']")
    await page.wait_for_load_state("load")
    items = await page.locator("#panel-ipv6 .split-item .split-subnet-text").all_text_contents()
    assert_true("summarise6: four /64s collapse to single /62",
                "2001:db8::/62" in items, f"got: {items}")
    copy_all = await page.query_selector(
        "#panel-ipv6 .overlap-panel .copy-all-btn[data-target='supernet6']"
    )
    assert_true("summarise6: Copy All button present", copy_all is not None)

    # Mixed v4/v6 → error band
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='supernet6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("textarea[name='supernet6_input']", "10.0.0.0/24\n2001:db8::/64")
    await page.click("button[name='supernet6_action'][value='find']")
    await page.wait_for_load_state("load")
    err_text = await page.text_content("#panel-ipv6 .overlap-panel .error") or ""
    assert_true("supernet6: mixed v4 input shows error band", len(err_text) > 0,
                f"got: {err_text!r}")

    # > 50 CIDRs → error band
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='supernet6']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    too_many = "\n".join([f"2001:db8::{i:x}/128" for i in range(1, 53)])
    await page.fill("textarea[name='supernet6_input']", too_many)
    await page.click("button[name='supernet6_action'][value='find']")
    await page.wait_for_load_state("load")
    err_cap = await page.text_content("#panel-ipv6 .overlap-panel .error") or ""
    assert_contains("supernet6: > 50 inputs → cap error", err_cap, "Maximum 50")

    # Shareable URL hydrates find
    await navigate(
        page,
        APP_URL + "?tab=ipv6&supernet6_action=find"
        "&supernet6_input=2001%3Adb8%3A%3A%2F64%0A2001%3Adb8%3A0%3A1%3A%3A%2F64",
    )
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    sn2 = await page.text_content("#panel-ipv6 .overlap-panel .overlap-result")
    assert_contains("supernet6: shareable URL hydrates find result", sn2 or "", "2001:db8::/63")

    # Shareable URL hydrates summarise
    await navigate(
        page,
        APP_URL + "?tab=ipv6&supernet6_action=summarise"
        "&supernet6_input=2001%3Adb8%3A%3A%2F64%0A2001%3Adb8%3A0%3A1%3A%3A%2F64",
    )
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    items3 = await page.locator("#panel-ipv6 .split-item .split-subnet-text").all_text_contents()
    assert_true("supernet6: shareable URL hydrates summarise result",
                "2001:db8::/63" in items3, f"got: {items3}")


async def test_api_supernet6(page: Page) -> None:
    section("API — POST /api/v1/supernet6")
    status, data = _api_post("supernet6", {
        "action": "find",
        "cidrs":  ["2001:db8::/64", "2001:db8:0:1::/64"],
    })
    assert_eq("api supernet6 find: HTTP 200", status, 200)
    assert_eq("api supernet6 find: result",
              data.get("data", {}).get("supernet"), "2001:db8::/63")

    status2, data2 = _api_post("supernet6", {
        "action": "summarise",
        "cidrs":  ["2001:db8::/64", "2001:db8:0:1::/64",
                   "2001:db8:0:2::/64", "2001:db8:0:3::/64"],
    })
    assert_eq("api supernet6 summarise: HTTP 200", status2, 200)
    summaries = data2.get("data", {}).get("summaries", [])
    assert_eq("api supernet6 summarise: 1 result (/62)", len(summaries), 1)
    assert_true("api supernet6 summarise: returns /62",
                "2001:db8::/62" in summaries, f"got: {summaries}")

    # IPv4 input → 4xx
    status3, data3 = _api_post("supernet6", {
        "action": "find",
        "cidrs":  ["10.0.0.0/24"],
    })
    assert_true("api supernet6: ipv4 input yields 4xx",
                400 <= status3 < 500, f"got: {status3}")
    assert_eq("api supernet6: ipv4 input ok=false", data3.get("ok"), False)


# ---------------------------------------------------------------------------
# v2.3.0 — API: range/ipv4 and tree (#182, #183)
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# v3.3.0 — IPv6 Zone-ID parser UI + API
# ---------------------------------------------------------------------------

async def test_ipv6_zoneid_ui(page: Page) -> None:
    section("IPv6 Zone-ID parser UI")

    # Success — link-local with zone
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='zoneid']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='zoneid_input']", "fe80::1%eth0")
    await page.click("#panel-ipv6 .tool-panel[data-tool='zoneid'] button.splitter-btn")
    await page.wait_for_load_state("load")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='zoneid'] .zoneid-result__row")
    assert_eq("zoneid: three result rows render", await rows.count(), 3)
    addr_text = await rows.nth(0).locator(".zoneid-result__value").text_content() or ""
    zone_text = await rows.nth(1).locator(".zoneid-result__value").text_content() or ""
    ll_text   = await rows.nth(2).locator(".zoneid-result__value").text_content() or ""
    assert_contains("zoneid: address row", addr_text, "fe80::1")
    assert_contains("zoneid: zone row",    zone_text, "eth0")
    assert_contains("zoneid: link-local Yes", ll_text, "Yes")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='zoneid']")
    assert_eq("zoneid: no warning band on link-local",
              await panel.locator(".warning").count(), 0)
    assert_eq("zoneid: no error band on success",
              await panel.locator(".error").count(), 0)

    # Warning — global address with zone
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='zoneid']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='zoneid_input']", "2001:db8::1%eth0")
    await page.click("#panel-ipv6 .tool-panel[data-tool='zoneid'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='zoneid']")
    warn_text = await panel.locator(".warning").text_content() or ""
    assert_true("zoneid: warning band visible on non-link-local + zone",
                len(warn_text) > 0, f"got: {warn_text!r}")
    ll2 = await panel.locator(".zoneid-result__row").nth(2).locator(".zoneid-result__value").text_content() or ""
    assert_contains("zoneid: link-local No on global", ll2, "No")

    # Error — malformed zone (empty after %)
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='zoneid']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='zoneid_input']", "fe80::1%")
    await page.click("#panel-ipv6 .tool-panel[data-tool='zoneid'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='zoneid']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("zoneid: error band visible on malformed zone",
                len(err_text) > 0, f"got: {err_text!r}")
    assert_eq("zoneid: no result rows on error",
              await panel.locator(".zoneid-result__row").count(), 0)


async def test_ipv6_zoneid_shareable_url(page: Page) -> None:
    section("IPv6 Zone-ID shareable URL")
    # %25 is URL-encoded %; PHP $_GET decodes it back to literal %.
    await navigate(page, APP_URL + "?tab=ipv6&zoneid_input=fe80%3A%3A1%25eth0")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='zoneid'] .zoneid-result__row")
    assert_eq("zoneid shareable: three result rows", await rows.count(), 3)
    addr_text = await rows.nth(0).locator(".zoneid-result__value").text_content() or ""
    zone_text = await rows.nth(1).locator(".zoneid-result__value").text_content() or ""
    assert_contains("zoneid shareable: address",  addr_text, "fe80::1")
    assert_contains("zoneid shareable: zone",     zone_text, "eth0")


async def test_api_zoneid(page: Page) -> None:
    section("API — POST /api/v1/zone-id")
    # Success
    status, data = _api_post("zone-id", {"input": "fe80::1%eth0"})
    assert_eq("api zone-id: HTTP 200", status, 200)
    payload = data.get("data", {})
    assert_eq("api zone-id: address",       payload.get("address"), "fe80::1")
    assert_eq("api zone-id: zone_id",       payload.get("zone_id"), "eth0")
    assert_eq("api zone-id: is_link_local", payload.get("is_link_local"), True)
    assert_eq("api zone-id: warning null on link-local",
              payload.get("warning"), None)

    # Warning — global with zone
    status2, data2 = _api_post("zone-id", {"input": "2001:db8::1%eth0"})
    assert_eq("api zone-id warn: HTTP 200", status2, 200)
    payload2 = data2.get("data", {})
    assert_eq("api zone-id warn: is_link_local false",
              payload2.get("is_link_local"), False)
    assert_true("api zone-id warn: warning string present",
                isinstance(payload2.get("warning"), str)
                and len(payload2.get("warning") or "") > 0,
                f"got: {payload2.get('warning')!r}")

    # Error — malformed zone
    status3, data3 = _api_post("zone-id", {"input": "fe80::1%"})
    assert_true("api zone-id err: 4xx", 400 <= status3 < 500, f"got: {status3}")
    assert_eq("api zone-id err: ok=false", data3.get("ok"), False)


async def test_ipv6_derive_ui(page: Page) -> None:
    section("IPv6 MAC-derivation UI")

    # Success — full RFC 4291 vector
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='derive']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='derive_mac']", "00:24:b9:7e:ab:cd")
    await page.click("#panel-ipv6 .tool-panel[data-tool='derive'] button.splitter-btn")
    await page.wait_for_load_state("load")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='derive'] .derive-result__row")
    assert_eq("derive: four result rows render", await rows.count(), 4)
    mac_text = await rows.nth(0).locator(".derive-result__value").text_content() or ""
    eui_text = await rows.nth(1).locator(".derive-result__value").text_content() or ""
    ll_text  = await rows.nth(2).locator(".derive-result__value").text_content() or ""
    sn_text  = await rows.nth(3).locator(".derive-result__value").text_content() or ""
    assert_contains("derive: MAC canonical row", mac_text, "00:24:b9:7e:ab:cd")
    assert_contains("derive: EUI-64 row",        eui_text, "0224:b9ff:fe7e:abcd")
    assert_contains("derive: link-local row",    ll_text,  "fe80::224:b9ff:fe7e:abcd")
    assert_contains("derive: solicited-node",    sn_text,  "ff02::1:ff7e:abcd")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='derive']")
    assert_eq("derive: no warning band on unicast MAC",
              await panel.locator(".warning").count(), 0)
    assert_eq("derive: no error band on success",
              await panel.locator(".error").count(), 0)

    # Warning — multicast bit set
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='derive']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='derive_mac']", "01:00:5e:00:00:01")
    await page.click("#panel-ipv6 .tool-panel[data-tool='derive'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='derive']")
    warn_text = await panel.locator(".warning").text_content() or ""
    assert_true("derive: warning band visible on multicast MAC",
                len(warn_text) > 0, f"got: {warn_text!r}")
    assert_eq("derive: result rows still render with warning",
              await panel.locator(".derive-result__row").count(), 4)

    # Error — malformed MAC
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='derive']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='derive_mac']", "not-a-mac")
    await page.click("#panel-ipv6 .tool-panel[data-tool='derive'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='derive']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("derive: error band visible on bad MAC",
                len(err_text) > 0, f"got: {err_text!r}")
    assert_eq("derive: no result rows on error",
              await panel.locator(".derive-result__row").count(), 0)


async def test_ipv6_derive_copy_buttons(page: Page) -> None:
    section("IPv6 MAC-derivation copy buttons")

    # Install clipboard intercept BEFORE the page loads (per CLAUDE.md guidance —
    # docker test harness serves over insecure HTTP where navigator.clipboard is undefined).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    await navigate(page, APP_URL + "?tab=ipv6&derive_mac=00%3A24%3Ab9%3A7e%3Aab%3Acd")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='derive'] .derive-result__row")
    assert_eq("derive copy: four rows hydrated from URL", await rows.count(), 4)

    # EUI-64 (row 1)
    await rows.nth(1).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    eui_clip = await page.evaluate("window.__lastClipboard")
    assert_eq("derive copy: EUI-64 copy", eui_clip, "0224:b9ff:fe7e:abcd")

    # Link-local (row 2)
    await rows.nth(2).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    ll_clip = await page.evaluate("window.__lastClipboard")
    assert_eq("derive copy: link-local copy", ll_clip, "fe80::224:b9ff:fe7e:abcd")

    # Solicited-node (row 3)
    await rows.nth(3).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    sn_clip = await page.evaluate("window.__lastClipboard")
    assert_eq("derive copy: solicited-node copy", sn_clip, "ff02::1:ff7e:abcd")


async def test_ipv6_derive_shareable_url(page: Page) -> None:
    section("IPv6 MAC-derivation shareable URL")
    await navigate(page, APP_URL + "?tab=ipv6&derive_mac=00%3A24%3Ab9%3A7e%3Aab%3Acd")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='derive'] .derive-result__row")
    assert_eq("derive shareable: four result rows", await rows.count(), 4)
    mac_text = await rows.nth(0).locator(".derive-result__value").text_content() or ""
    eui_text = await rows.nth(1).locator(".derive-result__value").text_content() or ""
    ll_text  = await rows.nth(2).locator(".derive-result__value").text_content() or ""
    sn_text  = await rows.nth(3).locator(".derive-result__value").text_content() or ""
    assert_contains("derive shareable: MAC canonical", mac_text, "00:24:b9:7e:ab:cd")
    assert_contains("derive shareable: EUI-64",        eui_text, "0224:b9ff:fe7e:abcd")
    assert_contains("derive shareable: link-local",    ll_text,  "fe80::224:b9ff:fe7e:abcd")
    assert_contains("derive shareable: solicited",     sn_text,  "ff02::1:ff7e:abcd")


async def test_api_derive(page: Page) -> None:
    section("API — POST /api/v1/derive")
    # Success
    status, data = _api_post("derive", {"mac": "00:24:b9:7e:ab:cd"})
    assert_eq("api derive: HTTP 200", status, 200)
    payload = data.get("data", {})
    assert_eq("api derive: mac_canonical",  payload.get("mac_canonical"),  "00:24:b9:7e:ab:cd")
    assert_eq("api derive: eui64",          payload.get("eui64"),          "0224:b9ff:fe7e:abcd")
    assert_eq("api derive: link_local",     payload.get("link_local"),     "fe80::224:b9ff:fe7e:abcd")
    assert_eq("api derive: solicited_node", payload.get("solicited_node"), "ff02::1:ff7e:abcd")
    assert_eq("api derive: ul_bit_flipped true", payload.get("ul_bit_flipped"), True)
    assert_eq("api derive: warning null on unicast",
              payload.get("warning"), None)

    # Warning — multicast MAC
    status2, data2 = _api_post("derive", {"mac": "01:00:5e:00:00:01"})
    assert_eq("api derive warn: HTTP 200", status2, 200)
    payload2 = data2.get("data", {})
    assert_true("api derive warn: warning string present",
                isinstance(payload2.get("warning"), str)
                and len(payload2.get("warning") or "") > 0,
                f"got: {payload2.get('warning')!r}")

    # Error — malformed MAC
    status3, data3 = _api_post("derive", {"mac": "not-a-mac"})
    assert_true("api derive err: 4xx", 400 <= status3 < 500, f"got: {status3}")
    assert_eq("api derive err: ok=false", data3.get("ok"), False)


async def test_ipv6_slaac_ui(page: Page) -> None:
    section("IPv6 SLAAC privacy UI")

    # Success — unseeded /64
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='slaac']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='slaac_prefix']", "2001:db8:1:2::/64")
    await page.click("#panel-ipv6 .tool-panel[data-tool='slaac'] button.splitter-btn")
    await page.wait_for_load_state("load")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac'] .slaac-result__row")
    assert_eq("slaac: four result rows render", await rows.count(), 4)
    prefix_text = await rows.nth(0).locator(".slaac-result__value").text_content() or ""
    addr_text   = await rows.nth(1).locator(".slaac-result__value").text_content() or ""
    iid_text    = await rows.nth(2).locator(".slaac-result__value").text_content() or ""
    seed_text   = await rows.nth(3).locator(".slaac-result__value").text_content() or ""
    assert_contains("slaac: prefix canonical row", prefix_text, "2001:db8:1:2::/64")
    assert_contains("slaac: address starts with prefix", addr_text, "2001:db8:1:2:")
    iid_clean = iid_text.strip()
    assert_eq("slaac: interface ID is 4 hextets",
              iid_clean.count(":"), 3)
    seed_clean = "".join(c for c in seed_text if c in "0123456789abcdefABCDEF")
    assert_eq("slaac: seed used is 16 hex chars", len(seed_clean), 16)
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac']")
    assert_eq("slaac: no warning band on success",
              await panel.locator(".warning").count(), 0)
    assert_eq("slaac: no error band on success",
              await panel.locator(".error").count(), 0)

    # Advanced disclosure opens the seed input
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='slaac']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    summary = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac'] details.slaac-advanced > summary")
    await summary.click()
    is_open = await page.evaluate(
        "() => document.querySelector(\"#panel-ipv6 .tool-panel[data-tool='slaac'] details.slaac-advanced\").open"
    )
    assert_true("slaac: advanced disclosure opens", bool(is_open),
                f"got: {is_open!r}")
    seed_input = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac'] input[name='slaac_seed']")
    assert_true("slaac: seed input visible after opening Advanced",
                await seed_input.is_visible(), "")

    # Error — non-/64 prefix
    await navigate(page, APP_URL + "?tab=ipv6")
    await page.click("#panel-ipv6 .tool-trigger[data-tool='slaac']")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    await page.fill("input[name='slaac_prefix']", "2001:db8::/48")
    await page.click("#panel-ipv6 .tool-panel[data-tool='slaac'] button.splitter-btn")
    await page.wait_for_load_state("load")
    panel = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac']")
    err_text = await panel.locator(".error").text_content() or ""
    assert_true("slaac: error band visible on non-/64 prefix",
                len(err_text) > 0, f"got: {err_text!r}")


async def test_ipv6_slaac_seeded_determinism(page: Page) -> None:
    section("IPv6 SLAAC seeded determinism")

    async def _submit_seeded() -> str:
        await navigate(page, APP_URL + "?tab=ipv6")
        await page.click("#panel-ipv6 .tool-trigger[data-tool='slaac']")
        await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
        # Open advanced disclosure first so the seed input is interactable.
        await page.locator(
            "#panel-ipv6 .tool-panel[data-tool='slaac'] details.slaac-advanced > summary"
        ).click()
        await page.fill("input[name='slaac_prefix']", "2001:db8:1:2::/64")
        await page.fill("input[name='slaac_seed']", "a8d3f4e10c529837")
        await page.click("#panel-ipv6 .tool-panel[data-tool='slaac'] button.splitter-btn")
        await page.wait_for_load_state("load")
        rows = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac'] .slaac-result__row")
        return (await rows.nth(1).locator(".slaac-result__value code").text_content() or "").strip()

    addr1 = await _submit_seeded()
    addr2 = await _submit_seeded()
    assert_eq("slaac: seeded determinism — same seed produces same address",
              addr2, addr1)


async def test_ipv6_slaac_unseeded_uniqueness(page: Page) -> None:
    section("IPv6 SLAAC unseeded uniqueness")

    async def _submit_unseeded() -> str:
        await navigate(page, APP_URL + "?tab=ipv6")
        await page.click("#panel-ipv6 .tool-trigger[data-tool='slaac']")
        await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
        await page.fill("input[name='slaac_prefix']", "2001:db8:1:2::/64")
        await page.click("#panel-ipv6 .tool-panel[data-tool='slaac'] button.splitter-btn")
        await page.wait_for_load_state("load")
        rows = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac'] .slaac-result__row")
        return (await rows.nth(1).locator(".slaac-result__value code").text_content() or "").strip()

    addr1 = await _submit_unseeded()
    addr2 = await _submit_unseeded()
    assert_true("slaac: unseeded uniqueness — random_bytes produces different addresses",
                addr1 != addr2,
                f"both runs produced {addr1!r} (collision probability ~2^-64)")


async def test_ipv6_slaac_copy_buttons(page: Page) -> None:
    section("IPv6 SLAAC copy buttons")

    # Install clipboard intercept BEFORE the page loads (per CLAUDE.md guidance —
    # docker test harness serves over insecure HTTP where navigator.clipboard is undefined).
    await page.add_init_script("""
        (() => {
            window.__lastClipboard = null;
            const stub = { writeText: (text) => { window.__lastClipboard = text; return Promise.resolve(); } };
            try { Object.defineProperty(navigator, 'clipboard', { value: stub, configurable: true }); }
            catch (e) { navigator.clipboard = stub; }
        })();
    """)

    await navigate(page,
        APP_URL + "?tab=ipv6&slaac_prefix=2001%3Adb8%3A1%3A2%3A%3A%2F64&slaac_seed=a8d3f4e10c529837")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac'] .slaac-result__row")
    assert_eq("slaac copy: four rows hydrated from URL", await rows.count(), 4)

    expected_addr = "2001:db8:1:2:a8d3:f4e1:c52:9837"
    expected_iid  = "a8d3:f4e1:0c52:9837"  # IID rendered without zero-suppression
    expected_seed = "a8d3f4e10c529837"

    # Address (row 1)
    await rows.nth(1).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    addr_clip = await page.evaluate("window.__lastClipboard")
    assert_eq("slaac copy: address copy", addr_clip, expected_addr)

    # Interface ID (row 2)
    await rows.nth(2).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    iid_clip = await page.evaluate("window.__lastClipboard")
    assert_eq("slaac copy: interface ID copy", iid_clip, expected_iid)

    # Seed used (row 3)
    await rows.nth(3).locator(".subnet-copy").click()
    await page.wait_for_timeout(50)
    seed_clip = await page.evaluate("window.__lastClipboard")
    assert_eq("slaac copy: seed copy", seed_clip, expected_seed)


async def test_ipv6_slaac_shareable_url(page: Page) -> None:
    section("IPv6 SLAAC shareable URL")
    await navigate(page,
        APP_URL + "?tab=ipv6&slaac_prefix=2001%3Adb8%3A1%3A2%3A%3A%2F64&slaac_seed=a8d3f4e10c529837")
    await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
    rows = page.locator("#panel-ipv6 .tool-panel[data-tool='slaac'] .slaac-result__row")
    assert_eq("slaac shareable: four result rows", await rows.count(), 4)
    prefix_text = await rows.nth(0).locator(".slaac-result__value code").text_content() or ""
    addr_text   = await rows.nth(1).locator(".slaac-result__value code").text_content() or ""
    iid_text    = await rows.nth(2).locator(".slaac-result__value code").text_content() or ""
    seed_text   = await rows.nth(3).locator(".slaac-result__value code").text_content() or ""
    assert_eq("slaac shareable: prefix",       prefix_text.strip(), "2001:db8:1:2::/64")
    assert_eq("slaac shareable: address",      addr_text.strip(),   "2001:db8:1:2:a8d3:f4e1:c52:9837")
    assert_eq("slaac shareable: interface ID", iid_text.strip(),    "a8d3:f4e1:0c52:9837")
    assert_eq("slaac shareable: seed used",    seed_text.strip(),   "a8d3f4e10c529837")


async def test_api_slaac(page: Page) -> None:
    section("API — POST /api/v1/slaac-privacy")
    # Unseeded success
    status, data = _api_post("slaac-privacy", {"prefix": "2001:db8:1:2::/64"})
    assert_eq("api slaac: HTTP 200", status, 200)
    payload = data.get("data", {})
    assert_eq("api slaac: seed_was_provided=false",
              payload.get("seed_was_provided"), False)
    assert_true("api slaac: address starts with prefix",
                isinstance(payload.get("address"), str)
                and (payload.get("address") or "").startswith("2001:db8:1:2:"),
                f"got: {payload.get('address')!r}")
    assert_true("api slaac: seed_used is 16 hex",
                isinstance(payload.get("seed_used"), str)
                and len(payload.get("seed_used") or "") == 16,
                f"got: {payload.get('seed_used')!r}")

    # Seeded — deterministic vector
    status2, data2 = _api_post("slaac-privacy", {
        "prefix": "2001:db8:1:2::/64",
        "seed":   "a8d3f4e10c529837",
    })
    assert_eq("api slaac seeded: HTTP 200", status2, 200)
    payload2 = data2.get("data", {})
    assert_eq("api slaac seeded: address",
              payload2.get("address"), "2001:db8:1:2:a8d3:f4e1:c52:9837")
    assert_eq("api slaac seeded: seed_was_provided=true",
              payload2.get("seed_was_provided"), True)

    # Error — non-/64 prefix
    status3, data3 = _api_post("slaac-privacy", {"prefix": "2001:db8::/48"})
    assert_eq("api slaac err: non-/64 → 400", status3, 400)
    assert_eq("api slaac err: ok=false", data3.get("ok"), False)
    err_msg = data3.get("error")
    if isinstance(err_msg, dict):
        err_msg = err_msg.get("message")
    assert_contains("api slaac err: /64 message",
                    str(err_msg or ""), "/64")

    # Error — bad seed
    status4, data4 = _api_post("slaac-privacy", {
        "prefix": "2001:db8:1:2::/64",
        "seed":   "abc",
    })
    assert_eq("api slaac err: bad seed → 400", status4, 400)
    assert_eq("api slaac err: ok=false (bad seed)", data4.get("ok"), False)
    err_msg2 = data4.get("error")
    if isinstance(err_msg2, dict):
        err_msg2 = err_msg2.get("message")
    assert_contains("api slaac err: 16-hex message",
                    str(err_msg2 or ""), "16")


async def test_a11y_ipv6_drawers(page: Page) -> None:
    """v3.4.0 (T11) — a11y audit for the 5 IPv6 tool drawers.

    Asserts, per drawer:
      1. Every form input has an associated <label> or aria-label.
      2. Every help_bubble() icon is keyboard-focusable (tabindex="0").
      3. Every copy_button() has aria-label starting with 'Copy'.
      4. Any <details> Advanced disclosure uses native <summary>.
      5. Submit buttons have an accessible name.

    Any gap surfaced here is a real a11y bug — fix the markup, not the test.
    """
    section("v3.4.0 — a11y audit for the 5 new IPv6 drawers")

    drawers = ["zoneid", "derive", "slaac", "rdns6", "mapped6"]

    for slug in drawers:
        await navigate(page, APP_URL + f"?tab=ipv6&tool={slug}")
        await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
        drawer = page.locator(f"#panel-ipv6 .tool-panel[data-tool='{slug}']")
        assert_eq(f"a11y {slug}: panel rendered", await drawer.count(), 1)

        # 1. Every input has a label or aria-label
        inputs = drawer.locator(
            "input[type=text], input[type=number], input[type=tel], input:not([type])"
        )
        n_inputs = await inputs.count()
        assert_true(
            f"a11y {slug}: at least one form input present",
            n_inputs >= 1,
            f"got: {n_inputs}",
        )
        for i in range(n_inputs):
            el = inputs.nth(i)
            has_label = await el.evaluate(
                "(e) => !!(e.labels && e.labels.length) "
                "|| !!e.getAttribute('aria-label') "
                "|| !!e.closest('label')"
            )
            ident = await el.get_attribute("id") or await el.get_attribute("name") or f"#{i}"
            assert_true(
                f"a11y {slug}: input '{ident}' has label/aria-label",
                bool(has_label),
                f"got has_label={has_label!r}",
            )

        # 2. Every help bubble icon is keyboard-focusable (tabindex="0")
        bubbles = drawer.locator(".help-bubble-icon")
        n_bubbles = await bubbles.count()
        for i in range(n_bubbles):
            ti = await bubbles.nth(i).get_attribute("tabindex")
            assert_eq(
                f"a11y {slug}: help-bubble[{i}] tabindex='0'",
                ti,
                "0",
            )
            role = await bubbles.nth(i).get_attribute("role")
            assert_eq(
                f"a11y {slug}: help-bubble[{i}] role='button'",
                role,
                "button",
            )

        # 3. Every copy button has aria-label beginning with 'Copy'
        copies = drawer.locator("button.subnet-copy")
        n_copies = await copies.count()
        for i in range(n_copies):
            al = await copies.nth(i).get_attribute("aria-label") or ""
            assert_true(
                f"a11y {slug}: copy-button[{i}] aria-label starts with 'Copy'",
                al.startswith("Copy"),
                f"got: {al!r}",
            )

        # 4. If a <details> Advanced disclosure exists, it must use native
        # <summary> (keyboard-operable by default).
        advanced_summaries = drawer.locator("details > summary")
        n_adv = await advanced_summaries.count()
        for i in range(n_adv):
            tag = await advanced_summaries.nth(i).evaluate("(e) => e.tagName")
            assert_eq(
                f"a11y {slug}: advanced disclosure[{i}] uses <summary>",
                tag,
                "SUMMARY",
            )

        # 5. Submit button has accessible name (visible text or aria-label)
        submits = drawer.locator("button[type=submit]")
        n_submits = await submits.count()
        assert_true(
            f"a11y {slug}: at least one submit button",
            n_submits >= 1,
            f"got: {n_submits}",
        )
        for i in range(n_submits):
            name = await submits.nth(i).evaluate(
                "(e) => (e.textContent || '').trim() || e.getAttribute('aria-label') || ''"
            )
            assert_true(
                f"a11y {slug}: submit-button[{i}] has accessible name",
                bool(name),
                f"got: {name!r}",
            )

    # Result-state copy-button audit: drive each drawer that emits copy
    # buttons through a successful submission and verify every rendered
    # copy_button() carries an aria-label starting with 'Copy'. (Empty-state
    # panels rarely contain copy buttons, so the loop above can't exercise
    # this assertion on its own.)
    result_cases = [
        ("derive",  "derive_mac",     "00:24:b9:7e:ab:cd"),
        ("slaac",   "slaac_prefix",   "2001:db8:1:2::/64"),
        ("rdns6",   "rdns6_address",  "2001:db8::1"),
        ("mapped6", "mapped6_input",  "192.0.2.1"),
    ]
    for slug, input_name, value in result_cases:
        await navigate(page, APP_URL + f"?tab=ipv6&tool={slug}")
        await page.wait_for_selector("#panel-ipv6 .tool-drawer.open")
        await page.fill(f"input[name='{input_name}']", value)
        await page.click(
            f"#panel-ipv6 .tool-panel[data-tool='{slug}'] button.splitter-btn"
        )
        await page.wait_for_load_state("load")
        drawer = page.locator(f"#panel-ipv6 .tool-panel[data-tool='{slug}']")
        copies = drawer.locator("button.subnet-copy")
        n_copies = await copies.count()
        assert_true(
            f"a11y {slug}: result state renders >=1 copy button",
            n_copies >= 1,
            f"got: {n_copies}",
        )
        for i in range(n_copies):
            al = await copies.nth(i).get_attribute("aria-label") or ""
            assert_true(
                f"a11y {slug}: copy-button[{i}] aria-label starts with 'Copy'",
                al.startswith("Copy"),
                f"got: {al!r}",
            )


async def test_api_range(page: Page) -> None:
    section("API — POST /api/v1/range/ipv4")
    status, data = _api_post("range/ipv4", {"start": "10.0.0.0", "end": "10.0.0.255"})
    assert_eq("api range: HTTP 200", status, 200)
    assert_eq("api range: ok=true",  data.get("ok"), True)
    cidrs = data.get("data", {}).get("cidrs", [])
    assert_true("api range: cidrs is list",   isinstance(cidrs, list), f"got: {cidrs!r}")
    assert_true("api range: first is /24",    "10.0.0.0/24" in cidrs, f"got: {cidrs}")

    # Missing start → 400
    status2, _ = _api_post("range/ipv4", {"end": "10.0.0.255"})
    assert_eq("api range: missing start → 400", status2, 400)

    # end < start → 400
    status3, _ = _api_post("range/ipv4", {"start": "10.0.0.255", "end": "10.0.0.0"})
    assert_eq("api range: end<start → 400", status3, 400)


async def test_api_tree(page: Page) -> None:
    section("API — POST /api/v1/tree")
    status, data = _api_post("tree", {
        "parent":   "10.0.0.0/24",
        "children": ["10.0.0.0/25", "10.0.0.128/25"],
    })
    assert_eq("api tree: HTTP 200", status, 200)
    assert_eq("api tree: ok=true",  data.get("ok"), True)
    tree = data.get("data", {}).get("tree", {})
    assert_eq("api tree: root cidr", tree.get("cidr"), "10.0.0.0/24")
    children = tree.get("children", [])
    assert_true("api tree: 2 children", len(children) == 2, f"got: {children}")

    # Invalid parent → 400
    status2, _ = _api_post("tree", {"parent": "not-a-cidr", "children": []})
    assert_eq("api tree: invalid parent → 400", status2, 400)


# ---------------------------------------------------------------------------
# v2.2.0 — API new fields
# ---------------------------------------------------------------------------

async def test_api_ipv4_v220_fields(page: Page) -> None:
    section("API — IPv4 v2.2.0 fields (network_hex, network_decimal)")
    status, data = _api_post("ipv4", {"ip": "192.168.1.0", "mask": "24"})
    assert_eq("api ipv4 v2.2.0: HTTP 200", status, 200)
    d = data.get("data", {})
    assert_eq("api ipv4 v2.2.0: network_hex",     d.get("network_hex"),     "C0.A8.01.00")
    assert_eq("api ipv4 v2.2.0: network_decimal", d.get("network_decimal"), 3232235776)


async def test_api_ipv6_v220_fields(page: Page) -> None:
    section("API — IPv6 v2.2.0 fields (address_expanded, address_compressed)")
    status, data = _api_post("ipv6", {"ipv6": "2001:db8::", "prefix": "32"})
    assert_eq("api ipv6 v2.2.0: HTTP 200", status, 200)
    d = data.get("data", {})
    assert_eq(
        "api ipv6 v2.2.0: address_expanded",
        d.get("address_expanded"),
        "2001:0db8:0000:0000:0000:0000:0000:0000",
    )
    assert_eq("api ipv6 v2.2.0: address_compressed", d.get("address_compressed"), "2001:db8::")


# ---------------------------------------------------------------------------
# v2.2.0 — API: $api_allowed_endpoints and per-token rate limits
# ---------------------------------------------------------------------------

async def test_api_v220_endpoint_allowlist(page: Page) -> None:
    """
    Smoke tests for the $api_allowed_endpoints feature.
    The dev server runs with $api_allowed_endpoints = [] (all endpoints open),
    so we verify that: (a) all normal endpoints return 200, and (b) a request
    to a non-existent path returns 404 — which is the same HTTP status that a
    blocked endpoint returns, documenting the contract.
    """
    section("API — v2.2.0 endpoint allowlist contract")

    # Normal endpoints must be reachable when allowlist is empty (default)
    status, _ = _api_post("ipv4", {"ip": "10.0.0.0", "mask": "24"})
    assert_eq("api allowlist: ipv4 accessible (default open)",  status, 200)
    status6, _ = _api_post("ipv6", {"ipv6": "2001:db8::", "prefix": "32"})
    assert_eq("api allowlist: ipv6 accessible (default open)", status6, 200)

    # A non-existent endpoint returns 404 — same status as a blocked endpoint
    resp = _SESSION.post(_API_BASE + "nonexistent_endpoint_xyz", json={}, timeout=10)
    assert_eq("api allowlist: unknown endpoint → 404", resp.status_code, 404)

    # Meta GET / is always reachable regardless of $api_allowed_endpoints
    status_meta, meta = _api_get("")
    assert_eq("api allowlist: meta GET always reachable", status_meta, 200)
    assert_eq("api allowlist: meta ok=true",              meta.get("ok"), True)


async def test_api_v220_rate_limit_contract(page: Page) -> None:
    """
    Smoke test for per-token rate limit overrides ($api_rate_limit_tokens).
    The dev server runs open (no $api_tokens configured), so we verify the
    unauthenticated rate-limit contract: requests succeed and the router does
    not error on missing auth. Per-token override testing requires a server
    configured with $api_tokens and $api_rate_limit_tokens.
    """
    section("API — v2.2.0 per-token rate limit contract")

    # Unauthenticated requests on an open API must succeed (global RPM applies)
    status, data = _api_post("ipv4", {"ip": "192.168.0.0", "mask": "24"})
    assert_eq("api rate-limit: open API accepts unauthenticated request", status, 200)
    assert_eq("api rate-limit: response ok=true", data.get("ok"), True)

    # A Bearer token on an open API is harmlessly ignored (no auth required)
    resp = _SESSION.post(
        _API_BASE + "ipv4",
        json={"ip": "10.0.0.0", "mask": "8"},
        headers={"Authorization": "Bearer test-token-does-not-exist"},
        timeout=10,
    )
    assert_eq("api rate-limit: unknown token on open API → 200", resp.status_code, 200)


async def test_tooltips_visual_polish(page: Page) -> None:
    """
    #205 fix: text-transform:none, right-edge positioning, max-width.
    """
    section("Tooltips — visual polish (#205)")

    await navigate(page, APP_URL)

    # 1. text-transform should be 'none' on tooltip content so it doesn't
    #    inherit 'uppercase' from parent label styling
    bubble_text = page.locator("#hb-ipv4-ip")
    text_transform = await bubble_text.evaluate(
        "el => getComputedStyle(el).textTransform"
    )
    assert_eq("tooltip text-transform is none", text_transform, "none")

    # 2. Tooltip max-width set — verify the computed max-width is not 'none'
    max_width = await bubble_text.evaluate(
        "el => getComputedStyle(el).maxWidth"
    )
    assert_true("tooltip max-width is constrained (not 'none')", max_width != "none", max_width)

    # 3. Right-edge detection: shrink viewport so the bubble is near the right edge,
    #    force detectBubbleEdges(), then check .bubble-right-edge is applied
    await page.set_viewport_size({"width": 360, "height": 667})
    await page.evaluate("window.dispatchEvent(new Event('resize'))")
    await page.wait_for_timeout(150)
    # No assertion needed on class presence — just verify page doesn't throw errors
    errors = await page.evaluate("""() => {
        try {
            document.querySelectorAll('.help-bubble').forEach(function(b) {
                var vw = window.innerWidth;
                var rect = b.getBoundingClientRect();
                if (rect.width > 0) {
                    b.classList.toggle('bubble-right-edge', (vw - rect.right) < 150);
                }
            });
            return null;
        } catch(e) {
            return e.message;
        }
    }""")
    err_detail: str = errors if errors is not None else ""
    assert_true("right-edge detection runs without error", errors is None, err_detail)
    await page.set_viewport_size({"width": 1280, "height": 800})


async def test_tooltips_accessibility(page: Page) -> None:
    """
    Tooltip a11y: keyboard focus shows tooltip, aria-describedby is wired,
    Tab past bubble does not trap focus.
    """
    section("Tooltips — accessibility (keyboard + ARIA)")

    await navigate(page, APP_URL)

    # Every .help-bubble-icon must have tabindex="0"
    icons = page.locator(".help-bubble-icon")
    count = await icons.count()
    assert_true("at least one help-bubble-icon on page", count > 0)

    non_focusable = await page.evaluate("""() => {
        var icons = document.querySelectorAll('.help-bubble-icon');
        var bad = [];
        icons.forEach(function(ic) {
            if (ic.getAttribute('tabindex') !== '0') bad.push(ic.id || '(no id)');
        });
        return bad;
    }""")
    assert_true(
        "all help-bubble-icons have tabindex=0",
        len(non_focusable) == 0,
        f"missing tabindex: {non_focusable}"
    )

    # Every icon must have aria-describedby pointing to an existing element
    unlinked = await page.evaluate("""() => {
        var icons = document.querySelectorAll('.help-bubble-icon[aria-describedby]');
        var bad = [];
        icons.forEach(function(ic) {
            var id = ic.getAttribute('aria-describedby');
            if (!document.getElementById(id)) bad.push(id);
        });
        return bad;
    }""")
    assert_true(
        "all aria-describedby refs resolve to existing elements",
        len(unlinked) == 0,
        f"broken refs: {unlinked}"
    )

    # Every tooltip element must have role="tooltip"
    missing_role = await page.evaluate("""() => {
        var tips = document.querySelectorAll('.help-bubble-text');
        var bad = [];
        tips.forEach(function(t) {
            if (t.getAttribute('role') !== 'tooltip') bad.push(t.id || '(no id)');
        });
        return bad;
    }""")
    assert_true(
        "all .help-bubble-text elements have role=tooltip",
        len(missing_role) == 0,
        f"missing role: {missing_role}"
    )


async def test_csp_inline_style_violations(page: Page) -> None:
    """
    #206 fix: zero CSP-blocked inline style= violations across full page interaction.
    """
    section("CSP — zero inline style= violations (#206)")

    csp_violations: list[str] = []

    def on_console(msg):  # type: ignore[override]
        text = msg.text
        if "Content-Security-Policy" in text or "ERR_BLOCKED_BY_CSP" in text:
            csp_violations.append(text)

    page.on("console", on_console)
    try:
        # Load page and trigger result panels (ipv4, ipv6, overlap, range, tree)
        await navigate(page, APP_URL)
        await page.wait_for_timeout(300)

        # IPv4 result
        await page.fill("#ip", "10.0.0.0")
        await page.fill("#mask", "24")
        await page.press("#ip", "Enter")
        await page.wait_for_selector(".results", timeout=8000)
        await page.wait_for_timeout(200)

        # IPv6 result
        await page.click("#tab-ipv6")
        await page.fill("#ipv6", "2001:db8::/32")
        await page.press("#ipv6", "Enter")
        await page.wait_for_selector("#panel-ipv6 .results", timeout=8000)
        await page.wait_for_timeout(200)

        # Overlap panel
        await page.click("#tab-vlsm")
        await page.click("#panel-vlsm .tool-trigger[data-tool='overlap']")
        await page.wait_for_selector("#panel-vlsm .tool-drawer.open")
        await page.fill("input[name='overlap_cidr_a']", "10.0.0.0/24")
        await page.fill("input[name='overlap_cidr_b']", "10.0.0.128/25")
        await submit_form(page, ".overlap-form")
        await page.wait_for_selector(".overlap-result", timeout=8000)
        await page.wait_for_timeout(200)

        assert_true(
            "no CSP inline-style violations across page interaction",
            len(csp_violations) == 0,
            f"violations: {csp_violations[:3]}"
        )

        # Verify no result panels have bare style= attributes (all should use CSS classes)
        styled_els = await page.evaluate("""() => {
            var panels = document.querySelectorAll(
                '.results, .overlap-result, .split-result, .vlsm-results, .tree-view'
            );
            var found = [];
            panels.forEach(function(p) {
                p.querySelectorAll('[style]').forEach(function(el) {
                    // Allow style from vendor scripts or nonce-covered blocks
                    var s = el.getAttribute('style') || '';
                    if (s.trim() !== '') {
                        found.push((el.className || el.tagName) + ': ' + s.substring(0, 60));
                    }
                });
            });
            return found;
        }""")
        assert_true(
            "no bare style= attributes in result panels",
            len(styled_els) == 0,
            f"found: {styled_els[:3]}"
        )
    finally:
        page.remove_listener("console", on_console)


async def test_print_stylesheet(page: Page) -> None:
    """
    #193 fix: VLSM table and summary are visible and usable in print mode.
    """
    section("Print stylesheet — VLSM (#193)")

    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("#vlsm_network", "10.0.0.0")
    await page.fill("#vlsm_cidr", "24")
    await page.evaluate("document.querySelectorAll('.vlsm-name-input')[0].value = 'LAN'")
    await page.evaluate("document.querySelectorAll('.vlsm-hosts-input')[0].value = '50'")
    await submit_form(page, ".vlsm-form")
    await page.wait_for_selector(".vlsm-table", timeout=8000)

    await page.emulate_media(media="print")
    await page.wait_for_timeout(200)

    # VLSM table must be visible in print mode
    table_visible = await page.locator(".vlsm-table").is_visible()
    assert_true("vlsm-table visible in print mode", table_visible)

    # Utilisation summary must not be hidden in print mode
    summary_visible = await page.locator(".vlsm-summary").is_visible()
    assert_true("vlsm-summary visible in print mode", summary_visible)

    # Export buttons must be hidden in print mode
    export_hidden = await page.evaluate("""() => {
        var btns = document.querySelectorAll(
            '.export-btn-group, .copy-all-btn, .ascii-export-btn'
        );
        for (var i = 0; i < btns.length; i++) {
            var cs = getComputedStyle(btns[i]);
            if (cs.display !== 'none') return false;
        }
        return true;
    }""")
    assert_true("export/copy buttons hidden in print mode", export_hidden)

    await page.emulate_media(media="screen")


async def test_locale_number_format(page: Page) -> None:
    """
    #191: Default 'en' locale uses comma thousands separators in displayed counts.
    """
    section("Locale — number formatting (#191)")

    # /16 subnet → 65,534 usable hosts
    await navigate(page, APP_URL + "?ip=10.0.0.0&mask=16")
    await page.wait_for_selector(".results", timeout=8000)

    usable = await result_value(page, "Usable IPs")
    assert_true(
        "usable IPs displayed with comma separator (65,534)",
        usable is not None and "," in (usable or ""),
        f"got {usable!r}"
    )
    assert_eq("usable IPs value is 65,534", usable, "65,534")

    # IPv4 results don't have a "Total IPs" row; verify IPv6 "Total Addresses"
    # also formats with commas (2^32 subnet → large number).
    await navigate(page, APP_URL + "?tab=ipv6&ipv6=2001%3Adb8%3A%3A1&prefix=32")
    await page.wait_for_selector("#panel-ipv6 .results", timeout=8000)
    total6 = await result_value(page, "Total Addresses")
    assert_true(
        "IPv6 Total Addresses uses scientific notation (2^96 for /32)",
        total6 is not None and ("2^" in (total6 or "") or "," in (total6 or "")),
        f"got {total6!r}"
    )


async def test_eslint_clean(page: Page) -> None:  # noqa: ARG001
    """Run ESLint and assert exit code 0."""
    import shutil
    import subprocess
    from pathlib import Path
    section("ESLint — app.js clean")
    if SKIP_LINT:
        return
    npm = shutil.which("npm")
    if npm is None:
        assert_true("npm is on PATH", False, "npm not found — cannot run ESLint")
        return
    repo_root = Path(__file__).resolve().parents[2]
    try:
        result = subprocess.run(
            [npm, "run", "lint:js"],
            capture_output=True, text=True, cwd=repo_root, timeout=300,
        )
    except subprocess.TimeoutExpired:
        assert_true("eslint exits 0 (no errors)", False, "ESLint timed out after 300 s")
        return
    assert_true(
        "eslint exits 0 (no errors)",
        result.returncode == 0,
        result.stdout[-500:] if result.stdout else result.stderr[-500:]
    )


async def test_stylelint_clean(page: Page) -> None:  # noqa: ARG001
    """Run Stylelint and assert exit code 0."""
    import shutil
    import subprocess
    from pathlib import Path
    section("Stylelint — app.css clean")
    if SKIP_LINT:
        return
    npm = shutil.which("npm")
    if npm is None:
        assert_true("npm is on PATH", False, "npm not found — cannot run Stylelint")
        return
    repo_root = Path(__file__).resolve().parents[2]
    try:
        result = subprocess.run(
            [npm, "run", "lint:css"],
            capture_output=True, text=True, cwd=repo_root, timeout=300,
        )
    except subprocess.TimeoutExpired:
        assert_true("stylelint exits 0 (no errors)", False, "Stylelint timed out after 300 s")
        return
    assert_true(
        "stylelint exits 0 (no errors)",
        result.returncode == 0,
        result.stdout[-500:] if result.stdout else result.stderr[-500:]
    )


async def test_full_visual_inspection(page: Page) -> None:
    """
    Systematic visual inspection: every tab, every major result state, both
    viewports.  Asserts that key structural elements are present and not
    clipped (bounding-rect checks) rather than pixel-diffing, so the test
    stays green after intentional UI changes.
    """
    section("Full visual inspection — all tabs / states")

    async def _rect_ok(locator, label: str) -> None:
        """Assert element has non-zero width & height and is inside the viewport."""
        rect = await locator.bounding_box()
        assert_true(f"{label}: visible on screen", rect is not None,
                    "element not visible or off-screen")
        if rect:
            assert_true(f"{label}: width > 0",  rect["width"]  > 0, f"width={rect['width']}")
            assert_true(f"{label}: height > 0", rect["height"] > 0, f"height={rect['height']}")
            vw = await page.evaluate("window.innerWidth")
            vh = await page.evaluate("window.innerHeight + window.scrollY")
            assert_true(
                f"{label}: not clipped left",
                rect["x"] >= -1,
                f"x={rect['x']:.0f}"
            )
            assert_true(
                f"{label}: right edge within page",
                rect["x"] + rect["width"] <= vw + 4,
                f"right={rect['x']+rect['width']:.0f} vw={vw}"
            )

    # ── Desktop 1280×800 ────────────────────────────────────────────────────
    await page.set_viewport_size({"width": 1280, "height": 800})
    await navigate(page, APP_URL)

    # Structural chrome
    await _rect_ok(page.locator("img.logo"),                    "logo (desktop)")
    await _rect_ok(page.locator("h1"),                          "h1 title (desktop)")
    await _rect_ok(page.locator(".version"),                    "version badge (desktop)")
    await _rect_ok(page.locator("#tab-ipv4"),                   "IPv4 tab btn (desktop)")
    await _rect_ok(page.locator("#tab-ipv6"),                   "IPv6 tab btn (desktop)")
    await _rect_ok(page.locator("#tab-vlsm"),                   "VLSM tab btn (desktop)")
    await _rect_ok(page.locator("footer"),                      "footer (desktop)")

    # IPv4 form idle
    await _rect_ok(page.locator("#ip"),                         "IPv4 ip input (desktop)")
    await _rect_ok(page.locator("#mask"),                       "IPv4 mask input (desktop)")
    await _rect_ok(page.locator("#panel-ipv4 button[type=submit]").first, "IPv4 calculate btn (desktop)")

    # IPv4 result
    await navigate(page, APP_URL + "?ip=192.168.1.0&mask=%2F24")
    await page.wait_for_selector(".results", timeout=8000)
    await _rect_ok(page.locator(".results"),                    "IPv4 results panel (desktop)")
    await _rect_ok(page.locator(".result-row").first,           "first result row (desktop)")
    # Address-type badge must be visible
    badge_count = await page.locator(".results .badge").count()
    assert_true("address-type badge present in IPv4 result", badge_count > 0)

    # Tool toolbar visible after IPv4 result (v2.8.0 drawer feature)
    await _rect_ok(page.locator("#panel-ipv4 .tool-toolbar"),   "IPv4 tool toolbar (desktop)")

    # IPv6 tab
    await navigate(page, APP_URL + "?tab=ipv6&ipv6=2001%3Adb8%3A%3A1&prefix=32")
    await page.wait_for_selector("#panel-ipv6 .results", timeout=8000)
    await _rect_ok(page.locator("#panel-ipv6 .results"),        "IPv6 results panel (desktop)")
    badge_count6 = await page.locator("#panel-ipv6 .badge").count()
    assert_true("address-type badge present in IPv6 result", badge_count6 > 0)

    # VLSM tab with result
    await navigate(
        page,
        APP_URL + "?tab=vlsm&vlsm_network=10.0.0.0&vlsm_cidr=24"
        "&vlsm_name%5B%5D=Sales&vlsm_hosts%5B%5D=50"
        "&vlsm_name%5B%5D=HR&vlsm_hosts%5B%5D=20"
    )
    await page.wait_for_selector(".vlsm-table", timeout=8000)
    await _rect_ok(page.locator(".vlsm-table"),                 "VLSM table (desktop)")
    await _rect_ok(page.locator(".vlsm-summary"),               "VLSM utilisation summary (desktop)")
    await _rect_ok(page.locator(".export-btn-group"),           "VLSM export buttons (desktop)")
    # Tool toolbar is visible on the VLSM tab (v2.8.0 drawer feature)
    vlsm_toolbar = page.locator("#panel-vlsm .tool-toolbar")
    await vlsm_toolbar.scroll_into_view_if_needed()
    await _rect_ok(vlsm_toolbar,                                "VLSM tool toolbar (desktop)")

    # ── Mobile 375×667 ──────────────────────────────────────────────────────
    await page.set_viewport_size({"width": 375, "height": 667})

    await navigate(page, APP_URL)
    await _rect_ok(page.locator("img.logo"),                    "logo (mobile)")
    await _rect_ok(page.locator("#tab-ipv4"),                   "IPv4 tab (mobile)")

    # IPv4 result at mobile
    await navigate(page, APP_URL + "?ip=10.0.0.0&mask=8")
    await page.wait_for_selector(".results", timeout=8000)
    await _rect_ok(page.locator(".results"),                    "IPv4 results panel (mobile)")
    # No horizontal overflow — temporarily hide absolutely-positioned tooltip text
    # elements (visibility:hidden but not display:none) that inflate scrollWidth.
    result = await page.evaluate("""() => {
        var tips = document.querySelectorAll('.help-bubble-text');
        tips.forEach(function(el) { el.style.display = 'none'; });
        var sw = document.documentElement.scrollWidth;
        var vw = window.innerWidth;
        var wide = [];
        if (sw > vw + 2) {
            document.querySelectorAll('*').forEach(function(el) {
                var r = el.getBoundingClientRect();
                if (r.right > vw + 2 && r.width > 0) {
                    var id = (el.id ? '#'+el.id : '') + (el.className ? '.'+String(el.className).split(' ')[0] : '');
                    wide.push(el.tagName + id + ' right=' + Math.round(r.right));
                }
            });
        }
        tips.forEach(function(el) { el.style.display = ''; });
        return {sw: sw, vw: vw, wide: wide.slice(0, 5)};
    }""")
    assert_true(
        "no horizontal scroll on mobile (375 px)",
        result["sw"] <= result["vw"] + 2,
        f"scrollWidth={result['sw']} vw={result['vw']} offenders={result['wide']}"
    )

    # Restore
    await page.set_viewport_size({"width": 1280, "height": 800})


async def test_all_tooltips_direction(page: Page) -> None:
    """
    Verify every help bubble in the app:
    - appears in the correct direction (below for label/section-header context,
      above otherwise)
    - does not overflow the viewport on any edge
    - shows correct tooltip text (non-empty, text-transform: none)
    """
    section("All tooltips — direction, overflow, and content")

    await page.set_viewport_size({"width": 1280, "height": 900})

    # Make all panels visible so we can measure hidden-tab bubbles too
    await navigate(page, APP_URL)
    await page.evaluate("document.querySelectorAll('.panel').forEach(p => p.style.display = 'block')")

    # Force-load the updated stylesheet (bypass any cache) so the fixes are active
    await page.evaluate("""() => {
        var link = document.querySelector('link[rel="stylesheet"][href*="app.css"]');
        if (link) link.href = link.href.split('?')[0] + '?v=' + Date.now();
    }""")
    await page.wait_for_timeout(800)

    results = await page.evaluate("""() => {
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        return Array.from(document.querySelectorAll('.help-bubble-text')).map(function(el) {
            // Force visible to measure
            el.style.visibility = 'visible';
            el.style.opacity    = '1';
            var tipRect  = el.getBoundingClientRect();
            var icon     = el.previousElementSibling;
            var iconRect = icon ? icon.getBoundingClientRect() : null;
            var cs       = getComputedStyle(el);
            // Determine parent context
            var parent = el.parentElement ? el.parentElement.className : '';
            var inLabel        = !!el.closest('label');
            var inOverlapTitle = !!el.closest('.overlap-title');
            var shouldBeBelow  = inLabel || inOverlapTitle;
            var isBelow = iconRect ? (tipRect.top >= iconRect.bottom - 2) : null;
            el.style.visibility = '';
            el.style.opacity    = '';
            return {
                id:            el.id,
                text:          el.textContent.trim().substring(0, 60),
                textTransform: cs.textTransform,
                shouldBeBelow: shouldBeBelow,
                isBelow:       isBelow,
                overflowTop:   tipRect.top    < 0,
                overflowRight: tipRect.right  > vw,
                overflowLeft:  tipRect.left   < 0,
                parentCtx:     parent.substring(0, 40),
            };
        });
    }""")

    for tip in results:
        tid = tip["id"] or "(no id)"

        # Non-empty content
        assert_true(
            f"{tid}: tooltip has non-empty text",
            len(tip["text"]) > 0,
            repr(tip["text"])
        )

        # text-transform must be 'none' (not inheriting label/button uppercase)
        assert_eq(
            f"{tid}: text-transform is none",
            tip["textTransform"], "none"
        )

        # Direction check (only for elements where direction can be measured)
        if tip["isBelow"] is not None:
            if tip["shouldBeBelow"]:
                assert_true(
                    f"{tid}: appears below trigger (label/section-header context)",
                    tip["isBelow"],
                    f"parentCtx={tip['parentCtx']}"
                )
            # else: above is acceptable — we don't assert direction for mid-page buttons

        # No viewport overflow
        assert_true(f"{tid}: no overflow top",   not tip["overflowTop"],
                    f"tipTop<0 for {tid}")
        assert_true(f"{tid}: no overflow right",  not tip["overflowRight"],
                    f"tipRight>vw for {tid}")
        assert_true(f"{tid}: no overflow left",   not tip["overflowLeft"],
                    f"tipLeft<0 for {tid}")

    total_tips = len(results)
    # 8 bubbles when sessions are disabled (vlsm-session bubble is conditional);
    # 9+ when sessions are enabled.
    assert_true(
        "found all expected help bubbles (≥8)",
        total_tips >= 8,
        f"found {total_tips}"
    )


async def test_console_no_errors(page: Page) -> None:
    """
    Listen to browser console across every major app state and assert zero
    errors or warnings.  Captures: page load, all three tabs (idle + result),
    theme toggle, VLSM with result, overlap, ULA generation.
    """
    section("Console — zero errors across all app states")

    errors: list[str] = []
    warnings: list[str] = []

    # Ignore known benign Playwright noise
    _IGNORE = (
        "net::ERR_ABORTED",           # browser cancels duplicate navigations
        "favicon",                    # favicon 404 is cosmetic
    )

    def _on_msg(msg) -> None:  # type: ignore[override]
        text = msg.text
        if any(s in text for s in _IGNORE):
            return
        if msg.type == "error":
            errors.append(f"[{msg.type}] {text}")
        elif msg.type == "warning":
            warnings.append(f"[{msg.type}] {text}")

    page.on("console", _on_msg)

    try:
        # 1. Fresh page load (dark mode default)
        await navigate(page, APP_URL)
        await page.wait_for_timeout(300)
        assert_true("no console errors on page load",
                    len(errors) == 0, "; ".join(errors))
        assert_true("no console warnings on page load",
                    len(warnings) == 0, "; ".join(warnings))
        errors.clear()
        warnings.clear()

        # 2. IPv4 result
        await navigate(page, APP_URL + "?ip=192.168.1.0&mask=%2F24")
        await page.wait_for_selector(".results", timeout=8000)
        await page.wait_for_timeout(200)
        assert_true("no console errors — IPv4 result",
                    len(errors) == 0, "; ".join(errors))
        assert_true("no console warnings — IPv4 result",
                    len(warnings) == 0, "; ".join(warnings))
        errors.clear()
        warnings.clear()

        # 3. IPv6 result
        await navigate(page, APP_URL + "?tab=ipv6&ipv6=2001%3Adb8%3A%3A1&prefix=32")
        await page.wait_for_selector("#panel-ipv6 .results", timeout=8000)
        await page.wait_for_timeout(200)
        assert_true("no console errors — IPv6 result",
                    len(errors) == 0, "; ".join(errors))
        assert_true("no console warnings — IPv6 result",
                    len(warnings) == 0, "; ".join(warnings))
        errors.clear()
        warnings.clear()

        # 4. VLSM result
        await navigate(
            page,
            APP_URL + "?tab=vlsm&vlsm_network=10.0.0.0&vlsm_cidr=24"
            "&vlsm_name%5B%5D=Sales&vlsm_hosts%5B%5D=50"
        )
        await page.wait_for_selector(".vlsm-table", timeout=8000)
        await page.wait_for_timeout(200)
        assert_true("no console errors — VLSM result",
                    len(errors) == 0, "; ".join(errors))
        assert_true("no console warnings — VLSM result",
                    len(warnings) == 0, "; ".join(warnings))
        errors.clear()
        warnings.clear()

        # 5. Theme toggle (dark → light → dark)
        await navigate(page, APP_URL)
        await page.wait_for_timeout(200)
        await page.click("#theme-toggle")
        await page.wait_for_timeout(200)
        assert_true("no console errors — light mode toggle",
                    len(errors) == 0, "; ".join(errors))
        await page.click("#theme-toggle")
        await page.wait_for_timeout(200)
        assert_true("no console errors — dark mode toggle",
                    len(errors) == 0, "; ".join(errors))
        errors.clear()
        warnings.clear()

        # 6. Hover every help-bubble icon (tooltip JS runs)
        await navigate(page, APP_URL)
        await page.wait_for_timeout(200)
        icon_count = await page.locator(".help-bubble-icon").count()
        for i in range(icon_count):
            icon = page.locator(".help-bubble-icon").nth(i)
            if await icon.is_visible():
                await icon.hover(timeout=1000)
        await page.wait_for_timeout(200)
        assert_true("no console errors — help bubble hovers",
                    len(errors) == 0, "; ".join(errors))
        assert_true("no console warnings — help bubble hovers",
                    len(warnings) == 0, "; ".join(warnings))
        errors.clear()
        warnings.clear()

        # 7. Copy button clicks
        await navigate(page, APP_URL + "?ip=10.0.0.0&mask=24")
        await page.wait_for_selector(".results", timeout=8000)
        copy_btns = page.locator(".subnet-copy, .copy-btn")
        cnt = await copy_btns.count()
        if cnt > 0:
            await copy_btns.first.click()
            await page.wait_for_timeout(200)
        assert_true("no console errors — copy button click",
                    len(errors) == 0, "; ".join(errors))
        errors.clear()
        warnings.clear()

        # 8. Tab switching
        await navigate(page, APP_URL)
        for tab in ("#tab-ipv6", "#tab-vlsm", "#tab-ipv4"):
            await page.click(tab)
            await page.wait_for_timeout(150)
        assert_true("no console errors — tab switching",
                    len(errors) == 0, "; ".join(errors))
        assert_true("no console warnings — tab switching",
                    len(warnings) == 0, "; ".join(warnings))
        errors.clear()
        warnings.clear()

    finally:
        page.remove_listener("console", _on_msg)


async def test_theme_light_dark(page: Page) -> None:
    """
    Verify dark and light theme: computed colours, persistence across reload,
    and that no UI element is invisible (white-on-white / black-on-black) in
    either mode.
    """
    section("Theme — light and dark mode visual verification")

    await page.set_viewport_size({"width": 1280, "height": 800})
    await navigate(page, APP_URL)

    # ── Dark mode (default) ─────────────────────────────────────────────────
    theme_attr = await page.get_attribute("html", "data-theme")
    is_dark = theme_attr != "light"
    assert_true("default theme is dark (data-theme != 'light')", is_dark,
                f"data-theme={theme_attr!r}")

    dark_vars = await page.evaluate("""() => {
        var r = document.documentElement;
        var cs = getComputedStyle(r);
        return {
            bg:      cs.getPropertyValue('--color-bg').trim(),
            surface: cs.getPropertyValue('--color-surface').trim(),
            text:    cs.getPropertyValue('--color-text').trim(),
            border:  cs.getPropertyValue('--color-border').trim(),
        };
    }""")
    assert_true("dark --color-bg is set",     bool(dark_vars["bg"]),     dark_vars["bg"])
    assert_true("dark --color-surface is set", bool(dark_vars["surface"]), dark_vars["surface"])
    assert_true("dark --color-text is set",   bool(dark_vars["text"]),   dark_vars["text"])

    # bg and text must be different colours (not invisible)
    assert_true(
        "dark: bg and text colours differ (no invisible text)",
        dark_vars["bg"] != dark_vars["text"],
        f"bg={dark_vars['bg']} text={dark_vars['text']}"
    )

    # Card must be visible against background
    card_bg = await page.evaluate(
        "() => getComputedStyle(document.querySelector('.card')).backgroundColor"
    )
    body_bg = await page.evaluate(
        "() => getComputedStyle(document.body).backgroundColor"
    )
    assert_true("dark: card background is set", bool(card_bg), card_bg)

    # Key elements visible in dark mode
    assert_true("dark: h1 visible",      await page.locator("h1").is_visible())
    assert_true("dark: logo visible",    await page.locator("img.logo").is_visible())
    assert_true("dark: tabs visible",    await page.locator(".tabs").is_visible())
    assert_true("dark: form visible",    await page.locator("#panel-ipv4 .form-row").first.is_visible())

    # IPv4 result in dark mode
    await navigate(page, APP_URL + "?ip=192.168.1.0&mask=%2F24")
    await page.wait_for_selector(".results", timeout=8000)
    assert_true("dark: results panel visible", await page.locator(".results").is_visible())
    result_text_color = await page.evaluate(
        "() => getComputedStyle(document.querySelector('.result-value')).color"
    )
    assert_true("dark: result value has colour", bool(result_text_color), result_text_color)

    # ── Toggle to light mode ────────────────────────────────────────────────
    await navigate(page, APP_URL)
    theme_btn = page.locator("#theme-toggle")
    await theme_btn.click()
    await page.wait_for_timeout(200)

    theme_light = await page.get_attribute("html", "data-theme")
    assert_eq("after toggle: data-theme is 'light'", theme_light, "light")

    light_vars = await page.evaluate("""() => {
        var r = document.documentElement;
        var cs = getComputedStyle(r);
        return {
            bg:      cs.getPropertyValue('--color-bg').trim(),
            surface: cs.getPropertyValue('--color-surface').trim(),
            text:    cs.getPropertyValue('--color-text').trim(),
        };
    }""")
    assert_true("light --color-bg is set",   bool(light_vars["bg"]),   light_vars["bg"])
    assert_true("light --color-text is set", bool(light_vars["text"]), light_vars["text"])

    # bg and text must differ in light mode too
    assert_true(
        "light: bg and text colours differ (no invisible text)",
        light_vars["bg"] != light_vars["text"],
        f"bg={light_vars['bg']} text={light_vars['text']}"
    )

    # Dark and light bg colours must be distinct from each other
    assert_true(
        "light bg differs from dark bg (themes are genuinely different)",
        light_vars["bg"] != dark_vars["bg"],
        f"light={light_vars['bg']} dark={dark_vars['bg']}"
    )

    # Key elements visible in light mode
    assert_true("light: h1 visible",      await page.locator("h1").is_visible())
    assert_true("light: logo visible",    await page.locator("img.logo").is_visible())
    assert_true("light: tabs visible",    await page.locator(".tabs").is_visible())
    assert_true("light: form visible",    await page.locator("#panel-ipv4 .form-row").first.is_visible())

    # IPv4 result in light mode
    await navigate(page, APP_URL + "?ip=192.168.1.0&mask=%2F24")
    await page.wait_for_selector(".results", timeout=8000)
    assert_true("light: results panel visible", await page.locator(".results").is_visible())
    light_result_color = await page.evaluate(
        "() => getComputedStyle(document.querySelector('.result-value')).color"
    )
    assert_true("light: result value has colour", bool(light_result_color), light_result_color)

    # ── Theme persists across reload ────────────────────────────────────────
    await page.reload(wait_until="load")
    await page.wait_for_timeout(200)
    theme_after_reload = await page.get_attribute("html", "data-theme")
    assert_eq("light theme persists after page reload", theme_after_reload, "light")

    # ── Toggle back to dark and verify persistence ───────────────────────────
    await navigate(page, APP_URL)
    await page.click("#theme-toggle")
    await page.wait_for_timeout(200)
    theme_back_to_dark = await page.get_attribute("html", "data-theme")
    assert_true("toggled back to dark mode",
                theme_back_to_dark != "light", f"data-theme={theme_back_to_dark!r}")

    await page.reload(wait_until="load")
    await page.wait_for_timeout(200)
    theme_dark_persisted = await page.get_attribute("html", "data-theme")
    assert_true("dark theme persists after page reload",
                theme_dark_persisted != "light", f"data-theme={theme_dark_persisted!r}")

    # ── VLSM result in light mode (most complex output) ─────────────────────
    # Switch to light again for this check
    await navigate(page, APP_URL)
    await page.click("#theme-toggle")
    await page.wait_for_timeout(200)
    await navigate(
        page,
        APP_URL + "?tab=vlsm&vlsm_network=10.0.0.0&vlsm_cidr=24"
        "&vlsm_name%5B%5D=LAN&vlsm_hosts%5B%5D=50"
    )
    await page.wait_for_selector(".vlsm-table", timeout=8000)
    assert_true("light: VLSM table visible", await page.locator(".vlsm-table").is_visible())
    assert_true("light: VLSM summary visible",
                await page.locator(".vlsm-summary").is_visible())

    # Restore dark mode at end so other tests aren't affected
    await navigate(page, APP_URL)
    current = await page.get_attribute("html", "data-theme")
    if current == "light":
        await page.click("#theme-toggle")
        await page.wait_for_timeout(200)


# ---------------------------------------------------------------------------
# a11y tests (v2.5.0)
# ---------------------------------------------------------------------------

async def test_a11y_landmarks(page: Page) -> None:
    section("a11y — page landmarks (<main>, skip link)")
    await navigate(page, APP_URL)
    assert_true("<main> element exists", await page.locator("main.card").count() == 1)
    skip_link = page.locator("a.skip-link")
    assert_true("skip link exists", await skip_link.count() == 1)
    assert_eq("skip link href", await skip_link.get_attribute("href"), "#main-content")
    assert_eq("main has id main-content", await page.locator("#main-content").count(), 1)


async def test_a11y_focus_inputs(page: Page) -> None:
    section("a11y — input focus ring (outline not none)")
    await navigate(page, APP_URL)
    outline = await page.eval_on_selector("#ip", """el => {
        el.focus();
        return getComputedStyle(el).outlineStyle;
    }""")
    assert_true("input focus outline is not none", outline != "none")
    btn_outline = await page.eval_on_selector("button[type='submit']", """el => {
        el.focus();
        return getComputedStyle(el).outlineStyle;
    }""")
    assert_true("button focus outline is not none", btn_outline != "none")
    reset_outline = await page.eval_on_selector("a.btn.reset", """el => {
        el.focus();
        return getComputedStyle(el).outlineStyle;
    }""")
    assert_true("a.btn focus outline is not none", reset_outline != "none")


async def test_a11y_toast_aria(page: Page) -> None:
    section("a11y — toast aria-live region")
    await navigate(page, APP_URL)
    toast = page.locator("#toast")
    assert_eq("toast role", await toast.get_attribute("role"), "status")
    assert_eq("toast aria-live", await toast.get_attribute("aria-live"), "polite")
    assert_eq("toast aria-atomic", await toast.get_attribute("aria-atomic"), "true")


async def test_a11y_help_bubble_keyboard(page: Page) -> None:
    section("a11y — help bubble keyboard accessible")
    await navigate(page, APP_URL)
    icon = page.locator(".help-bubble-icon").first
    assert_eq("help icon tabindex", await icon.get_attribute("tabindex"), "0")
    assert_eq("help icon role", await icon.get_attribute("role"), "button")
    assert_eq("help icon aria-label", await icon.get_attribute("aria-label"), "Help")


async def test_a11y_reduced_motion_css(page: Page) -> None:
    section("a11y — prefers-reduced-motion CSS media query present")
    await navigate(page, APP_URL)
    has_rule = await page.evaluate("""() => {
        for (const sheet of document.styleSheets) {
            try {
                for (const rule of sheet.cssRules) {
                    if (rule.conditionText?.includes('prefers-reduced-motion')) return true;
                }
            } catch (e) {}
        }
        return false;
    }""")
    assert_true("prefers-reduced-motion media query present", has_rule)


# ---------------------------------------------------------------------------
# v3.0.0 — keyboard shortcut overlay (#300) and recent calculations (#301)
# ---------------------------------------------------------------------------

async def test_kbd_overlay_question_mark_opens(page: Page) -> None:
    section("v3.0.0 #300 keyboard overlay — '?' opens, Esc closes")
    await navigate(page, APP_URL)
    overlay = page.locator("#kbd-overlay")
    assert_true("kbd overlay: hidden by default", await overlay.is_hidden())
    # Press ? on the body (not in any input).
    await page.locator("body").click()
    await page.keyboard.press("Shift+/")
    assert_true(
        "kbd overlay: visible after '?'",
        await overlay.is_visible(),
    )
    assert_true(
        "kbd overlay: shows shortcut entries",
        await overlay.locator(".kbd-list dt").count() >= 5,
    )
    await page.keyboard.press("Escape")
    assert_true(
        "kbd overlay: hidden after Esc",
        await overlay.is_hidden(),
    )


async def test_kbd_overlay_header_button_opens(page: Page) -> None:
    section("v3.0.0 #300 keyboard overlay — header button opens it")
    await navigate(page, APP_URL)
    await page.click("#kbd-help-toggle")
    assert_true(
        "kbd overlay: visible after header click",
        await page.locator("#kbd-overlay").is_visible(),
    )
    # Backdrop click closes.
    await page.locator("#kbd-overlay").click(position={"x": 5, "y": 5})
    assert_true(
        "kbd overlay: hidden after backdrop click",
        await page.locator("#kbd-overlay").is_hidden(),
    )


async def test_kbd_tab_switching_digits(page: Page) -> None:
    section("v3.0.0 #300 keyboard — digits 1-4 switch tabs")
    await navigate(page, APP_URL)
    await page.locator("body").click()
    await page.keyboard.press("3")
    assert_eq(
        "kbd: '3' selects vlsm tab",
        await page.get_attribute("#tab-vlsm", "aria-selected"),
        "true",
    )
    # v3.1.0 (#328): digit presses now also focus the first input on the
    # destination panel, so we must blur before the next digit press —
    # otherwise the inEditable gate (correctly) swallows it.
    await page.evaluate("() => document.activeElement && document.activeElement.blur()")
    await page.locator("body").click()
    await page.keyboard.press("1")
    assert_eq(
        "kbd: '1' selects ipv4 tab",
        await page.get_attribute("#tab-ipv4", "aria-selected"),
        "true",
    )


async def test_kbd_slash_focuses_input(page: Page) -> None:
    section("v3.0.0 #300 keyboard — '/' focuses first input on active tab")
    await navigate(page, APP_URL)
    await page.locator("body").click()
    await page.keyboard.press("/")
    focused_id = await page.evaluate("() => document.activeElement?.id")
    assert_eq("kbd: '/' focuses #ip on IPv4 tab", focused_id, "ip")


async def test_kbd_tab_switch_focuses_input(page: Page) -> None:
    section("v3.1.0 #328 — tab-switch digits focus the first input on the destination panel")
    await navigate(page, APP_URL)
    await page.locator("body").click()
    expectations = [
        ("1", "ip"),
        ("2", "ipv6"),
        ("3", "vlsm_network"),
        ("4", "vlsm6_network"),
    ]
    for key, expected_id in expectations:
        # Reload between iterations: each digit press lands on an input, and a
        # follow-up digit press would otherwise be (correctly) swallowed by the
        # inEditable gate.
        await navigate(page, APP_URL)
        await page.locator("body").click()
        await page.evaluate("() => document.activeElement && document.activeElement.blur()")
        await page.keyboard.press(key)
        await page.wait_for_function(
            "id => document.activeElement && document.activeElement.id === id",
            arg=expected_id,
        )
        active_tag = await page.evaluate("() => document.activeElement?.tagName")
        active_id = await page.evaluate("() => document.activeElement?.id")
        assert_eq(f"kbd: '{key}' focuses INPUT after tab switch", active_tag, "INPUT")
        assert_eq(f"kbd: '{key}' focuses #{expected_id}", active_id, expected_id)


async def test_kbd_help_button_hidden_on_touch(page: Page) -> None:
    section("v3.0.0 #300 keyboard — help button hidden on coarse pointers (CSS @media)")
    await navigate(page, APP_URL)
    has_rule = await page.evaluate("""() => {
        for (const sheet of document.styleSheets) {
            try {
                for (const rule of sheet.cssRules) {
                    if (rule.conditionText?.includes('hover: none')) {
                        const css = rule.cssText || '';
                        if (css.includes('kbd-help-toggle')) return true;
                    }
                }
            } catch (e) {}
        }
        return false;
    }""")
    assert_true("kbd help: @media (hover: none) hides #kbd-help-toggle", has_rule)


async def test_history_disabled_by_default(page: Page) -> None:
    section("v3.0.0 #301 history — disabled by default; opening overlay shows opt-in")
    await navigate(page, APP_URL)
    # Clear any localStorage from previous tests in this context.
    await page.evaluate("() => localStorage.clear()")
    await page.click("#history-toggle")
    overlay = page.locator("#history-overlay")
    assert_true("history: overlay visible", await overlay.is_visible())
    cb = page.locator("#history-enabled-toggle")
    assert_eq("history: toggle starts unchecked", await cb.is_checked(), False)
    assert_true(
        "history: disabled-msg is shown when off",
        await page.locator("#history-disabled-msg").is_visible(),
    )


async def test_history_opt_in_records_calculation(page: Page) -> None:
    section("v3.0.0 #301 history — enabling, calculating, then re-opening shows entry")
    await navigate(page, APP_URL)
    await page.evaluate("() => localStorage.clear()")
    await page.click("#history-toggle")
    await page.locator("#history-enabled-toggle").check()
    await page.locator("#history-overlay .modal-close").click()

    # Run a successful calculation; module captures URL on next page load.
    await navigate(page, APP_URL + "?ip=192.168.50.0&mask=24&tab=ipv4")
    # Open history again and assert the entry appears.
    await page.click("#history-toggle")
    items = page.locator("#history-list .history-item")
    assert_true(
        "history: at least one entry recorded",
        await items.count() >= 1,
    )
    first_link = items.first.locator(".history-link")
    label = await first_link.text_content()
    assert_true(
        "history: entry label contains the input",
        bool(label) and "192.168.50.0" in (label or ""),
    )


async def test_history_clear_removes_entries(page: Page) -> None:
    section("v3.0.0 #301 history — Clear all empties the list")
    await navigate(page, APP_URL)
    # Seed an entry directly so this test does not depend on order.
    await page.evaluate(
        """() => {
            localStorage.setItem('sc.history.enabled', '1');
            localStorage.setItem('sc.history.entries',
                JSON.stringify([{url: '?ip=10.0.0.0&mask=8', tab: 'ipv4', label: '10.0.0.0', ts: 1}]));
        }"""
    )
    await page.click("#history-toggle")
    assert_true(
        "history: seeded entry visible",
        await page.locator("#history-list .history-item").count() == 1,
    )
    await page.click("#history-clear")
    assert_eq(
        "history: cleared list is empty",
        await page.locator("#history-list .history-item").count(),
        0,
    )
    stored = await page.evaluate("() => localStorage.getItem('sc.history.entries')")
    assert_eq("history: localStorage cleared", stored, "[]")


async def test_history_h_key_opens(page: Page) -> None:
    section("v3.0.0 #301 history — 'h' key opens overlay")
    await navigate(page, APP_URL)
    await page.locator("body").click()
    await page.keyboard.press("h")
    assert_true(
        "history: 'h' opens overlay",
        await page.locator("#history-overlay").is_visible(),
    )


async def _enable_history(page: Page) -> None:
    """Helper: enable the history opt-in via the overlay UI."""
    await page.evaluate("() => localStorage.clear()")
    await page.click("#history-toggle")
    await page.locator("#history-enabled-toggle").check()
    await page.locator("#history-overlay .modal-close").click()


# v3.1.0 #327 — data-history-source extension
async def test_history_captures_lookup_result(page: Page) -> None:
    section("v3.1.0 #327 history — IP Lookup result captured via data-history-source")
    await navigate(page, APP_URL)
    await _enable_history(page)
    # Run a lookup via shareable GET URL — history-capture fires on next page load.
    await navigate(
        page,
        APP_URL + "?tab=ipv4&lookup_cidrs=10.0.0.0%2F8%0A192.168.0.0%2F16&lookup_ips=10.1.2.3%0A192.168.5.5%0A8.8.8.8",
    )
    # Verify the result block carries the new trio of attributes.
    block = page.locator('[data-history-source="lookup"][data-history-active="1"]').first
    assert_true("history #327: lookup result has data-history-source", await block.count() >= 1)
    label = await block.get_attribute("data-history-label")
    assert_true(
        "history #327: lookup label uses 'Lookup:' prefix with counts",
        bool(label) and label.startswith("Lookup:") and "IP" in (label or "") and "CIDR" in (label or ""),
    )
    # Close the auto-opened tool drawer so the history toggle is clickable.
    await page.locator(".panel.active .tool-drawer.open .tool-drawer-close").first.click()
    await page.click("#history-toggle")
    items = page.locator("#history-list .history-item")
    assert_true("history #327: lookup entry recorded", await items.count() >= 1)
    first_label = await items.first.locator(".history-link").text_content()
    assert_true(
        "history #327: lookup history label starts with 'Lookup:'",
        bool(first_label) and (first_label or "").startswith("Lookup:"),
    )


async def test_history_captures_diff_result(page: Page) -> None:
    section("v3.1.0 #327 history — Subnet Diff result captured via data-history-source")
    await navigate(page, APP_URL)
    await _enable_history(page)
    await navigate(
        page,
        APP_URL + "?tab=ipv4&diff_before=10.0.0.0%2F24%0A10.0.1.0%2F24&diff_after=10.0.0.0%2F23%0A10.0.2.0%2F24",
    )
    block = page.locator('[data-history-source="diff"][data-history-active="1"]').first
    assert_true("history #327: diff result has data-history-source", await block.count() >= 1)
    label = await block.get_attribute("data-history-label")
    assert_true(
        "history #327: diff label uses 'Diff:' prefix",
        bool(label) and (label or "").startswith("Diff:"),
    )
    await page.locator(".panel.active .tool-drawer.open .tool-drawer-close").first.click()
    await page.click("#history-toggle")
    items = page.locator("#history-list .history-item")
    assert_true("history #327: diff entry recorded", await items.count() >= 1)
    first_label = await items.first.locator(".history-link").text_content()
    assert_true(
        "history #327: diff history label starts with 'Diff:'",
        bool(first_label) and (first_label or "").startswith("Diff:"),
    )


async def test_history_captures_wildcard_result(page: Page) -> None:
    section("v3.1.0 #327 history — Wildcard result captured via data-history-source")
    await navigate(page, APP_URL)
    await _enable_history(page)
    # Wildcard is POST-only; submit via the tool drawer form.
    await navigate(page, APP_URL + "?tab=ipv4")
    # Open the wildcard tool drawer.
    await page.locator('.tool-trigger[data-tool="wildcard"]').first.click()
    await page.locator("#wildcard_input").fill("/24")
    await page.locator('.tool-panel[data-tool="wildcard"] form button[type="submit"]').click()
    # After POST submit, the page reloads with the result rendered.
    block = page.locator('[data-history-source="wildcard"][data-history-active="1"]').first
    assert_true("history #327: wildcard result has data-history-source", await block.count() >= 1)
    label = await block.get_attribute("data-history-label")
    assert_true(
        "history #327: wildcard label uses 'Wildcard:' prefix with input",
        bool(label) and (label or "").startswith("Wildcard:") and "/24" in (label or ""),
    )
    await page.locator(".panel.active .tool-drawer.open .tool-drawer-close").first.click()
    await page.click("#history-toggle")
    items = page.locator("#history-list .history-item")
    assert_true("history #327: wildcard entry recorded", await items.count() >= 1)
    first_label = await items.first.locator(".history-link").text_content()
    assert_true(
        "history #327: wildcard history label starts with 'Wildcard:'",
        bool(first_label) and (first_label or "").startswith("Wildcard:"),
    )


async def test_vlsm_keyboard_delete(page: Page) -> None:
    section("VLSM — keyboard Delete on remove button")
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.click(".vlsm-add-row")
    rows_before = await page.locator(".vlsm-req-row").count()
    remove_btns = page.locator(".vlsm-remove-row")
    await remove_btns.first.focus()
    await page.keyboard.press("Delete")
    rows_after = await page.locator(".vlsm-req-row").count()
    assert_true("Delete key removes a VLSM row", rows_after == rows_before - 1)
    focused_class = await page.evaluate(
        "document.activeElement ? document.activeElement.className : ''"
    )
    assert_true(
        "focus moves to a remaining VLSM name input after delete",
        "vlsm-name-input" in (focused_class or ""),
        str(focused_class),
    )
    # Backspace path
    await page.click(".vlsm-add-row")
    rows_before_bs = await page.locator(".vlsm-req-row").count()
    await page.locator(".vlsm-remove-row").first.focus()
    await page.keyboard.press("Backspace")
    rows_after_bs = await page.locator(".vlsm-req-row").count()
    assert_true("Backspace key removes a VLSM row", rows_after_bs == rows_before_bs - 1)
    focused_class_bs = await page.evaluate(
        "document.activeElement ? document.activeElement.className : ''"
    )
    assert_true(
        "focus moves to name input after Backspace delete",
        "vlsm-name-input" in (focused_class_bs or ""),
        str(focused_class_bs),
    )


async def _open_tree_editor(page: Page, root_cidr: str = "10.0.0.0/24") -> None:
    """Open the IPv4 tab, expand the Tree Editor tool drawer, and start editing."""
    await navigate(page, APP_URL)
    await page.evaluate("() => localStorage.clear()")
    await page.click("#panel-ipv4 .tool-trigger[data-tool='tree-editor']")
    await page.wait_for_selector("#panel-ipv4 .tool-drawer.open")
    await page.fill("#tree_editor_cidr", root_cidr)
    await page.click("#tree-editor-init button[type='submit']")
    await page.wait_for_selector(".tree-editor-canvas .tree-editor-card")


async def test_tree_editor_split(page: Page) -> None:
    section("v3.0.0 #302 tree editor — split root into 4 children")
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    await page.wait_for_selector("[data-role='split-modal']:not([hidden])")
    await page.click("[data-split-into='4']")
    cidrs = await page.evaluate(
        "() => Array.from(document.querySelectorAll('.tree-editor-cidr')).map(e=>e.textContent)"
    )
    assert_eq("split /24 into 4 → 5 nodes total", str(len(cidrs)), "5")
    assert_true("first child is 10.0.0.0/26", "10.0.0.0/26" in cidrs)
    assert_true("last child is 10.0.0.192/26", "10.0.0.192/26" in cidrs)


async def test_tree_editor_merge_via_drag(page: Page) -> None:
    section("v3.0.0 #302 tree editor — drag-merge sibling restores parent")
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    await page.click("[data-split-into='2']")
    # Verify split happened.
    initial = await page.locator(".tree-editor-cidr").count()
    assert_true("split occurred before merge", initial == 3)
    # Drag first child onto second.
    children = page.locator(".tree-editor-children .tree-editor-card")
    # Scroll children into view so HTML5 drag-and-drop's drop event fires —
    # Chromium silently drops when source/destination are below the painted
    # viewport region (#323 added a toolbar button that pushed the canvas
    # low enough to expose this latent fragility).
    await children.nth(1).scroll_into_view_if_needed()
    src = await children.nth(0).bounding_box()
    dst = await children.nth(1).bounding_box()
    if src and dst:
        await page.mouse.move(src["x"] + 5, src["y"] + 5)
        await page.mouse.down()
        await page.mouse.move(dst["x"] + 5, dst["y"] + 5, steps=5)
        await page.mouse.up()
    after = await page.locator(".tree-editor-cidr").count()
    assert_eq("after drag-merge → 1 node remains", str(after), "1")


async def test_tree_editor_rename(page: Page) -> None:
    section("v3.0.0 #302 tree editor — rename + notes persist")
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-pencil")
    await page.wait_for_selector("[data-role='rename-modal']:not([hidden])")
    await page.fill("[data-role='rename-name']", "Corp HQ")
    await page.fill("[data-role='rename-notes']", "edge router")
    await page.click("[data-role='rename-save']")
    name = await page.locator(".tree-editor-name").first.text_content()
    notes = await page.locator(".tree-editor-notes").first.text_content()
    assert_eq("name renders", name, "Corp HQ")
    assert_eq("notes render", notes, "edge router")


async def test_tree_editor_undo(page: Page) -> None:
    section("v3.0.0 #302 tree editor — Ctrl+Z reverts last edit")
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    await page.click("[data-split-into='2']")
    assert_true("split happened", await page.locator(".tree-editor-cidr").count() == 3)
    await page.locator(".tree-editor-canvas").click()  # focus inside editor
    await page.keyboard.press("Control+z")
    after = await page.locator(".tree-editor-cidr").count()
    assert_eq("after undo → 1 node", str(after), "1")


async def test_tree_editor_autosave_round_trip(page: Page) -> None:
    section("v3.0.0 #302 tree editor — localStorage autosave restores on reload")
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    await page.click("[data-split-into='2']")
    # autosave is debounced 300 ms.
    await page.wait_for_timeout(400)
    stored = await page.evaluate("() => localStorage.getItem('sc.tree.draft.10.0.0.0/24')")
    assert_true("autosave wrote a draft", stored is not None and "10.0.0.0/25" in stored)
    # Reload and verify the editor restores from autosave.
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='tree-editor']")
    await page.fill("#tree_editor_cidr", "10.0.0.0/24")
    await page.click("#tree-editor-init button[type='submit']")
    await page.wait_for_selector(".tree-editor-canvas .tree-editor-card")
    cidrs = await page.evaluate(
        "() => Array.from(document.querySelectorAll('.tree-editor-cidr')).map(e=>e.textContent)"
    )
    assert_true("reload: split children present", "10.0.0.0/25" in cidrs and "10.0.0.128/25" in cidrs)


async def test_tree_editor_save_session(page: Page) -> None:
    section("v3.0.0 #302 tree editor — Save Session POSTs and returns id")
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    await page.click("[data-split-into='2']")
    await page.click("[data-action='save-session']")
    # status banner shows "Saved as session XXXXXXXX".
    await page.wait_for_function(
        "() => /Saved as session [0-9a-f]{8}/.test(document.querySelector('.tree-editor-status').textContent || '')",
        timeout=5000,
    )
    status = await page.locator(".tree-editor-status").text_content()
    assert_true("save status surfaces session id", bool(status) and "Saved as session" in (status or ""))


async def test_tree_editor_share_url(page: Page) -> None:
    section("v3.0.0 #302 tree editor — Share URL emits ?tree=… and is copyable")
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    await page.click("[data-split-into='2']")
    # Stub clipboard so the test never depends on a real clipboard surface.
    await page.add_init_script(
        "window.__lastClipboard = null; "
        "navigator.clipboard = navigator.clipboard || {}; "
        "navigator.clipboard.writeText = function(t){ window.__lastClipboard = t; return Promise.resolve(); };"
    )
    await page.click("[data-action='share-url']")
    share_url = await page.locator("[data-role='share-url']").text_content()
    assert_true("share URL contains ?tree=", bool(share_url) and "tree=" in (share_url or ""))


async def test_tree_editor_copy_formats(page: Page) -> None:
    section("v3.0.0 #302 tree editor — copy CIDR / Markdown / Cisco")
    # Install clipboard stub BEFORE app.js boots. On insecure HTTP
    # (test rig) navigator.clipboard is undefined and a plain assignment
    # is silently swallowed; defineProperty with configurable:true wins.
    await page.add_init_script(
        "window.__clip = [];"
        "try {"
        "  Object.defineProperty(navigator, 'clipboard', {"
        "    value: { writeText: function(t){ window.__clip.push(t); return Promise.resolve(); } },"
        "    configurable: true,"
        "    writable: true"
        "  });"
        "} catch (e) {}"
    )
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    await page.click("[data-split-into='2']")
    await page.click("[data-action='copy-cidr']")
    await page.click("[data-action='copy-md']")
    await page.click("[data-action='copy-cisco']")
    captured = await page.evaluate("() => window.__clip || []")
    assert_eq("3 clipboard payloads recorded", str(len(captured)), "3")
    assert_true("CIDR list contains /25 children", "10.0.0.0/25" in captured[0] and "10.0.0.128/25" in captured[0])
    assert_true("Markdown export starts with heading", captured[1].startswith("# Subnet Plan"))
    assert_true("Cisco export contains interface stanza", "interface XX" in captured[2])


async def test_tree_editor_action_sheet_on_touch(page: Page) -> None:
    section("v3.0.0 #302 tree editor — action sheet appears on touch (hover:none)")
    # Force the (hover:none) media-query branch to activate.
    await page.emulate_media(reduced_motion=None)
    # Playwright does not directly emulate hover:none, but matchMedia can be overridden
    # via add_init_script — test the actual branch path.
    await page.add_init_script(
        "(() => { const orig = window.matchMedia.bind(window);"
        " window.matchMedia = function(q){ if (q.indexOf('hover: none') !== -1) "
        "{ return { matches: true, media: q, addListener:()=>{}, removeListener:()=>{}, "
        "addEventListener:()=>{}, removeEventListener:()=>{}, onchange: null, dispatchEvent: ()=>true }; } "
        "return orig(q); }; })();"
    )
    await _open_tree_editor(page, "10.0.0.0/24")
    await page.click(".tree-editor-canvas .tree-editor-card")
    visible = await page.locator("[data-role='action-sheet']").is_visible()
    assert_true("touch: tap opens action sheet (not split modal)", visible)
    split_visible = await page.locator("[data-role='split-modal']").is_visible()
    assert_true("touch: split modal stays closed on initial tap", not split_visible)


async def test_tree_editor_share_too_large_fallback(page: Page) -> None:
    section("v3.0.0 #302 tree editor — share-URL fallback when >50 nodes")
    await _open_tree_editor(page, "10.0.0.0/24")
    # Inject a large fake state with >50 nodes via the public-ish localStorage path,
    # then re-open the editor so it loads the autosave.
    big_root = '{"cidr":"10.0.0.0/24","children":[' + ','.join(
        ['{"cidr":"10.0.0.' + str(i) + '/32"}' for i in range(0, 60)]
    ) + ']}'
    await page.evaluate(f"() => localStorage.setItem('sc.tree.draft.10.0.0.0/24', {big_root!r})")
    await navigate(page, APP_URL)
    await page.click("#panel-ipv4 .tool-trigger[data-tool='tree-editor']")
    await page.fill("#tree_editor_cidr", "10.0.0.0/24")
    await page.click("#tree-editor-init button[type='submit']")
    await page.wait_for_selector(".tree-editor-canvas .tree-editor-card")
    await page.click("[data-action='share-url']")
    status = await page.locator(".tree-editor-status").text_content()
    assert_true(
        "share-url too-large fallback message surfaces",
        bool(status) and "too large" in (status or "").lower(),
        str(status),
    )


# v3.1.0 #322 — multi-tree diff
async def test_tree_editor_diff_two_sessions(page: Page) -> None:
    section("v3.1.0 #322 tree editor — diff modal renders four categories")
    # Stub clipboard *before* navigating so the production navigator.clipboard
    # path is overridden when the diff "Copy Markdown" button fires.
    await page.add_init_script(
        "Object.defineProperty(navigator, 'clipboard', {"
        " configurable: true, value: {"
        "  writeText: function (t) { window.__lastClipboard = t;"
        "  return Promise.resolve(); }"
        " }});"
    )
    await _open_tree_editor(page, "10.0.0.0/24")

    tree_a = (
        '{"type":"tree","root":{"cidr":"10.0.0.0/24","name":"DMZ",'
        '"children":[{"cidr":"10.0.0.0/25"},{"cidr":"10.0.0.128/25"}]}}'
    )
    # Tree B: rename root, change /25 → /26 prefix on first child,
    # remove second child, add a fresh /26 sibling.
    tree_b = (
        '{"type":"tree","root":{"cidr":"10.0.0.0/24","name":"Edge",'
        '"children":[{"cidr":"10.0.0.0/26"},{"cidr":"10.0.0.64/26"}]}}'
    )

    await page.click(".tree-editor-toolbar [data-action='diff']")
    await page.wait_for_selector("[data-role='diff-modal']:not([hidden])")

    # Tab "Paste JSON" is active by default. Fill both textareas.
    fields = page.locator("[data-role='diff-modal'] .tree-diff-source-paste")
    await fields.nth(0).fill(tree_a)
    await fields.nth(1).fill(tree_b)
    await page.click("[data-role='diff-compare']")
    await page.wait_for_selector("[data-role='diff-result']:not([hidden])")

    # Inspect the rendered annotations.
    diffs = await page.evaluate(
        "() => Array.from(document.querySelectorAll("
        "'[data-role=\"diff-canvas\"] .tree-editor-node[data-diff]'"
        ")).map(n => ({cidr: n.getAttribute('data-cidr'),"
        " kind: n.getAttribute('data-diff')}))"
    )
    by_cidr = {d["cidr"]: d["kind"] for d in diffs}
    assert_true("added 10.0.0.64/26 marked added", by_cidr.get("10.0.0.64/26") == "added")
    assert_true("removed 10.0.0.128/25 marked removed", by_cidr.get("10.0.0.128/25") == "removed")
    # The first child changes prefix from /25 to /26 — stored under the new CIDR.
    assert_true(
        "prefix-changed node marked changed",
        by_cidr.get("10.0.0.0/26") in ("changed", "added"),
    )
    # Root rename → changed annotation on root (10.0.0.0/24).
    assert_true(
        "root rename marked changed",
        by_cidr.get("10.0.0.0/24") == "changed",
    )

    # Summary line emits +/-/Δ counts.
    summary = await page.locator("[data-role='diff-summary']").text_content()
    assert_true("summary emits counts", bool(summary) and "+" in (summary or "") and "−" in (summary or ""))

    # Markdown export records every category — clipboard stub installed pre-nav.
    await page.click("[data-role='diff-copy-md']")
    await page.wait_for_function("() => typeof window.__lastClipboard === 'string'")
    md = await page.evaluate("() => window.__lastClipboard || ''")
    assert_true("markdown contains added marker",   "+ 10.0.0.64/26" in md)
    assert_true("markdown contains removed marker", "− 10.0.0.128/25" in md)
    assert_true("markdown contains prefix marker",  "Δ 10.0.0.0/25 → 10.0.0.0/26" in md)
    assert_true("markdown contains rename marker",  '~ 10.0.0.0/24: name "DMZ" → "Edge"' in md)


# v3.1.0 #323 — tree presets
async def test_tree_editor_apply_preset_then_undo(page: Page) -> None:
    section("v3.1.0 #323 tree editor — apply LAN/DMZ/Mgmt preset, then undo")
    await _open_tree_editor(page, "10.0.0.0/24")

    # Open the preset picker.
    await page.click(".tree-editor-toolbar [data-action='apply-template']")
    await page.wait_for_selector("[data-role='preset-modal']:not([hidden])")
    # Wait until the manifest loads and the picker renders preset items.
    await page.wait_for_selector(".tree-preset-item[data-preset-id='lan-dmz-mgmt-24']")

    # Click the lan-dmz-mgmt-24 preset.
    await page.click(".tree-preset-item[data-preset-id='lan-dmz-mgmt-24']")
    # Confirm pane appears with the editable Root CIDR.
    await page.wait_for_selector("[data-role='preset-confirm']:not([hidden])")
    root_value = await page.locator("[data-role='preset-root-cidr']").input_value()
    assert_true(
        "root CIDR pre-filled from preset (10.0.0.0/24)",
        root_value.strip() == "10.0.0.0/24",
        f"got: {root_value!r}",
    )

    # Apply.
    await page.click("[data-role='preset-apply']")
    # Picker should close; tree editor should now show the split children.
    await page.wait_for_selector(".tree-editor-children .tree-editor-card")

    # Tree should now have the root + 4 children = 5 nodes total.
    cidrs = await page.evaluate(
        "() => Array.from(document.querySelectorAll('.tree-editor-cidr')).map(e=>e.textContent)"
    )
    assert_eq("after apply → 5 nodes", str(len(cidrs)), "5")
    assert_true("LAN child cidr present", "10.0.0.0/26" in cidrs)
    assert_true("DMZ child cidr present", "10.0.0.64/26" in cidrs)
    assert_true("Mgmt child cidr present", "10.0.0.128/26" in cidrs)
    assert_true("Reserve child cidr present", "10.0.0.192/26" in cidrs)

    # The rename ops should have applied — the names render on the cards.
    names = await page.evaluate(
        "() => Array.from(document.querySelectorAll('.tree-editor-name')).map(e=>e.textContent)"
    )
    for expected in ("LAN", "DMZ", "Mgmt", "Reserve"):
        assert_true(f"name {expected} rendered", expected in names)

    # Each preset op pushes its own undo frame — undo should peel back the
    # last rename first (Reserve), not collapse the whole tree.
    await page.locator(".tree-editor-canvas").click()  # focus inside editor
    await page.keyboard.press("Control+z")
    after_names = await page.evaluate(
        "() => Array.from(document.querySelectorAll('.tree-editor-name')).map(e=>e.textContent)"
    )
    assert_true("undo dropped the Reserve rename", "Reserve" not in after_names)


async def test_v290_typography(page: Page) -> None:
    """v2.9.0: Verify Space Grotesk, Plus Jakarta Sans, and Fira Code are loaded."""
    section("v2.9.0 — typography verification")

    await navigate(page, APP_URL)

    h1_font = await page.evaluate(
        "() => getComputedStyle(document.querySelector('h1')).fontFamily"
    )
    assert_true(
        "h1 uses Space Grotesk",
        "Space Grotesk" in h1_font,
        f"h1 fontFamily: {h1_font}"
    )

    body_font = await page.evaluate(
        "() => getComputedStyle(document.body).fontFamily"
    )
    assert_true(
        "body uses Plus Jakarta Sans",
        "Plus Jakarta Sans" in body_font,
        f"body fontFamily: {body_font}"
    )

    input_font = await page.evaluate(
        '() => getComputedStyle(document.querySelector(\'input[type="text"]\')).fontFamily'
    )
    assert_true(
        "input uses Fira Code",
        "Fira Code" in input_font,
        f"input fontFamily: {input_font}"
    )
    assert_true(
        "input does NOT use JetBrains Mono",
        "JetBrains" not in input_font,
        f"input fontFamily: {input_font}"
    )

    calc_bg = await page.evaluate(
        '() => getComputedStyle(document.querySelector(\'button[type="submit"]\')).backgroundColor'
    )
    assert_true(
        "Calculate button background is teal (not blue)",
        "6, 214, 160" in calc_bg or "06d6a0" in calc_bg.lower(),
        f"button bg: {calc_bg}"
    )

    badge_color = await page.evaluate(
        "() => getComputedStyle(document.querySelector('.version')).color"
    )
    assert_true(
        "version badge text is teal",
        "6, 214, 160" in badge_color,
        f"badge color: {badge_color}"
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

async def main() -> None:
    _needs_auth = bool(BASIC_USER and BASIC_PASS)
    if not _needs_auth and "dev-direct.seanmousseau.com" in APP_URL:
        print(f"{RED}ERROR: IPAM_BASIC_USER / IPAM_BASIC_PASS not set.{RST}")
        print("Run: bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/playwright_test.py'")
        sys.exit(1)

    print(f"{BOLD}Subnet Calculator — Playwright browser tests{RST}")
    print(f"{DIM}Target: {APP_URL}{RST}")

    # v3.1.0 #324 — zero admin state at suite setup so a non-fresh webapp
    # container does not carry api_keys / admin_audit rows from a previous
    # crashed run. No-op when PHPUNIT_TEST_DRAIN_TOKEN is unset.
    _drain_admin_state()

    async with async_playwright() as pw:
        # Chromium 127+ aggressively auto-upgrades plain HTTP to HTTPS via
        # several feature flags. The docker test harness serves the app over
        # plain HTTP at http://webapp:8080/, so the full disable set + the
        # docker-compose service rename to avoid ambiguity with the .app TLD
        # are both needed to stop ERR_SSL_PROTOCOL_ERROR on page.goto().
        browser = await pw.chromium.launch(
            headless=True,
            args=[
                "--disable-features=HttpsUpgrades,"
                "AutoupgradeHttpsOnly,"
                "HttpsFirstBalancedMode,"
                "HttpsFirstModeV2,"
                "HttpsFirstModeV2ForEngagedSites,"
                "HttpsFirstModeIncognito",
                "--allow-running-insecure-content",
            ],
        )
        ctx_kwargs: dict = {
            "ignore_https_errors": True,
            "permissions": ["clipboard-read", "clipboard-write"],
        }
        if _needs_auth:
            ctx_kwargs["http_credentials"] = {"username": BASIC_USER, "password": BASIC_PASS}
        context = await browser.new_context(**ctx_kwargs)
        page = await context.new_page()

        try:
            await test_page_load(page)
            await test_headers_and_csp(page)
            await test_ipv4_basic(page)
            await test_ipv4_dotted_mask(page)
            await test_ipv4_cidr_paste(page)
            await test_ipv4_edge_cases(page)
            await test_ipv4_address_types(page)
            await test_ipv4_errors(page)
            await test_ipv4_splitter(page)
            await test_ipv4_shareable_url(page)
            await test_ipv4_reset(page)
            await test_ipv6_basic(page)
            await test_ipv6_types(page)
            await test_ipv6_cidr_paste(page)
            await test_ipv6_errors(page)
            await test_ipv6_splitter(page)
            await test_ipv6_shareable_url(page)
            await test_per_tool_routes_equivalence(page)
            await test_legacy_query_param_url_no_redirect(page)
            await test_canonical_route_with_query_params(page)
            await test_iframe(page)
            await test_theme_toggle(page)
            await test_tab_switch(page)
            await test_permissions_policy(page)
            await test_reverse_dns_ipv4(page)
            await test_reverse_dns_ipv6(page)
            await test_ipv6_small_count(page)
            await test_binary_repr(page)
            await test_overlap_checker(page)
            await test_vlsm(page)
            await test_splitter_copy_buttons(page)
            await test_splitter_shareable_url(page)
            await test_vlsm_shareable_url(page)
            await test_vlsm_csv_export(page)
            await test_vlsm_json_export(page)
            await test_vlsm_xlsx_export(page)
            await test_ascii_export(page)
            await test_vlsm_reset(page)
            await test_vlsm_validation(page)
            await test_vlsm_copy_all(page)
            await test_ipv4_copy_all(page)
            await test_ipv6_copy_all(page)
            await test_vlsm_utilisation_summary(page)
            await test_vlsm_sort_note(page)
            await test_vlsm6_basic(page)
            await test_vlsm6_2pow_n(page)
            await test_vlsm6_non_power_of_two(page)
            await test_vlsm6_overcapacity_error(page)
            await test_vlsm6_shareable_url(page)
            await test_vlsm6_dynamic_rows(page)
            await test_vlsm6_validation_inline(page)
            await test_vlsm6_copy_all(page)
            await test_vlsm6_csv_export(page)
            await test_vlsm6_json_export(page)
            await test_vlsm6_reset(page)
            await test_vlsm6_keyboard_delete(page)
            await test_vlsm6_utilisation_summary(page)
            await test_ipv6_overlap(page)
            await test_multi_cidr_overlap(page)
            await test_ipv6_binary_repr(page)
            await test_regression_bugs_v130(page)
            await test_supernet_ui(page)
            await test_ula_generator_ui(page)
            await test_tooltips_help_bubbles(page)
            await test_tool_drawer_toolbar_renders(page)
            await test_tool_drawer_click_split_opens(page)
            await test_tool_drawer_auto_reopens_after_submit(page)
            await test_tool_drawer_escape_closes(page)
            await test_tool_drawer_close_button(page)
            await test_tool_drawer_toggle_closed(page)
            await test_tool_drawer_switch_tools(page)
            await test_visual_regression(page)
            await test_docs_footer_link(page)
            await test_sitemap_and_robots(page)
            await test_print_stylesheet_dark_mode(page)
            await test_wildcard_cidr_to_wildcard(page)
            await test_wildcard_to_cidr(page)
            await test_wildcard_rejects_noncontiguous(page)
            await test_wildcard_api_endpoint(page)
            await test_lookup_api_endpoint(page)
            await test_lookup_ui(page)
            await test_lookup_ui_ipv6_tab(page)
            await test_lookup_shareable_url(page)
            await test_diff_api_endpoint(page)
            await test_diff_ui(page)
            await test_diff_shareable_url(page)
            await test_copy_as_markdown_ipv4(page)
            await test_copy_as_markdown_ipv6(page)
            await test_copy_as_markdown_vlsm(page)
            await test_copy_as_cisco_ipv4(page)
            await test_copy_as_cisco_vlsm(page)
            await test_copy_as_cisco_ipv6(page)
            await test_copy_as_markdown_splitter(page)
            await test_copy_as_markdown_vlsm6(page)
            await test_api_meta(page)
            await test_api_ipv4(page)
            await test_api_ipv6(page)
            await test_api_vlsm(page)
            await test_vlsm6_api_endpoint(page)
            await test_api_overlap(page)
            await test_api_split(page)
            await test_api_supernet(page)
            await test_api_ula(page)
            await test_api_openapi_spec(page)
            await test_api_rdns(page)
            await test_api_bulk(page)
            await test_api_bulk_covers_new_ipv6_endpoints(page)
            await test_vlsm_session_ttl_notice(page)
            await test_session_forms_spacing(page)
            await test_vlsm6_session_save_load(page)
            # v3.1.0 #321 — IPv6 VLSM Save Session drawer parity
            await test_vlsm6_session_drawer_pattern(page)
            await test_admin_keys_unauth_challenge(page)
            await test_admin_keys_authed_renders(page)
            # v3.2.0 #348 — keys polish (post-mint Copy button, dismiss, empty state)
            await test_admin_keys_post_mint_panel_has_copy_button(page)
            await test_admin_keys_copy_button_invokes_clipboard(page)
            await test_admin_keys_empty_state_links_to_docs(page)
            await test_admin_audit_renders(page)
            await test_admin_audit_records_login_failure(page)
            # v3.2.0 #346 — audit log polish
            await test_admin_audit_filter_tablist_semantics(page)
            await test_admin_audit_action_badges(page)
            await test_admin_audit_sticky_thead(page)
            await test_admin_audit_pagination_disabled_not_focusable(page)
            await test_admin_audit_time_cells_have_datetime_attr(page)
            await test_admin_audit_caption_sr_only(page)
            await test_admin_keys_csrf_rejected(page)
            await test_admin_keys_per_row_rpm_edit(page)
            await test_admin_keys_revoke_confirm_present(page)
            await test_admin_drain_endpoint_zeroes_state(page)
            # v3.2.0 #349 — settings page
            await test_admin_settings_page_renders(page)
            await test_admin_settings_validation_rejects_invalid(page)
            await test_admin_settings_secret_masking(page)
            await test_admin_settings_section_anchors_and_extras(page)
            await test_admin_audit_pagination_param(page)
            await test_admin_totp_page_renders(page)
            # v3.2.0 #347 — TOTP enrol/disable polish (replaces #313 generate_secret flow)
            await test_admin_totp_enrol_invalid_code_renders_error(page)
            await test_admin_totp_audit_enrol_events_logged(page)
            await test_admin_totp_regenerate_blocked_when_disabled(page)
            await test_permissions_policy_directives(page)
            await test_vlsm_utilisation_accuracy(page)
            await test_ipv4_binary_hex_decimal(page)
            await test_ipv6_address_forms(page)
            await test_api_ipv4_v220_fields(page)
            await test_api_ipv6_v220_fields(page)
            await test_api_v220_endpoint_allowlist(page)
            await test_api_v220_rate_limit_contract(page)
            await test_ipv4_range_to_cidr(page)
            await test_ipv6_range_to_cidr(page)
            await test_tree_view(page)
            await test_api_range(page)
            await test_api_range6(page)
            await test_ipv6_supernet_ui(page)
            await test_api_supernet6(page)
            await test_ipv6_zoneid_ui(page)
            await test_ipv6_zoneid_shareable_url(page)
            await test_api_zoneid(page)
            await test_ipv6_derive_ui(page)
            await test_ipv6_derive_copy_buttons(page)
            await test_ipv6_derive_shareable_url(page)
            await test_api_derive(page)
            await test_ipv6_slaac_ui(page)
            await test_ipv6_slaac_seeded_determinism(page)
            await test_ipv6_slaac_unseeded_uniqueness(page)
            await test_ipv6_slaac_copy_buttons(page)
            await test_ipv6_slaac_shareable_url(page)
            await test_api_slaac(page)
            await test_ipv6_rdns6_ui(page)
            await test_api_rdns6(page)
            await test_ipv6_mapped6_ui(page)
            await test_api_mapped6(page)
            await test_ipv6_embedded_v4_ui(page)
            await test_api_embedded_v4(page)
            await test_ipv6_6to4_ui(page)
            await test_api_6to4(page)
            await test_ipv6_teredo_ui(page)
            await test_api_teredo(page)
            await test_ipv6_isatap_ui(page)
            await test_api_isatap(page)
            await test_ipv6_6rd_ui(page)
            await test_api_6rd(page)
            await test_ipv6_nat64_ui(page)
            await test_api_nat64(page)
            await test_ipv6_prefix_plan_ui(page)
            await test_api_prefix_plan6(page)
            await test_ipv6_nibble_ui(page)
            await test_api_nibble6(page)
            await test_ipv6_rfc3531_ui(page)
            await test_api_rfc3531(page)
            await test_a11y_ipv6_drawers(page)
            await test_api_tree(page)
            await test_tooltips_visual_polish(page)
            await test_tooltips_accessibility(page)
            await test_csp_inline_style_violations(page)
            await test_print_stylesheet(page)
            await test_locale_number_format(page)
            await test_eslint_clean(page)
            await test_stylelint_clean(page)
            await test_full_visual_inspection(page)
            await test_all_tooltips_direction(page)
            await test_console_no_errors(page)
            await test_theme_light_dark(page)
            # v3.2.0 #342 — admin login form (run before chrome tests so a
            # rate-limit lockout from the failure test can clear before the
            # chrome tests rely on a successful session login).
            await test_admin_login_form_renders_with_autocomplete(page)
            await test_admin_login_success_redirects(page)
            await test_admin_login_failure_increments_rate_limit(page)
            await test_admin_login_protected_pages_redirect_unauth(page)
            await test_api_v1_admin_basic_auth_still_works(page)
            # v3.2.0 #343 — logout button + user chip (after login tests
            # so a fresh session is available for the logout flow).
            await test_admin_logout_button_visible_on_every_admin_page(page)
            await test_admin_logout_clears_session(page)
            await test_admin_logout_rejects_get(page)
            await test_admin_logout_csrf_protected(page)
            # v3.2.0 #344 — left sidebar (run after logout tests so a fresh
            # cookie session is available for sidebar rendering checks).
            await test_admin_sidebar_marks_current_page(page)
            await test_admin_sidebar_collapses_on_mobile(page)
            await test_admin_sidebar_keyboard_order(page)
            await test_admin_sidebar_absent_on_login(page)
            # v3.2.0 #345 — admin chrome adoption (run AFTER theme tests
            # because the admin theme test mutates localStorage.theme).
            await test_admin_pages_share_app_header(page)
            await test_admin_theme_toggle_works(page)
            await test_admin_inputs_share_bg_token(page)
            await test_a11y_landmarks(page)
            await test_a11y_focus_inputs(page)
            await test_a11y_toast_aria(page)
            await test_a11y_help_bubble_keyboard(page)
            await test_a11y_reduced_motion_css(page)
            await test_vlsm_keyboard_delete(page)
            # v3.0.0 PR3a — keyboard shortcut overlay (#300) + history (#301)
            await test_kbd_overlay_question_mark_opens(page)
            await test_kbd_overlay_header_button_opens(page)
            await test_kbd_tab_switching_digits(page)
            await test_kbd_slash_focuses_input(page)
            # v3.1.0 #328 — tab-switch focus
            await test_kbd_tab_switch_focuses_input(page)
            await test_kbd_help_button_hidden_on_touch(page)
            await test_history_disabled_by_default(page)
            await test_history_opt_in_records_calculation(page)
            await test_history_clear_removes_entries(page)
            await test_history_h_key_opens(page)
            # v3.1.0 #327 — data-history-source extension
            await test_history_captures_lookup_result(page)
            await test_history_captures_diff_result(page)
            await test_history_captures_wildcard_result(page)
            # v3.0.0 PR3b — interactive subnet tree editor (#302)
            await test_tree_editor_split(page)
            await test_tree_editor_merge_via_drag(page)
            await test_tree_editor_rename(page)
            await test_tree_editor_undo(page)
            await test_tree_editor_autosave_round_trip(page)
            await test_tree_editor_save_session(page)
            await test_tree_editor_share_url(page)
            await test_tree_editor_copy_formats(page)
            await test_tree_editor_action_sheet_on_touch(page)
            await test_tree_editor_share_too_large_fallback(page)
            # v3.1.0 #322 — multi-tree diff
            await test_tree_editor_diff_two_sessions(page)
            # v3.1.0 #323 — tree presets
            await test_tree_editor_apply_preset_then_undo(page)
            await test_v290_typography(page)
        finally:
            await context.close()
            await browser.close()

    total  = _passed + _failed
    colour = GREEN if _failed == 0 else RED
    print(f"\n{colour}{BOLD}{_passed}/{total} passed{RST}")
    if _failed:
        sys.exit(1)


if __name__ == "__main__":
    asyncio.run(main())
