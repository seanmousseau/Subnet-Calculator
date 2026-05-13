# Internal Documentation

> Source of truth for **how this project actually works** — beyond the user-facing docs at `docs/`.

These documents are not deployed to GitHub Pages. They're read by:
- The operator (Sean), to recover context across long gaps.
- Future agent sessions, to load decision history without re-deriving it from git archaeology.
- Anyone reviewing a PR who needs to know "why does this code look weird?"

## Map

| Document | When to read |
|---|---|
| [`design-document.md`](design-document.md) | Architecture, invariants, design decisions. **Start here.** |
| [`design-guide.md`](design-guide.md) | UX principles. Read before adding tools or changing UI. |
| [`coding-guide.md`](coding-guide.md) | PHP/JS/CSS conventions. Read before writing code. |
| [`testing-guide.md`](testing-guide.md) | Test layers, when to add what. Read before changing test coverage. |
| [`security-model.md`](security-model.md) | Threat model + trust boundaries. Read before security-sensitive changes. |
| [`api-contract.md`](api-contract.md) | API versioning, breaking-change policy, envelope contract. Read before API changes. |
| [`data-dictionary.md`](data-dictionary.md) | SQLite schemas + migration policy. Read before DB-level changes. |
| [`config-reference.md`](config-reference.md) | Operator-tunable config variables. Read before adding/changing config knobs. |
| [`runbooks.md`](runbooks.md) | Production incident playbooks. Read when something breaks. |
| [`specs/`](specs/) | One-shot internal specs (e.g., the v3.3.0 IPv6 foundations spec). |

## Companion documents (elsewhere in the repo)

| Document | Lives at | Purpose |
|---|---|---|
| `README.md` | repo root | User-facing project README |
| `CHANGELOG.md` | repo root | Per-release log |
| `CONTRIBUTING.md` | repo root | External contributor on-ramp |
| `SECURITY.md` | repo root | Vulnerability reporting process |
| `CLAUDE.md` | repo root | Agent quick-reference (commands, hotspots) |
| `docs/superpowers/style-guide.md` | docs/ | Visual identity (logo, colours, typography) |
| `docs/superpowers/plans/2026-04-25-v2.9.0-ui-ux-style-guide.md` | docs/ | Detailed UI/UX pattern catalogue |
| `docs/superpowers/plans/` + `docs/superpowers/specs/` | docs/ | Per-release plans + design specs |

## Update protocol

Each document has its own "Update protocol" section at the bottom. Common rule: update *with the same PR* that changes the underlying reality. Don't let docs drift.

When in doubt about where information belongs:
- Architecture / invariants / why → `design-document.md`
- How to do X in code → `coding-guide.md`
- What the UI should look like → `design-guide.md` (principles) or visual style guide (specifics)
- How to test → `testing-guide.md`
- What attacks we defend against → `security-model.md`
- How the API behaves → `api-contract.md`
- What to do when things break → `runbooks.md`

If something doesn't fit any of these, the doc set is incomplete — propose a new file.
