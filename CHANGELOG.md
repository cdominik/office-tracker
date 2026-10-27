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
- Day/Week swipe on phones: the new day or week now slides in once (it no longer appears first and
  then slides in again), and the grid keeps its scroll position. The ← / → / Today buttons and keyboard
  date navigation keep the scroll position too.
- Backups: fixed retention. Automatic backups were deleted after about 8 days instead of being kept
  weekly for about a month (most visible right after "Back up now", which runs the clean-up). Kept
  backups were never affected. The Backups panel now lists all backups instead of the newest 20.
- Backups: a backup's age is now read from the date in its file name, not the file's modification
  time. Copying or uploading the data folder resets modification times, which made every automatic
  backup look brand-new so the clean-up kept only one.
