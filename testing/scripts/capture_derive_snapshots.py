"""
One-off snapshot capture for the v3.3.0 IPv6 MAC-derivation drawer.

Spins up `php -S localhost:8347 -t Subnet-Calculator/`, drives a headless
Chromium via Playwright, and writes five PNGs into testing/snapshots/.
"""

import asyncio
import socket
import subprocess
import sys
import time
from pathlib import Path

from playwright.async_api import async_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))
from snapshot_utils import capture_snapshot  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
DOCROOT = ROOT / "Subnet-Calculator"
HOST = "127.0.0.1"
PORT = 8347
BASE = f"http://{HOST}:{PORT}/"


async def _wait_app_ready(page) -> None:
    await page.wait_for_selector('button.tab-btn[data-tab="ipv6"]', timeout=5000)


async def _open_ipv6_tab(page) -> None:
    btn = await page.query_selector('button.tab-btn[data-tab="ipv6"]')
    assert btn is not None, "IPv6 tab button not found"
    await btn.click()
    await page.wait_for_timeout(150)


async def _open_drawer_panel(page, tool: str) -> None:
    sel = f'button.tool-trigger[data-tool="{tool}"]'
    await page.wait_for_selector(sel, state="visible", timeout=3000)
    await page.click(sel)
    await page.wait_for_timeout(250)


async def _scroll_panel(page, tool: str) -> None:
    await page.evaluate(
        f"document.querySelector('.tool-panel[data-tool=\"{tool}\"]')?.scrollIntoView({{block:'center'}})"
    )
    await page.wait_for_timeout(150)


async def capture_state(page, *, state: str, name: str) -> None:
    """state: closed | open | success | warning | error"""
    if state == "closed":
        await page.goto(BASE)
        await _wait_app_ready(page)
        await _open_ipv6_tab(page)
        await page.evaluate(
            "document.querySelector('.tool-toolbar')?.scrollIntoView({block:'center'})"
        )
        await page.wait_for_timeout(150)
    elif state == "open":
        await page.goto(BASE)
        await _wait_app_ready(page)
        await _open_ipv6_tab(page)
        await _open_drawer_panel(page, "derive")
        await _scroll_panel(page, "derive")
    elif state == "success":
        await page.goto(BASE + "?tab=ipv6&derive_mac=00%3A24%3Ab9%3A7e%3Aab%3Acd")
        await _wait_app_ready(page)
        await page.wait_for_selector(".tool-panel[data-tool='derive'].active", timeout=3000)
        await _scroll_panel(page, "derive")
    elif state == "warning":
        await page.goto(BASE + "?tab=ipv6&derive_mac=01%3A00%3A5e%3A00%3A00%3A01")
        await _wait_app_ready(page)
        await page.wait_for_selector(".tool-panel[data-tool='derive'].active", timeout=3000)
        await _scroll_panel(page, "derive")
    elif state == "error":
        await page.goto(BASE + "?tab=ipv6&derive_mac=not-a-mac")
        await _wait_app_ready(page)
        await page.wait_for_selector(".tool-panel[data-tool='derive'].active", timeout=3000)
        await _scroll_panel(page, "derive")
    else:
        raise ValueError(state)

    await capture_snapshot(page, name)
    print(f"  captured: {name}")


async def main() -> int:
    server = subprocess.Popen(
        ["php", "-S", f"{HOST}:{PORT}", "-t", str(DOCROOT)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        cwd=str(ROOT),
    )
    try:
        for _ in range(50):
            try:
                with socket.create_connection((HOST, PORT), timeout=0.3):
                    break
            except OSError:
                time.sleep(0.1)
        else:
            print("PHP dev server did not start in time", file=sys.stderr)
            return 1

        async with async_playwright() as p:
            browser = await p.chromium.launch(headless=True)
            context = await browser.new_context(viewport={"width": 1280, "height": 900})
            page = await context.new_page()

            print("derive:")
            await capture_state(page, state="closed",  name="ipv6-derive-closed")
            await capture_state(page, state="open",    name="ipv6-derive-open")
            await capture_state(page, state="success", name="ipv6-derive-success")
            await capture_state(page, state="warning", name="ipv6-derive-warning")
            await capture_state(page, state="error",   name="ipv6-derive-error")

            await browser.close()
        return 0
    finally:
        server.terminate()
        try:
            server.wait(timeout=2)
        except Exception:
            server.kill()


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
