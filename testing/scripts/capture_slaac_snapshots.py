"""
One-off snapshot capture for the v3.3.0 IPv6 SLAAC privacy drawer.

Spins up `php -S localhost:8348 -t Subnet-Calculator/`, drives a headless
Chromium via Playwright, and writes six PNGs into testing/snapshots/.

States captured:
  closed        — IPv6 toolbar with no drawer open
  open          — drawer open, prefix input empty, Advanced collapsed
  advanced-open — drawer open, Advanced disclosure expanded, seed visible
  success       — unseeded result rows visible
  success-seeded — seeded result rows visible (deterministic vector)
  error         — non-/64 prefix triggers error band
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
PORT = 8348
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
        await _open_drawer_panel(page, "slaac")
        await _scroll_panel(page, "slaac")
    elif state == "advanced-open":
        await page.goto(BASE)
        await _wait_app_ready(page)
        await _open_ipv6_tab(page)
        await _open_drawer_panel(page, "slaac")
        await page.evaluate(
            "document.querySelector(\"#panel-ipv6 .tool-panel[data-tool='slaac'] details.slaac-advanced\").open = true;"
        )
        await page.wait_for_timeout(150)
        await _scroll_panel(page, "slaac")
    elif state == "success":
        # Unseeded — random output (snapshot will differ between runs but layout
        # is what matters; we only commit the baseline once and accept drift via
        # snapshot regeneration if the prefix changes).
        await page.goto(BASE + "?tab=ipv6&slaac_prefix=2001%3Adb8%3A1%3A2%3A%3A%2F64")
        await _wait_app_ready(page)
        await page.wait_for_selector(".tool-panel[data-tool='slaac'].active", timeout=3000)
        await _scroll_panel(page, "slaac")
    elif state == "success-seeded":
        await page.goto(BASE
            + "?tab=ipv6&slaac_prefix=2001%3Adb8%3A1%3A2%3A%3A%2F64&slaac_seed=a8d3f4e10c529837")
        await _wait_app_ready(page)
        await page.wait_for_selector(".tool-panel[data-tool='slaac'].active", timeout=3000)
        await _scroll_panel(page, "slaac")
    elif state == "error":
        await page.goto(BASE + "?tab=ipv6&slaac_prefix=2001%3Adb8%3A%3A%2F48")
        await _wait_app_ready(page)
        await page.wait_for_selector(".tool-panel[data-tool='slaac'].active", timeout=3000)
        await _scroll_panel(page, "slaac")
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

            print("slaac:")
            await capture_state(page, state="closed",         name="ipv6-slaac-closed")
            await capture_state(page, state="open",           name="ipv6-slaac-open")
            await capture_state(page, state="advanced-open",  name="ipv6-slaac-advanced-open")
            await capture_state(page, state="success",        name="ipv6-slaac-success")
            await capture_state(page, state="success-seeded", name="ipv6-slaac-success-seeded")
            await capture_state(page, state="error",          name="ipv6-slaac-error")

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
