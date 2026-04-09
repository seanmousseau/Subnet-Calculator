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
6. Build the release tarball: `tar -czf releases/subnet-calculator-X.Y.Z.tar.gz -C Subnet-Calculator .`
7. Commit all changes with message: `release: vX.Y.Z`
8. Confirm with user before pushing and opening a PR from `dev → main`.
