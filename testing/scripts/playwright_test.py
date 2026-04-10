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

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

BASIC_USER = os.environ.get("IPAM_BASIC_USER", "")
BASIC_PASS = os.environ.get("IPAM_BASIC_PASS", "")
APP_URL    = "https://dev-direct.seanmousseau.com:8343/claude/subnet-calculator/"

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

_APP_BASE = "https://dev-direct.seanmousseau.com:8343/claude/subnet-calculator/"
_SESSION  = _requests.Session()
_SESSION.auth    = (BASIC_USER, BASIC_PASS)
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
    return await page.evaluate("""(label) => {
        var rows = document.querySelectorAll('.result-row');
        for (var i = 0; i < rows.length; i++) {
            var l = rows[i].querySelector('.result-label');
            if (l && l.textContent.trim() === label) {
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
    return await frame.evaluate("""(label) => {
        var rows = document.querySelectorAll('.result-row');
        for (var i = 0; i < rows.length; i++) {
            var l = rows[i].querySelector('.result-label');
            if (l && l.textContent.trim() === label) {
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

    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, ".splitter-form")

    count = await page.evaluate("document.querySelectorAll('.split-item').length.toString()")
    assert_eq("split /24→/26 gives 4 subnets",     count, "4")
    assert_eq("first subnet is 192.168.0.0/26",    await page.locator(".split-item").first.inner_text(), "192.168.0.0/26")

    # Splitter rejects prefix not larger than current
    await navigate(page, APP_URL)
    await page.fill("#ip",   "10.0.0.0")
    await page.fill("#mask", "24")
    await submit_form(page, "#panel-ipv4 form")
    await page.fill("input[name='split_prefix']", "/24")
    await submit_form(page, ".splitter-form")
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

    await page.fill("input[name='split_prefix6']", "/33")
    await submit_form(page, ".splitter-form")

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
    await page.fill("input[name='overlap_cidr_a']", "10.0.0.0/24")
    await page.fill("input[name='overlap_cidr_b']", "10.0.1.0/24")
    await submit_form(page, ".overlap-form")
    result = await page.text_content(".overlap-result") or ""
    assert_contains("no overlap detected", result.lower(), "no overlap")

    # a contains b
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
    await page.fill("input[name='overlap_cidr_a']", "10.0.0.0/23")
    await page.fill("input[name='overlap_cidr_b']", "10.0.0.0/24")
    await submit_form(page, ".overlap-form")
    result2 = await page.text_content(".overlap-result") or ""
    assert_contains("a contains b", result2.lower(), "contains")

    # Identical
    await navigate(page, APP_URL)
    await page.click("#tab-vlsm")
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
    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, ".splitter-form")

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
    await page.fill("input[name='split_prefix']", "/26")
    await submit_form(page, ".splitter-form")

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
# Main
# ---------------------------------------------------------------------------

async def main() -> None:
    if not BASIC_USER or not BASIC_PASS:
        print(f"{RED}ERROR: IPAM_BASIC_USER / IPAM_BASIC_PASS not set.{RST}")
        print("Run: bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/playwright_test.py'")
        sys.exit(1)

    print(f"{BOLD}Subnet Calculator — Playwright browser tests{RST}")
    print(f"{DIM}Target: {APP_URL}{RST}")

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        context = await browser.new_context(
            http_credentials={"username": BASIC_USER, "password": BASIC_PASS},
            ignore_https_errors=True,
        )
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
