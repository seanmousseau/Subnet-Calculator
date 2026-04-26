# Subnet Calculator — Style Guide

> Source of truth for the visual identity across both sites: docs (`docs/stylesheets/extra.css` + `docs/overrides/home.html`) and app (`Subnet-Calculator/assets/`).  
> This file lives in `docs/superpowers/` and is excluded from the MkDocs build.

---

## Logo & Favicon

### Mark — SC Monogram (B1)

**File:** `docs/assets/logo-mark.svg`  
**Usage:** MkDocs Material header (`theme.logo` in `mkdocs.yml`)

The bare `SC` initials in Space Grotesk 800, teal `#06d6a0`, letter-spacing `−1`. No container — the text mark pairs naturally with the "Subnet Calculator" wordmark MkDocs Material renders beside it.

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 28" role="img" aria-label="Subnet Calculator">
  <text x="0" y="23"
    font-family="'Space Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    font-weight="800" font-size="28" fill="#06d6a0" letter-spacing="-1">SC</text>
</svg>
```

Works on both dark (`#0a0f16`) and light (`#ffffff`) header backgrounds — teal has sufficient contrast on both.

### Favicon — Network Hierarchy (A)

**File:** `docs/assets/favicon.svg`  
**Usage:** Browser tab (`theme.favicon` in `mkdocs.yml`)

A parent network block splitting into two child subnets — literally what the calculator does. Pure SVG paths (no text), readable at 16–32px, teal `#06d6a0` on any background.

```text
┌──────────┐
│  parent  │
└────┬─────┘
  ┌──┴──┐
┌─┴─┐ ┌─┴─┐
│/25│ │/25│
└───┘ └───┘
```

### App assets (subnetcalculator.app)

The app uses the same mark system as the docs site:

| File | Size | Usage |
|------|------|-------|
| `Subnet-Calculator/assets/favicon-32.png/webp` | 32×32 | Browser tab favicon |
| `Subnet-Calculator/assets/apple-touch-icon.png/webp` | 180×180 | iOS home screen |
| `Subnet-Calculator/assets/logo.png/webp` | 512×512 | App header UI (`<picture class="logo">`), OG/Twitter image, README |

The old skeuomorphic calculator icon is preserved at `Subnet-Calculator/assets/old/` but is no longer used.

### Decision rationale

| Asset | Docs site | App (subnetcalculator.app) |
|-------|-----------|---------------------------|
| SC monogram on dark square (B1) | ✅ header logo | ✅ logo, apple-touch-icon |
| Network hierarchy (A) | ✅ favicon | ✅ favicon-32 |
| Skeuomorphic calculator (retired) | — | `assets/old/` |

---

## Fonts

| Role | Family | Weights | Usage |
|------|--------|---------|-------|
| **Heading** | Space Grotesk | 600 700 800 | All `h1–h4`, hero title, card titles, section labels, nav group titles |
| **Body** | Plus Jakarta Sans | 400 500 600 | Body copy, nav links, badges, descriptions |
| **Mono** | Fira Code | 400 | Code blocks, inline code, pills, version badge |

```css
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap');
```

### Type scale

| Element | Size | Weight | Letter-spacing | Line-height |
|---------|------|--------|---------------|-------------|
| Hero title | `clamp(2.6rem, 6vw, 4.2rem)` | 700 | −0.03em | 1.05 |
| `h1` (doc pages) | `2rem` | 800 | −0.02em | 1.15 |
| `h2` | `1.35rem` | 700 | −0.02em | — |
| `h3 / h4` | MkDocs default | 700 | −0.02em | — |
| Section title | `clamp(1.75rem, 4vw, 2.4rem)` | 800 | −0.035em | 1.12 |
| Section label | `0.68rem` | 700 | +0.14em | — |
| Body | MkDocs default (~1rem) | 400 | — | 1.5–1.65 |
| Badge / pills | `0.72rem` | 400 | +0.02–0.03em | — |
| Nav group title | `0.68rem` | 700 | +0.10em | — |
| Table headers | `0.75rem` | 700 | +0.06em | — |

---

## Colour Tokens

### Global (`:root`)

| Token | Value | Usage |
|-------|-------|-------|
| `--sc-teal` | `#06d6a0` | Primary accent — links, active states, icons, CTAs |
| `--sc-teal-dim` | `rgb(6 214 160 / 10%)` | Teal fill for badges, admonition backgrounds |
| `--sc-teal-border` | `rgb(6 214 160 / 28%)` | Teal border on hover / badge outline |
| `--sc-navy` | `#0d1117` | Dark mode background, hero background |
| `--sc-surface` | `#161b22` | Cards, code blocks, sidebar, search input |
| `--sc-border` | `#21262d` | All dividers and element borders (dark mode) |
| `--sc-text` | `#e6edf3` | Primary text (dark mode) |
| `--sc-muted` | `#8b949e` | Secondary text, tagline, nav links (inactive) |
| `--sc-faint` | `#6e7681` | Tertiary text, placeholder, scrollbar thumb |

### Dark mode (`data-md-color-scheme="slate"`)

| MkDocs token | Resolves to |
|---|---|
| `--md-default-bg-color` | `#0d1117` (navy) |
| `--md-default-fg-color` | `#e6edf3` |
| `--md-code-bg-color` | `#161b22` (surface) |
| `--md-code-fg-color` | `#c9d1d9` |
| `--md-typeset-a-color` | `#06d6a0` (teal) |
| `--md-primary-fg-color` | `#06d6a0` (teal) |
| `--md-primary-bg-color` | `#0d1117` (navy) |
| `--md-footer-bg-color` | `#0a0f16` |

### Light mode (`data-md-color-scheme="default"`)

| MkDocs token | Value | Notes |
|---|---|---|
| `--md-primary-fg-color` | `#065f46` | Emerald-900 — headers, active tabs |
| `--md-primary-bg-color` | `#ffffff` | |
| `--md-accent-fg-color` | `#06d6a0` | Teal accent retained |
| `--md-typeset-a-color` | `#065f46` | Links |
| Header background | `#ffffff` | |
| Tab bar background | `#f9fafb` | |
| Tab link (default) | `#374151` | |
| Tab link (active/hover) | `#065f46` | |
| `h2` border | `#e5e7eb` | |
| Header border | `#e5e7eb` | |

---

## Elevation & Surfaces

| Layer | Dark value | Light equiv. | Usage |
|-------|-----------|------------|-------|
| Base / page | `#0d1117` | `#ffffff` | Document body |
| Surface | `#161b22` | MkDocs default | Cards, code blocks, sidebar, search |
| Raised (header/footer) | `#0a0f16` | `#ffffff` | Site header, footer |
| Border | `#21262d` | `#e5e7eb` | All dividers |

---

## Components

### Badge

```css
background: var(--sc-teal-dim);        /* rgb(6 214 160 / 10%) */
border: 1px solid var(--sc-teal-border); /* rgb(6 214 160 / 28%) */
color: var(--sc-teal);
font-family: 'Fira Code', monospace;
font-size: 0.72rem;
padding: 0.28rem 0.8rem;
border-radius: 999px;
letter-spacing: 0.03em;
```

Animated pulsing dot: `6px × 6px`, `background: var(--sc-teal)`, pulse animation 2.4s.

### Pills (tech stack)

Same shape as badge but:
```css
background: rgb(255 255 255 / 4%);
color: var(--sc-faint);
font-family: 'Fira Code', monospace;
```

### Buttons

| Variant | Background | Text | Border | Hover |
|---------|-----------|------|--------|-------|
| **Primary** | `#06d6a0` | `#0a1a12` | — | bg `#4dffd8`, `translateY(-1px)`, teal shadow |
| **Ghost** | transparent | `var(--sc-text)` | `1px solid #30363d` | border `--sc-teal-border`, text `--sc-teal`, `translateY(-1px)` |

Shared: `padding: 0.72rem 1.6rem`, `border-radius: 8px`, `font-weight: 600`, `font-size: 0.875rem`, `letter-spacing: -0.01em`, `transition: all 0.15s ease`.

At ≤540px: buttons go `width: 100%; justify-content: center`.

### Feature Cards

```css
background: var(--sc-surface);        /* #161b22 */
border: 1px solid var(--sc-border);   /* #21262d */
border-radius: 12px;
padding: 1.75rem;
transition: border-color .15s, transform .15s, box-shadow .15s;
```

Hover:
```css
border-color: var(--sc-teal-border);
transform: translateY(-2px);
box-shadow: 0 10px 36px rgb(0 0 0 / 35%);
/* + radial teal glow via ::before pseudo-element */
```

Icon: `1.75rem × 1.75rem` inline SVG, `color: var(--sc-teal)`. Always `aria-hidden="true"` on the wrapper `<span>`, `focusable="false"` on the `<svg>`.

CTA link: `color: var(--sc-teal)`, `font-size: 0.78rem`, `font-family: 'Fira Code'`, arrow via content.

### Grid

```css
display: grid;
grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
gap: 1.1rem;
```

Naturally collapses: 3 columns at ≥960px, 2 columns at ~640–960px, 1 column at ≤540px (via explicit breakpoint).

### Terminal / Code Block (homepage)

```css
/* Terminal chrome bar (.sc-terminal__bar) */
background: var(--sc-surface);   /* #161b22 */
border-radius: 10px 10px 0 0;
```
Three dot decorators (red `#ff5f57`, yellow `#febc2e`, green `#28c840`), `bash` label in `var(--sc-faint)`.

Terminal body: monospace text, `font-size: 0.82rem`, `line-height: 1.9`.

Syntax colours inside the terminal:
- Comment: `var(--sc-faint)` / `#6e7681`
- Prompt `$`: `var(--sc-teal)`
- Command: `#c9d1d9` (`.cmd`)
- Flag: `#79c0ff` (blue, `.flag`)
- Path: `#ffa657` (orange, `.path`)

### Code blocks (doc pages, dark mode)

```css
background: var(--sc-surface);
border: 1px solid var(--sc-border);
border-radius: 8px;
```

Inline code: `background: rgb(110 118 129 / 12%)`, `color: var(--sc-teal)`, `border-radius: 4px`, `padding: 0.1em 0.4em`.

### Tables

```css
border: 1px solid var(--sc-border);
border-radius: 8px;
overflow: hidden;
```

Header row: `background: var(--sc-surface)`, `font-family: 'Space Grotesk'`, `font-size: 0.75rem`, `text-transform: uppercase`, `letter-spacing: 0.06em`.

Row divider: `border-bottom: 1px solid #1c2128`. Last row: no border.

Hover row: `background: rgb(6 214 160 / 3%)`.

### Admonitions (dark mode)

```css
background: var(--sc-surface);
border-color: var(--sc-border);
border-radius: 8px;
```

Note / info / tip title bar: `background: var(--sc-teal-dim)`.

---

## Hero Background Effects

```css
/* Scrolling teal grid */
background-image:
  linear-gradient(rgb(6 214 160 / 5.5%) 1px, transparent 1px),
  linear-gradient(90deg, rgb(6 214 160 / 5.5%) 1px, transparent 1px);
background-size: 48px 48px;
mask-image: radial-gradient(ellipse 90% 85% at 50% 0%, black 20%, transparent 100%);
animation: sc-grid 24s linear infinite;   /* scrolls diagonally */

/* Radial teal glow behind title */
background: radial-gradient(ellipse, rgb(6 214 160 / 10%) 0%, transparent 65%);
```

---

## Spacing Scale

| Token | Value | Usage |
|-------|-------|-------|
| `0.28rem` | ~4.5px | Badge/pill padding (vertical) |
| `0.6rem` | ~9.6px | Pill gap, badge dot gap |
| `0.75rem` | ~12px | Button gap, card CTA gap |
| `1.1rem` | ~17.6px | Grid gap |
| `1.75rem` | ~28px | Card padding, badge margin-bottom |
| `2rem` | ~32px | Section horizontal padding |
| `2.5rem` | ~40px | Pills margin-top |
| `2.75rem` | ~44px | Tagline margin-bottom |
| `3rem` | ~48px | Mobile hero padding |
| `3.5rem` | ~56px | Mobile features/quickstart padding |
| `4rem` | ~64px | Desktop hero padding |
| `5.5rem` | ~88px | Desktop features padding |

---

## Animations

All animations respect `prefers-reduced-motion: reduce` — motion is suppressed via `animation: none !important` and `transition: none !important`.

| Name | Duration | Easing | Usage |
|------|----------|--------|-------|
| `sc-fade-in` | 0.4s | `ease` | Badge |
| `sc-fade-up` | 0.5s | `ease` | Title (0.08s delay), tagline (0.18s), actions (0.28s), pills (0.38s) |
| `sc-pulse` | 2.4s | `ease infinite` | Badge pulsing dot |
| `sc-grid` | 24s | `linear infinite` | Hero background grid scroll |
| Card / button hover | 0.15s | `ease` | `border-color`, `transform`, `box-shadow` |
| Nav links | 0.15s | — | `color` transition |

`sc-fade-up` keyframe: `from { opacity: 0; transform: translateY(14px); }`.

---

## Responsive Breakpoints

| Breakpoint | Rules applied |
|------------|--------------|
| `≤768px` | Reduce padding: hero `3rem 1.25rem`, features/quickstart `3.5rem 1.25rem`; hero title font clamp to `clamp(2rem, 10vw, 2.6rem)` |
| `≤540px` | Grid → 1 column; actions → `flex-direction: column`; buttons → `width: 100%`; quickstart links → column |

The grid collapses automatically from 3→2 columns via `auto-fill minmax(310px, 1fr)` without explicit breakpoints.

---

## Scrollbar (dark mode, WebKit)

```css
width: 5px; height: 5px;
track: var(--sc-navy)
thumb: #30363d  →  hover: var(--sc-faint)
border-radius: 3px;
```

---

## Section Labels (`.sc-section-label`)

All-caps, Space Grotesk, `0.68rem`, `font-weight: 700`, `letter-spacing: 0.14em`, `color: var(--sc-teal)`.

Used above every major section heading on the homepage ("WHAT'S INCLUDED", "INSTALLATION").

---

## Dos and Don'ts

| Do | Don't |
|----|-------|
| Use inline SVG icons (`aria-hidden` on wrapper, `focusable="false"` on `<svg>`) | Use emoji as icons |
| `cursor: pointer` on all clickable cards and buttons | Leave default cursor on interactive elements |
| `var(--sc-teal)` for all accent usage | Hardcode `#06d6a0` in new rules |
| `var(--sc-border)` for all dark-mode borders | Use `rgba()` legacy syntax — use `rgb(r g b / a%)` |
| Space Grotesk for headings and UI labels | Mix in other display fonts |
| Fira Code for monospace / code / pills | Use system-ui for code contexts |
| `border-radius: 8px` for cards/blocks, `border-radius: 12px` for feature cards, `border-radius: 999px` for badges/pills | Random border-radius values |
| Add light-mode overrides for any colour that uses dark tokens | Assume dark-mode colours work in light mode |
| Test at 375px, 540px, 768px, 1440px | Ship without mobile check |
