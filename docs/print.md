# Printing

The calculator includes a dedicated print stylesheet so that any tab — IPv4, IPv6, VLSM — produces a clean, ink-efficient page when you use **File → Print** in your browser.

## What gets printed

- The currently active tab's results card (full width on paper).
- Result rows, badges, and the VLSM table (with repeating header rows on page breaks).
- The reverse DNS zone, binary representation, and any other server-rendered content.

## What gets hidden

- Tabs, the toolbar buttons, the share bar, and the splitter form.
- The theme toggle, version badge, footer, and toast notifications.
- All copy-to-clipboard, export, and "Copy All" buttons.
- The slide-in tool drawer chrome (only the active tool's results stay visible if you submitted one before printing).

## Dark mode → light paper

If you're using the calculator in dark mode and print, the print stylesheet **forces light surfaces and dark ink** so the output is readable on white paper. The teal accent colour is dimmed to `#0a8f6b` for ink legibility. You don't need to switch themes before printing.

This is implemented by redefining the dark-theme CSS custom properties (`--color-bg`, `--color-surface`, `--color-text`, `--color-border`, `--color-accent`) to print-safe light values inside `@media print { html[data-theme="dark"] { … } }` in `assets/app.css`. The screen experience is untouched.

## Tips

- **Print preview before printing.** The printer's preview reflects what will land on paper.
- **Save as PDF** in your browser's print dialog produces a portable archive of the result.
- **Print only the result you want** by submitting the tool you care about (e.g. VLSM allocation) first — only the active tab and its drawer panel are printed.
