# Changelog

Notable changes to Office Planner, newest first. The running version is shown at the bottom of the
Setup rooms page and set by `APP_VERSION` in `config.php`. Version 1.0 is the first release; changes
are recorded from there on.

**With every change (human or AI maintainer):** add a line under "Unreleased" below, in the same commit.

**To publish a release:** run `php tests/smoke.php` (extend it first if the release adds server-side
behaviour), rename "Unreleased" to the new version and date (e.g. `## 1.1 — 2026-11-02`), start a fresh
empty "Unreleased" section above it, and set `APP_VERSION` in `config.php` to match. Note anything an
admin must do when upgrading (usually nothing: the database migrates itself).

## Unreleased
- Room planner: a plain click now books a slot with your initials (and clears your own), as in the
  grid; dragging still selects.
