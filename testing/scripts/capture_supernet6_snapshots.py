"""
One-off snapshot capture for the v3.3.0 IPv6 Supernet drawer (and the range6
drawer snapshots deferred from PR #370).

Spins up `php -S localhost:8345 -t Subnet-Calculator/`, drives a headless
Chromium via Playwright, and writes six PNGs into testing/snapshots/.
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
PORT = 8345
BASE = f"http://{HOST}:{PORT}/"


async def _wait_app_ready(page) -> None:
    await page.wait_for_selector('button.tab-btn[data-tab="ipv6"]', timeout=5000)


async def _open_ipv6_tab(page) -> None:
    # Click whichever IPv6 tab trigger exists.
    btn = await page.query_selector('button.tab-btn[data-tab="ipv6"]')
    assert btn is not None, "IPv6 tab button not found"
    await btn.click()
    await page.wait_for_timeout(150)


async def _open_drawer_panel(page, tool: str) -> None:
    # Drawer trigger lives in IPv6 tab.
    sel = f'button.tool-trigger[data-tool="{tool}"]'
    await page.wait_for_selector(sel, state="visible", timeout=3000)
    await page.click(sel)
    await page.wait_for_timeout(250)


async def _close_drawer(page) -> None:
    # Click any expanded trigger again, or click outside.
    expanded = await page.query_selector('button.tool-trigger[aria-expanded="true"]')
    if expanded is not None:
        await expanded.click()
        await page.wait_for_timeout(150)


async def capture_state(page, *, tool: str, state: str, name: str) -> None:
    """state: closed | open | error"""
    if state == "closed":
        await page.goto(BASE)
        await _wait_app_ready(page)
        await _open_ipv6_tab(page)
        # Scroll the drawer trigger row into view.
        await page.evaluate("document.querySelector('.tool-drawer:not([hidden]) .tool-trigger-row')?.scrollIntoView({block:'center'})")
        await page.wait_for_timeout(150)
    elif state == "open":
        await page.goto(BASE)
        await _wait_app_ready(page)
        await _open_ipv6_tab(page)
        await _open_drawer_panel(page, tool)
        await page.evaluate(
            f"document.querySelector('.tool-panel[data-tool=\"{tool}\"]')?.scrollIntoView({{block:'center'}})"
        )
        await page.wait_for_timeout(150)
    elif state == "error":
        # Submit shareable URL with deliberately invalid input to surface the error band.
        if tool == "supernet6":
            url = (
                BASE
                + "?tab=ipv6&tool=supernet6&supernet6_action=find&supernet6_input=not-a-cidr"
            )
        else:  # range6
            url = (
                BASE
                + "?tab=ipv6&tool=range6&range6_start=not-an-ip&range6_end=also-not"
            )
        await page.goto(url)
        await _wait_app_ready(page)
        # Tab/tool should auto-open via shareable-URL hydration. Force panel visible.
        await _open_ipv6_tab(page)
        try:
            await page.wait_for_selector(
                f'.tool-panel[data-tool="{tool}"] .error', timeout=3000
            )
        except Exception:
            # If error didn't surface (e.g. server-side cap), open the panel manually.
            await _open_drawer_panel(page, tool)
        await page.evaluate(
            f"document.querySelector('.tool-panel[data-tool=\"{tool}\"]')?.scrollIntoView({{block:'center'}})"
        )
        await page.wait_for_timeout(150)
    else:
        raise ValueError(state)

    await capture_snapshot(page, name)
    print(f"  captured: {name}")


async def main() -> int:
    # Boot PHP dev server.
    server = subprocess.Popen(
        ["php", "-S", f"{HOST}:{PORT}", "-t", str(DOCROOT)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        cwd=str(ROOT),
    )
    try:
        # Wait briefly for server (TCP connect probe — no HTTP fetch needed).
        for _ in range(50):
            if server.poll() is not None:
                print(
                    f"PHP dev server exited early (code={server.returncode}); "
                    f"port {PORT} may already be in use",
                    file=sys.stderr,
                )
                return 1
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

            print("supernet6:")
            await capture_state(page, tool="supernet6", state="closed", name="ipv6-supernet-closed")
            await capture_state(page, tool="supernet6", state="open", name="ipv6-supernet-open")
            await capture_state(page, tool="supernet6", state="error", name="ipv6-supernet-error")

            print("range6:")
            await capture_state(page, tool="range6", state="closed", name="ipv6-range-closed")
            await capture_state(page, tool="range6", state="open", name="ipv6-range-open")
            await capture_state(page, tool="range6", state="error", name="ipv6-range-error")

            await browser.close()
        return 0
    finally:
        server.terminate()
        try:
            server.wait(timeout=2)
        except subprocess.TimeoutExpired:
            server.kill()


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
