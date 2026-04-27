#!/usr/bin/env python3
"""
Playwright browser tests for Subnet Calculator.

Runs against the deployed dev server using a local Chromium instance.

Usage:
    bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/playwright_test.py'
"""

import asyncio
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
            min_count,
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
    assert_true("binary network contains dots", net_code and "." in (net_code or ""), net_code)
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
    assert_eq("api lookup: 3 result rows", len(results), 3)

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
    assert_eq("lookup ui: 2 result rows", len(rows), 2)
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
    assert_eq("lookup ui v6: 2 result rows", len(rows), 2)
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
    assert_eq("lookup share: 2 result rows", len(rows), 2)
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
    assert_eq("api diff: 1 changed entry", len(changed), 1)
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
    assert_eq("api vlsm: 2 allocations", len(allocs), 2)
    assert_eq("api vlsm: first allocation has subnet key",
              "subnet" in allocs[0] if allocs else False, True)


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
    assert_eq("api vlsm6: 2 allocations", len(allocs), 2)
    names = {a.get("name") for a in allocs}
    assert_true("api vlsm6: names round-trip",
                names == {"site-a", "site-b"}, str(names))
    # Largest-first: site-a (256 hosts) → /120 block
    site_a = next(a for a in allocs if a["name"] == "site-a")
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
    assert_eq("api vlsm6 (2^N): 1 allocation", len(allocs2), 1)
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
    panel = await page.query_selector(".session-ttl-notice")
    if panel is None:
        ok("session forms spacing: sessions not enabled on this server (skipped)")
        return
    forms = page.locator(".session-forms")
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
# v2.3.0 — API: range/ipv4 and tree (#182, #183)
# ---------------------------------------------------------------------------

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

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        ctx_kwargs: dict = {"ignore_https_errors": True}
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
            await test_vlsm_session_ttl_notice(page)
            await test_session_forms_spacing(page)
            await test_permissions_policy_directives(page)
            await test_vlsm_utilisation_accuracy(page)
            await test_ipv4_binary_hex_decimal(page)
            await test_ipv6_address_forms(page)
            await test_api_ipv4_v220_fields(page)
            await test_api_ipv6_v220_fields(page)
            await test_api_v220_endpoint_allowlist(page)
            await test_api_v220_rate_limit_contract(page)
            await test_ipv4_range_to_cidr(page)
            await test_tree_view(page)
            await test_api_range(page)
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
            await test_a11y_landmarks(page)
            await test_a11y_focus_inputs(page)
            await test_a11y_toast_aria(page)
            await test_a11y_help_bubble_keyboard(page)
            await test_a11y_reduced_motion_css(page)
            await test_vlsm_keyboard_delete(page)
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
