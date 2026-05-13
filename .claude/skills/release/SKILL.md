---
name: release
description: Run the full release checklist for Subnet Calculator — bump version, update changelog, build tarball, commit, push, and open a PR.
disable-model-invocation: true
---

Run the full Subnet Calculator release workflow:

1. Ask the user for the new version number (e.g. "1.3.0") if not already provided.
2. Bump `$app_version` in `Subnet-Calculator/includes/config.php`.
3. Update the version string in `Subnet-Calculator/templates/layout.php`.
4. Add a new release section to `CHANGELOG.md` (summarize changes since last release from `git log --oneline` since the last tag).
5. Add a row to the downloads table in `README.md`.
6. Update docs to reflect the new version:
   - Bump `extra.version` in `mkdocs.yml` to `"X.Y.Z"`.
   - Update the tarball filename in `docs/index.md` (the `tar -xzf` install snippet).
7. Build the release tarball: `tar -czf releases/subnet-calculator-X.Y.Z.tar.gz -C Subnet-Calculator .`
8. **Sync internal docs** (`docs/internal/`). Review the per-release spec/plan files (`docs/superpowers/specs/` and `docs/superpowers/plans/`) for this version and propagate anything load-bearing into the long-lived docs:
   - `design-document.md` — new invariants (§5), design decisions (§6), tools (§7), endpoints (§8); update scale numbers (test counts, line counts) in §3/§9.
   - `design-guide.md` — new UX patterns or principles.
   - `coding-guide.md` — convention changes.
   - `api-contract.md` — new error codes, breaking-change notes, deprecations.
   - `data-dictionary.md` — schema, column, index, migration, or DB-path changes.
   - `config-reference.md` — added/removed/renamed/redefaulted operator config variables.
   - `security-model.md` — threat-surface changes or new controls.
   - `runbooks.md` — new incident classes learned this cycle.
   - Bump the "Last updated" header on every doc touched.
   - If nothing changed for a given doc, skip it (don't bump just to bump).
9. Commit all changes with message: `release: vX.Y.Z`
10. Confirm with user before pushing and opening a PR from `dev → main`.
