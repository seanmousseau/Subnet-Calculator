"""
Snapshot utilities for visual regression testing.

Captures and compares page screenshots using Pillow pixel comparison.

Usage in playwright_test.py:
    from snapshot_utils import capture_snapshot, compare_snapshot

    # Capture a new baseline (when UPDATE_SNAPSHOTS=1 env var is set):
    await capture_snapshot(page, "ipv4_result")

    # Compare against existing baseline:
    ok_flag, diff_pct = await compare_snapshot(page, "ipv4_result")
"""

import io
import os
from pathlib import Path
from typing import Optional

try:
    from PIL import Image
    _PIL_AVAILABLE = True
except ImportError:
    _PIL_AVAILABLE = False

from playwright.async_api import Page

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

_SNAPSHOTS_DIR = Path(__file__).parent.parent / "snapshots"
_UPDATE = os.environ.get("UPDATE_SNAPSHOTS", "0") == "1"

# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------


async def capture_snapshot(page: Page, name: str) -> None:
    """Capture a screenshot and write it as the baseline for *name*."""
    _SNAPSHOTS_DIR.mkdir(parents=True, exist_ok=True)
    dest = _SNAPSHOTS_DIR / f"{name}.png"
    await page.screenshot(path=str(dest), full_page=False)


async def compare_snapshot(
    page: Page,
    name: str,
    threshold: float = 0.02,
) -> tuple[bool, float]:
    """
    Compare the current page against the baseline PNG for *name*.

    Returns (passed, diff_pct) where:
    - passed    True when diff_pct <= threshold
    - diff_pct  Fraction of pixels that differ (0.0–1.0)

    When UPDATE_SNAPSHOTS=1 the baseline is overwritten and (True, 0.0) is
    returned without performing a comparison.
    """
    if not _PIL_AVAILABLE:
        raise RuntimeError(
            "Pillow is required for visual regression tests: pip install Pillow"
        )

    if _UPDATE:
        await capture_snapshot(page, name)
        return True, 0.0

    baseline_path = _SNAPSHOTS_DIR / f"{name}.png"
    if not baseline_path.exists():
        raise FileNotFoundError(
            f"No baseline snapshot found for '{name}'. "
            "Run with UPDATE_SNAPSHOTS=1 to create baselines."
        )

    # Take current screenshot into memory
    screenshot_bytes = await page.screenshot(full_page=False)
    current = Image.open(io.BytesIO(screenshot_bytes)).convert("RGB")
    baseline = Image.open(str(baseline_path)).convert("RGB")

    # Resize if dimensions differ (e.g. after a font/layout change)
    if current.size != baseline.size:
        current = current.resize(baseline.size, Image.LANCZOS)

    import struct
    import zlib as _zlib  # noqa: F401 — keep Pillow-only approach below

    # Pixel-by-pixel comparison
    cur_pixels  = list(current.getdata())
    base_pixels = list(baseline.getdata())
    total       = len(base_pixels)
    if total == 0:
        return True, 0.0

    diff_count = sum(
        1 for c, b in zip(cur_pixels, base_pixels)
        if _pixel_distance(c, b) > 20  # tolerance: 20/255 per channel
    )
    diff_pct = diff_count / total
    return diff_pct <= threshold, diff_pct


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _pixel_distance(a: tuple, b: tuple) -> float:
    """Euclidean distance between two RGB tuples."""
    return ((a[0] - b[0]) ** 2 + (a[1] - b[1]) ** 2 + (a[2] - b[2]) ** 2) ** 0.5


async def set_viewport(page: Page, width: int, height: int = 900) -> None:
    """Resize the viewport (helper for multi-viewport snapshot capture)."""
    await page.set_viewport_size({"width": width, "height": height})
