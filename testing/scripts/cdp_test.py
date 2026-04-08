#!/usr/bin/env python3
"""
CDP browser tests for Subnet Calculator.

Usage:
    bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/cdp_test.py'
"""

import asyncio
import base64
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.request
import websockets

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

CHROME_HOST = "192.168.80.15"
CHROME_PORT = 9224
BASIC_USER  = os.environ.get("IPAM_BASIC_USER", "")
BASIC_PASS  = os.environ.get("IPAM_BASIC_PASS", "")
BASE_URL    = (
    f"https://{BASIC_USER}:{BASIC_PASS}"
    f"@dev-direct.seanmousseau.com:8343/claude/subnet-calculator/"
)

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
# CDP infrastructure
# ---------------------------------------------------------------------------

class BrowserCDP:
    """
    Routes CDP messages across both the browser session and flat tab sessions.
    All messages share one WebSocket; sessionId in the payload determines routing.
    """

    def __init__(self, ws):
        self._ws      = ws
        self._cmd_id  = 0
        self._lock    = asyncio.Lock()
        # (session_id | None, cmd_id) -> Future
        self._pending: dict[tuple, asyncio.Future] = {}
        # session_id | None -> Queue[dict]
        self._events: dict[str | None, asyncio.Queue] = {None: asyncio.Queue()}
        self._task: asyncio.Task | None = None

    async def start(self) -> None:
        self._task = asyncio.create_task(self._recv_loop())

    async def stop(self) -> None:
        if self._task:
            self._task.cancel()
            try:
                await self._task
            except asyncio.CancelledError:
                pass

    async def _recv_loop(self) -> None:
        try:
            async for raw in self._ws:
                msg  = json.loads(raw)
                sid  = msg.get("sessionId")          # None for browser-level
                if "id" in msg:
                    fut = self._pending.pop((sid, msg["id"]), None)
                    if fut and not fut.done():
                        fut.set_result(msg)
                elif "method" in msg:
                    q = self._events.setdefault(sid, asyncio.Queue())
                    await q.put(msg)
        except Exception:
            pass

    async def _send(self, session_id: str | None, method: str,
                    params: dict | None, timeout: float) -> dict:
        async with self._lock:
            self._cmd_id += 1
            cmd_id = self._cmd_id

        loop   = asyncio.get_event_loop()
        fut: asyncio.Future = loop.create_future()
        self._pending[(session_id, cmd_id)] = fut

        payload: dict = {"id": cmd_id, "method": method}
        if session_id:
            payload["sessionId"] = session_id
        if params:
            payload["params"] = params
        await self._ws.send(json.dumps(payload))
        return await asyncio.wait_for(fut, timeout=timeout)

    def browser_session(self) -> "CDPSession":
        return CDPSession(self, None)

    def tab_session(self, session_id: str) -> "CDPSession":
        self._events.setdefault(session_id, asyncio.Queue())
        return CDPSession(self, session_id)


class CDPSession:
    """A view of BrowserCDP scoped to one session (or the browser itself)."""

    def __init__(self, browser: BrowserCDP, session_id: str | None):
        self._b   = browser
        self._sid = session_id

    async def call(self, method: str, params: dict | None = None,
                   timeout: float = 15) -> dict:
        return await self._b._send(self._sid, method, params, timeout)

    async def wait_for_event(self, event_name: str, timeout: float = 15) -> dict:
        q     = self._b._events.setdefault(self._sid, asyncio.Queue())
        stash: list[dict] = []
        deadline = asyncio.get_event_loop().time() + timeout
        try:
            while True:
                remaining = deadline - asyncio.get_event_loop().time()
                if remaining <= 0:
                    raise TimeoutError(f"Timed out waiting for {event_name}")
                event = await asyncio.wait_for(q.get(), timeout=remaining)
                if event.get("method") == event_name:
                    return event
                stash.append(event)
        finally:
            for e in stash:
                await q.put(e)

    # ------------------------------------------------------------------
    # Navigation
    # ------------------------------------------------------------------

    async def navigate(self, url: str, timeout: float = 15) -> None:
        await self.call("Page.enable")
        await self.call("Page.navigate", {"url": url})
        await self.wait_for_event("Page.loadEventFired", timeout=timeout)
        await asyncio.sleep(0.2)   # let inline JS settle

    # ------------------------------------------------------------------
    # JS / DOM helpers
    # ------------------------------------------------------------------

    async def eval(self, expr: str, timeout: float = 5) -> dict:
        result = await self.call(
            "Runtime.evaluate",
            {"expression": expr, "returnByValue": True},
            timeout=timeout,
        )
        return result.get("result", {}).get("result", {})

    async def js_str(self, expr: str) -> str | None:
        r = await self.eval(expr)
        v = r.get("value")
        return str(v) if v is not None else None

    async def js_bool(self, expr: str) -> bool:
        r = await self.eval(expr)
        return bool(r.get("value"))

    async def text(self, selector: str) -> str | None:
        return await self.js_str(
            f"(function(){{var e=document.querySelector({json.dumps(selector)});"
            f"return e?e.textContent.trim():null}})()"
        )

    async def attr(self, selector: str, attribute: str) -> str | None:
        return await self.js_str(
            f"(function(){{var e=document.querySelector({json.dumps(selector)});"
            f"return e?e.getAttribute({json.dumps(attribute)}):null}})()"
        )

    async def exists(self, selector: str) -> bool:
        return await self.js_bool(
            f"document.querySelector({json.dumps(selector)})!==null"
        )

    async def fill(self, selector: str, value: str) -> None:
        await self.eval(
            f"document.querySelector({json.dumps(selector)}).value={json.dumps(value)}"
        )

    async def click(self, selector: str) -> None:
        await self.eval(
            f"document.querySelector({json.dumps(selector)}).click()"
        )

    async def submit_form(self, form_selector: str) -> None:
        """Submit a form and wait for the resulting page load."""
        await self.call("Page.enable")
        await self.eval(
            f"document.querySelector({json.dumps(form_selector)}).submit()"
        )
        await self.wait_for_event("Page.loadEventFired", timeout=15)
        await asyncio.sleep(0.2)

    async def result_value(self, label: str) -> str | None:
        """Return the result-value text for a given result-label."""
        return await self.js_str(
            f"""(function(){{
                var rows=document.querySelectorAll('.result-row');
                for(var i=0;i<rows.length;i++){{
                    var l=rows[i].querySelector('.result-label');
                    if(l&&l.textContent.trim()==={json.dumps(label)}){{
                        var v=rows[i].querySelector('.result-value');
                        return v?v.textContent.trim():null;
                    }}
                }}
                return null;
            }})()"""
        )


# ---------------------------------------------------------------------------
# HTTP helper (used by header / CSP tests — no CDP required)
# ---------------------------------------------------------------------------

_SSL_CTX = ssl.create_default_context()
_BASIC_HDR = "Basic " + base64.b64encode(
    f"{BASIC_USER}:{BASIC_PASS}".encode()
).decode()
_APP_BASE = "https://dev-direct.seanmousseau.com:8343/claude/subnet-calculator/"


def _http_get(path: str = "") -> tuple[int, dict[str, str], str]:
    """
    Return (status, lowercase_headers, body) for a path under the app root.
    Non-2xx responses are caught and returned as (status, {}, "").
    """
    url = _APP_BASE + path
    req = urllib.request.Request(url, headers={"Authorization": _BASIC_HDR})
    try:
        with urllib.request.urlopen(req, context=_SSL_CTX, timeout=10) as resp:
            hdrs = {k.lower(): v for k, v in resp.headers.items()}
            body = resp.read().decode("utf-8", errors="replace")
            return resp.status, hdrs, body
    except urllib.error.HTTPError as e:
        return e.code, {}, ""


# ---------------------------------------------------------------------------
# Test suites
# ---------------------------------------------------------------------------

async def test_page_load(tab: CDPSession) -> None:
    section("Page load")
    await tab.navigate(BASE_URL)

    title = await tab.text("title")
    assert_contains("page title contains 'Subnet Calculator'", title or "", "Subnet Calculator")
    assert_true("IPv4 tab exists", await tab.exists("#tab-ipv4"))
    assert_true("IPv6 tab exists", await tab.exists("#tab-ipv6"))
    assert_eq("IPv4 tab active by default", await tab.attr("#tab-ipv4", "aria-selected"), "true")
    assert_true("logo present", await tab.exists("img.logo"))


async def test_headers_and_csp(_tab: CDPSession) -> None:
    # ── main page response headers ─────────────────────────────────────────
    section("security headers — main page")
    status, hdrs, body = _http_get()

    assert_eq("HTTP 200",                          status, 200)
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
        """Extract the value of a CSP directive."""
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

    try:
        base64.b64decode(nonce)
        ok("nonce is valid base64")
    except Exception as exc:
        fail("nonce is valid base64", str(exc))

    assert_contains("nonce on inline <script> in HTML",
                    body, f'nonce="{nonce}"')

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


async def test_ipv4_basic(tab: CDPSession) -> None:
    section("IPv4 — basic calculation (192.168.1.0/24)")
    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "192.168.1.0")
    await tab.fill("#mask", "24")
    await tab.submit_form("#panel-ipv4 form")

    assert_eq("Subnet (CIDR)",   await tab.result_value("Subnet (CIDR)"),   "192.168.1.0/24")
    assert_eq("Netmask (CIDR)",  await tab.result_value("Netmask (CIDR)"),  "/24")
    assert_eq("Netmask (Octet)", await tab.result_value("Netmask (Octet)"), "255.255.255.0")
    assert_eq("Wildcard Mask",   await tab.result_value("Wildcard Mask"),   "0.0.0.255")
    assert_eq("First Usable IP", await tab.result_value("First Usable IP"), "192.168.1.1")
    assert_eq("Last Usable IP",  await tab.result_value("Last Usable IP"),  "192.168.1.254")
    assert_eq("Broadcast IP",    await tab.result_value("Broadcast IP"),    "192.168.1.255")
    assert_eq("Usable IPs",      await tab.result_value("Usable IPs"),      "254")
    assert_eq("Address type: Private", await tab.text(".badge"), "Private")

    share = await tab.text(".share-url")
    assert_true("share URL present", share and "192.168.1.0" in share)


async def test_ipv4_dotted_mask(tab: CDPSession) -> None:
    section("IPv4 — dotted-decimal netmask")
    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "10.0.0.0")
    await tab.fill("#mask", "255.0.0.0")
    await tab.submit_form("#panel-ipv4 form")

    assert_eq("Subnet (CIDR) with octet mask", await tab.result_value("Subnet (CIDR)"), "10.0.0.0/8")
    assert_eq("Usable IPs /8", await tab.result_value("Usable IPs"), "16,777,214")


async def test_ipv4_cidr_paste(tab: CDPSession) -> None:
    section("IPv4 — CIDR paste into IP field")
    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "172.16.0.0/12")
    await tab.fill("#mask", "")
    await tab.submit_form("#panel-ipv4 form")

    assert_eq("CIDR paste: subnet", await tab.result_value("Subnet (CIDR)"), "172.16.0.0/12")


async def test_ipv4_edge_cases(tab: CDPSession) -> None:
    section("IPv4 — edge cases")

    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "1.2.3.4")
    await tab.fill("#mask", "32")
    await tab.submit_form("#panel-ipv4 form")
    assert_eq("/32 usable IPs",    await tab.result_value("Usable IPs"),   "1")
    assert_eq("/32 broadcast",     await tab.result_value("Broadcast IP"), "1.2.3.4")

    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "10.0.0.0")
    await tab.fill("#mask", "31")
    await tab.submit_form("#panel-ipv4 form")
    assert_eq("/31 usable IPs",    await tab.result_value("Usable IPs"),   "2")

    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "0.0.0.0")
    await tab.fill("#mask", "0")
    await tab.submit_form("#panel-ipv4 form")
    assert_eq("/0 subnet",         await tab.result_value("Subnet (CIDR)"), "0.0.0.0/0")


async def test_ipv4_address_types(tab: CDPSession) -> None:
    section("IPv4 — address type badges")
    cases = [
        ("127.0.0.1",   "24", "Loopback"),
        ("169.254.0.0", "16", "Link-local"),
        ("224.0.0.1",   "4",  "Multicast"),
        ("8.8.8.8",     "32", "Public"),
        ("100.64.0.0",  "10", "CGNAT"),
    ]
    for ip, mask, expected in cases:
        await tab.navigate(BASE_URL)
        await tab.fill("#ip",   ip)
        await tab.fill("#mask", mask)
        await tab.submit_form("#panel-ipv4 form")
        assert_eq(f"{ip} → {expected}", await tab.text(".badge"), expected)


async def test_ipv4_errors(tab: CDPSession) -> None:
    section("IPv4 — error handling")

    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "999.999.999.999")
    await tab.fill("#mask", "24")
    await tab.submit_form("#panel-ipv4 form")
    err = await tab.text(".error")
    assert_true("invalid IP shows error",    err and len(err) > 0, str(err))
    assert_true("no results on invalid IP",  not await tab.exists(".results"))

    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "192.168.1.0")
    await tab.fill("#mask", "33")
    await tab.submit_form("#panel-ipv4 form")
    err = await tab.text(".error")
    assert_true("mask > 32 shows error",     err and len(err) > 0, str(err))


async def test_ipv4_splitter(tab: CDPSession) -> None:
    section("IPv4 — subnet splitter")

    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "192.168.0.0")
    await tab.fill("#mask", "24")
    await tab.submit_form("#panel-ipv4 form")

    await tab.fill("input[name='split_prefix']", "/26")
    await tab.submit_form(".splitter-form")

    count = await tab.js_str("document.querySelectorAll('.split-item').length.toString()")
    assert_eq("split /24→/26 gives 4 subnets",     count, "4")
    assert_eq("first subnet is 192.168.0.0/26",    await tab.text(".split-item"), "192.168.0.0/26")

    # Splitter rejects prefix not larger than current
    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "10.0.0.0")
    await tab.fill("#mask", "24")
    await tab.submit_form("#panel-ipv4 form")
    await tab.fill("input[name='split_prefix']", "/24")
    await tab.submit_form(".splitter-form")
    err = await tab.text(".error")
    assert_true("splitter rejects same-size prefix", err and "larger" in err.lower(), str(err))


async def test_ipv4_shareable_url(tab: CDPSession) -> None:
    section("IPv4 — shareable GET URL")
    await tab.navigate(BASE_URL + "?tab=ipv4&ip=10.10.10.0&mask=28")

    assert_eq("GET auto-calc: subnet", await tab.result_value("Subnet (CIDR)"), "10.10.10.0/28")
    assert_eq("GET auto-calc: usable", await tab.result_value("Usable IPs"),    "14")


async def test_ipv4_reset(tab: CDPSession) -> None:
    section("IPv4 — reset button")
    await tab.navigate(BASE_URL)
    await tab.fill("#ip",   "192.168.1.0")
    await tab.fill("#mask", "24")
    await tab.submit_form("#panel-ipv4 form")
    assert_true("results shown before reset", await tab.exists(".results"))

    await tab.call("Page.enable")
    await tab.click("a.reset")
    await tab.wait_for_event("Page.loadEventFired", timeout=10)
    await asyncio.sleep(0.2)

    assert_true("results gone after reset", not await tab.exists(".results"))
    assert_eq("IP field cleared", await tab.js_str("document.querySelector('#ip').value"), "")


async def test_ipv6_basic(tab: CDPSession) -> None:
    section("IPv6 — basic calculation (2001:db8::/32)")
    await tab.navigate(BASE_URL)
    await tab.click("#tab-ipv6")
    await asyncio.sleep(0.1)
    await tab.fill("#ipv6",   "2001:db8::")
    await tab.fill("#prefix", "32")
    await tab.submit_form("#panel-ipv6 form")

    network = await tab.result_value("Network (CIDR)") or ""
    assert_contains("Network CIDR contains 2001:db8::", network, "2001:db8::")
    assert_eq("Prefix Length",            await tab.result_value("Prefix Length"), "/32")

    total = await tab.result_value("Total Addresses") or ""
    assert_true("Total Addresses is 2^96", "2^96" in total, total)
    assert_eq("Address type: Documentation", await tab.text(".badge"), "Documentation")


async def test_ipv6_types(tab: CDPSession) -> None:
    section("IPv6 — address type badges")
    cases = [
        ("::1",         "128", "Loopback"),
        ("fe80::1",     "64",  "Link-local"),
        ("fc00::1",     "48",  "Unique Local"),
        ("ff02::1",     "128", "Multicast"),
        ("2001:db8::1", "128", "Documentation"),
    ]
    for ip, prefix, expected in cases:
        await tab.navigate(BASE_URL)
        await tab.click("#tab-ipv6")
        await asyncio.sleep(0.1)
        await tab.fill("#ipv6",   ip)
        await tab.fill("#prefix", prefix)
        await tab.submit_form("#panel-ipv6 form")
        assert_eq(f"{ip} → {expected}", await tab.text(".badge"), expected)


async def test_ipv6_cidr_paste(tab: CDPSession) -> None:
    section("IPv6 — CIDR paste into address field")
    await tab.navigate(BASE_URL)
    await tab.click("#tab-ipv6")
    await asyncio.sleep(0.1)
    await tab.fill("#ipv6",   "2001:db8::/48")
    await tab.fill("#prefix", "")
    await tab.submit_form("#panel-ipv6 form")
    assert_eq("CIDR paste: prefix length", await tab.result_value("Prefix Length"), "/48")


async def test_ipv6_errors(tab: CDPSession) -> None:
    section("IPv6 — error handling")

    await tab.navigate(BASE_URL)
    await tab.click("#tab-ipv6")
    await asyncio.sleep(0.1)
    await tab.fill("#ipv6",   "not-an-ipv6")
    await tab.fill("#prefix", "64")
    await tab.submit_form("#panel-ipv6 form")
    err = await tab.text(".error")
    assert_true("invalid IPv6 shows error",  err and len(err) > 0, str(err))

    await tab.navigate(BASE_URL)
    await tab.click("#tab-ipv6")
    await asyncio.sleep(0.1)
    await tab.fill("#ipv6",   "2001:db8::1")
    await tab.fill("#prefix", "129")
    await tab.submit_form("#panel-ipv6 form")
    err = await tab.text(".error")
    assert_true("prefix > 128 shows error", err and len(err) > 0, str(err))


async def test_ipv6_splitter(tab: CDPSession) -> None:
    section("IPv6 — subnet splitter")
    await tab.navigate(BASE_URL)
    await tab.click("#tab-ipv6")
    await asyncio.sleep(0.1)
    await tab.fill("#ipv6",   "2001:db8::")
    await tab.fill("#prefix", "32")
    await tab.submit_form("#panel-ipv6 form")

    await tab.fill("input[name='split_prefix6']", "/33")
    await tab.submit_form(".splitter-form")

    count = await tab.js_str("document.querySelectorAll('.split-item').length.toString()")
    assert_eq("split /32→/33 gives 2 subnets", count, "2")


async def test_ipv6_shareable_url(tab: CDPSession) -> None:
    section("IPv6 — shareable GET URL")
    await tab.navigate(BASE_URL + "?tab=ipv6&ipv6=fd00%3A%3A&prefix=8")
    assert_eq("GET auto-calc IPv6: prefix length", await tab.result_value("Prefix Length"), "/8")


async def _poll_resize_count(tab: CDPSession, min_count: int,
                             timeout: float = 5.0) -> int:
    """Return when resize-log data-count >= min_count, else return whatever we got."""
    deadline = asyncio.get_event_loop().time() + timeout
    while asyncio.get_event_loop().time() < deadline:
        raw = await tab.js_str(
            "document.getElementById('resize-log').getAttribute('data-count')"
        )
        count = int(raw or "0")
        if count >= min_count:
            return count
        await asyncio.sleep(0.2)
    raw = await tab.js_str(
        "document.getElementById('resize-log').getAttribute('data-count')"
    )
    return int(raw or "0")


async def test_iframe(tab: CDPSession) -> None:
    IFRAME_HARNESS = BASE_URL + "iframe-test.html"

    # ── setup ──────────────────────────────────────────────────────────────
    section("iframe — setup")
    await tab.navigate(IFRAME_HARNESS)
    # Wait for the embedded calculator to load and send its first sc-resize
    await _poll_resize_count(tab, 1, timeout=8.0)

    assert_true("harness page loaded", await tab.exists("#scFrame"))
    assert_true("iframe element present", await tab.exists("#scFrame"))

    # ── in-iframe detection ────────────────────────────────────────────────
    section("iframe — in-iframe detection")
    has_class = await tab.js_bool(
        "document.getElementById('scFrame').contentDocument"
        ".documentElement.classList.contains('in-iframe')"
    )
    assert_true("html has in-iframe class", has_class)

    # ── height reporting ───────────────────────────────────────────────────
    section("iframe — height reporting")
    height_str = await tab.js_str(
        "document.getElementById('resize-log').getAttribute('data-height')"
    )
    height = int(height_str or "0")
    assert_true("sc-resize received with height > 0", height > 0, f"height={height}")

    frame_h = await tab.js_str("document.getElementById('scFrame').style.height")
    assert_true("iframe element height auto-set", frame_h not in (None, "", "0px"),
                f"style.height={frame_h!r}")

    # ── background colour ──────────────────────────────────────────────────
    section("iframe — background colour (sc-set-bg)")
    sent = await tab.js_str(
        "document.getElementById('bg-log').getAttribute('data-sent')"
    )
    assert_eq("sc-set-bg forwarded to iframe on load", sent, "1")

    bg = await tab.js_str(
        "document.getElementById('scFrame').contentDocument"
        ".body.style.backgroundColor"
    )
    assert_true("sc-set-bg applied to iframe body", bg and len(bg) > 0,
                f"backgroundColor={bg!r}")

    # ── GET-based calculation inside iframe ────────────────────────────────
    section("iframe — calculation via GET URL")
    count_before = int(
        (await tab.js_str(
            "document.getElementById('resize-log').getAttribute('data-count')"
        )) or "0"
    )
    # Navigate the iframe to a shareable URL
    await tab.eval(
        "document.getElementById('scFrame').src = './?tab=ipv4&ip=10.0.0.0&mask=8'"
    )
    await _poll_resize_count(tab, count_before + 1, timeout=8.0)

    subnet_in_iframe = await tab.js_str(
        """(function(){
            var doc = document.getElementById('scFrame').contentDocument;
            var rows = doc.querySelectorAll('.result-row');
            for (var i = 0; i < rows.length; i++) {
                var l = rows[i].querySelector('.result-label');
                if (l && l.textContent.trim() === 'Subnet (CIDR)') {
                    var v = rows[i].querySelector('.result-value');
                    return v ? v.textContent.trim() : null;
                }
            }
            return null;
        })()"""
    )
    assert_eq("IPv4 GET calc renders in iframe", subnet_in_iframe, "10.0.0.0/8")

    count_after_get = int(
        (await tab.js_str(
            "document.getElementById('resize-log').getAttribute('data-count')"
        )) or "0"
    )
    assert_true("sc-resize fires after GET calculation",
                count_after_get > count_before, f"count {count_before}→{count_after_get}")

    # ── form submission inside iframe ──────────────────────────────────────
    section("iframe — form submission")
    count_before_submit = count_after_get
    iframe_doc = "document.getElementById('scFrame').contentDocument"
    await tab.eval(f"{iframe_doc}.querySelector('#ip').value = '192.168.50.0'")
    await tab.eval(f"{iframe_doc}.querySelector('#mask').value = '26'")
    await tab.eval(f"{iframe_doc}.querySelector('#panel-ipv4 form').submit()")
    await _poll_resize_count(tab, count_before_submit + 1, timeout=8.0)

    usable = await tab.js_str(
        """(function(){
            var doc = document.getElementById('scFrame').contentDocument;
            var rows = doc.querySelectorAll('.result-row');
            for (var i = 0; i < rows.length; i++) {
                var l = rows[i].querySelector('.result-label');
                if (l && l.textContent.trim() === 'Usable IPs') {
                    var v = rows[i].querySelector('.result-value');
                    return v ? v.textContent.trim() : null;
                }
            }
            return null;
        })()"""
    )
    assert_eq("form submit in iframe: results render", usable, "62")

    count_after_submit = int(
        (await tab.js_str(
            "document.getElementById('resize-log').getAttribute('data-count')"
        )) or "0"
    )
    assert_true("sc-resize fires after form submit",
                count_after_submit > count_before_submit,
                f"count {count_before_submit}→{count_after_submit}")


async def test_theme_toggle(tab: CDPSession) -> None:
    section("UI — theme toggle")
    await tab.navigate(BASE_URL)

    theme = await tab.attr("html", "data-theme")
    assert_true("default theme is dark", theme != "light", str(theme))

    await tab.click("#theme-toggle")
    await asyncio.sleep(0.1)
    assert_eq("after toggle: light mode", await tab.attr("html", "data-theme"), "light")

    await tab.click("#theme-toggle")
    await asyncio.sleep(0.1)
    theme = await tab.attr("html", "data-theme")
    assert_true("after second toggle: dark", theme != "light", str(theme))


async def test_tab_switch(tab: CDPSession) -> None:
    section("UI — tab switching")
    await tab.navigate(BASE_URL)

    await tab.click("#tab-ipv6")
    await asyncio.sleep(0.1)
    assert_eq("IPv6 tab becomes active",   await tab.attr("#tab-ipv6", "aria-selected"), "true")
    assert_eq("IPv4 tab becomes inactive", await tab.attr("#tab-ipv4", "aria-selected"), "false")

    panel_class = await tab.attr("#panel-ipv6", "class") or ""
    assert_true("IPv6 panel has active class", "active" in panel_class)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

async def main() -> None:
    if not BASIC_USER or not BASIC_PASS:
        print(f"{RED}ERROR: IPAM_BASIC_USER / IPAM_BASIC_PASS not set.{RST}")
        print("Run: bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; python3 testing/scripts/cdp_test.py'")
        sys.exit(1)

    print(f"{BOLD}Subnet Calculator — CDP browser tests{RST}")
    print(f"{DIM}Target: {BASE_URL.replace(f'{BASIC_PASS}@', '***@')}{RST}")

    with urllib.request.urlopen(
        f"http://{CHROME_HOST}:{CHROME_PORT}/json/version", timeout=5
    ) as resp:
        info = json.load(resp)
    browser_ws_url = info["webSocketDebuggerUrl"]

    async with websockets.connect(browser_ws_url, max_size=10 * 1024 * 1024) as ws:
        browser = BrowserCDP(ws)
        await browser.start()
        b = browser.browser_session()

        # Open a new tab and attach a flat CDP session to it
        target    = await b.call("Target.createTarget", {"url": "about:blank"})
        target_id = target["result"]["targetId"]
        attached  = await b.call("Target.attachToTarget",
                                 {"targetId": target_id, "flatten": True})
        session_id = attached["result"]["sessionId"]
        tab = browser.tab_session(session_id)

        try:
            await test_page_load(tab)
            await test_headers_and_csp(tab)
            await test_ipv4_basic(tab)
            await test_ipv4_dotted_mask(tab)
            await test_ipv4_cidr_paste(tab)
            await test_ipv4_edge_cases(tab)
            await test_ipv4_address_types(tab)
            await test_ipv4_errors(tab)
            await test_ipv4_splitter(tab)
            await test_ipv4_shareable_url(tab)
            await test_ipv4_reset(tab)
            await test_ipv6_basic(tab)
            await test_ipv6_types(tab)
            await test_ipv6_cidr_paste(tab)
            await test_ipv6_errors(tab)
            await test_ipv6_splitter(tab)
            await test_ipv6_shareable_url(tab)
            await test_iframe(tab)
            await test_theme_toggle(tab)
            await test_tab_switch(tab)
        finally:
            await b.call("Target.closeTarget", {"targetId": target_id})
            await browser.stop()

    total  = _passed + _failed
    colour = GREEN if _failed == 0 else RED
    print(f"\n{colour}{BOLD}{_passed}/{total} passed{RST}")
    if _failed:
        sys.exit(1)


if __name__ == "__main__":
    asyncio.run(main())
