# Office Planner

A PHP + SQL app for tracking who's in the office and how meeting/booking spaces are used,
laid out like a spreadsheet. Every day is split into 1-hour columns (9–17); desk presence is
recorded per morning (9–13) and afternoon (13–17), and those AM/PM fields line up exactly with
the hourly slots used by the bookable spaces.

## Room types

Each room shows its **type abbreviation + room number** in the left "Room" column (e.g. `S 1030`,
`M 2000`). The room number is set in the admin page.

**Offices** (S, D, O, E) all work the same way: one or more **desk lines**, each with a desk pictogram,
a name (set in admin), and per-day **AM / PM** presence fields that can be colored green (free) or red
(occupied). The four office types differ only in how many desks they start with and a couple of
conveniences:

- **S — Single occupancy office**: one desk.
- **D — Double occupancy office**: two desks.
- **O — General office**: any number of desks (1 or more — add/remove them in Setup rooms).
- **E — Early-career scientists**: like a general office (any number of desks, starts at 3 by default) but
  with **numbered** desk lines and its **own visibility toggle** in Setup rooms — for master/PhD/postdoc
  shared offices.

**Any office can also have a bookable meeting table.** Each office card in Setup rooms has a **Bookable
meeting table** checkbox; ticking it adds a round-table row to that room with hourly (9–17) booking slots.
So a single, double, general, or early-career office can each either include a table or not — independently
per room. (Single offices come with their table on by default; the others off.)

**Bookable rooms** (M, F, T) are the standalone bookable spaces — no desks, just hourly (9–17) booking slots:

- **M — Large meeting room**: hourly booking; capacity shown next to the name.
- **F — Focus room** (small meeting room): hourly booking; capacity shown.
- **T — Calling cell**: hourly booking; a single-person booth (capacity 1).

Notes:
- The **meeting-space focus** toggle (the round-table disc in the toolbar) hides the desk-only offices for
  a compact "find a room" view, but keeps any office that **has a table** fully visible (you need its
  desks' presence to judge whether the table is free).
- When an office is marked occupied, its meeting table's matching half-day is faintly tinted and (if
  enabled) booking it asks for confirmation. In a multi-desk office the table is flagged if **any** desk is
  occupied that half-day; it clears only when **no** desk is.
- Internally, **O** is stored as type code `LO` (it was formerly "Large office"); this is invisible to
  users and needs no data migration when upgrading.

## How it works

- **Views**: Day / Week / Month / +Month+ buttons (top right). Previous / Today / Next buttons (top
  left) stay in a fixed position and never shift. The control bar, toolbar, and the grid's day/hour
  header stay frozen while you scroll the room list. (On phones the two Month views are hidden — Day
  and Week are the usable ones there — and the grid defaults to Day.)
- **Current time**: in Day and Week view a thin blue line marks the current time down today's column,
  during office hours.
- **Desk AM/PM cells** (any office's desk lines):
  - Click a cell to select it; click-drag across a row to select several. **Shift+click** or
    **Shift+arrow keys** extend the selection into a rectangular block (spreadsheet style), and
    **Ctrl/⌘+click** toggles individual cells in/out. On a touch device, **long-press** a desk cell
    to enter selection mode, then tap desk cells to add/remove; a bottom bar shows the count and the
    Free / Occ / Clear / Cancel actions.
  - Type to fill in text, or paste (Ctrl/Cmd+V). Enter/F2 edits in place; Delete/Backspace clears.
    With several cells selected, type and press Enter to fill the same text into all of them.
  - Use the **Free** (green) / **Occ** (red) / **Clear** buttons to color the current selection.
- **Hourly booking cells** (any office's meeting table, plus M, F, T):
  - Type or paste initials into a slot, or just **tap/click a slot to book it with your own
    initials** (set once via the "You:" chip and remembered on your device). Tapping your own
    booking again clears it; tapping someone else's leaves it alone. Filled slots turn red
    automatically; these cells aren't manually colorable — the color follows the content.
  - **Office etiquette:** when an office is marked occupied for a half-day, that half-day's hours on
    the office's own meeting table are tinted faintly, and (if enabled in Setup rooms → "Booking
    etiquette") booking one of those hours asks for a quick confirmation. In a multi-desk office the
    table is flagged if *any* desk is occupied; it clears only when none is. The tint is always shown;
    the confirmation can be switched off once the habit forms.
- **Right-click** a desk cell for a quick **Free / Occ / Clear** menu (plus "Open year planner");
  it acts on the whole selection if you right-click within it.
- **Undo / redo**: **⌘/Ctrl+Z** and **⌘/Ctrl+Shift+Z** reverse the last edits (this browser session).
  Undo re-selects the cells it changed. The year planner has its own separate undo history.
- **Month view** drops the hourly booking slots (booking rows show an inert striped band) and
  shows each day as two columns — morning (AM) and afternoon (PM) — so the whole month fits.
- **+Month+ view** (the fourth view button, or **⌘/Ctrl+4**) is the same month padded out to
  complete Mon–Fri weeks. The padding days (from the neighbouring months) are shown muted but stay
  editable — handy for marking leave or travel that spans a month boundary. It's a distinct view,
  so it stays while you page between months and you return to plain Month/Week/Day by choosing them.
- On a touch device in **Day or Week view**, swipe left/right on the grid to move to the next/previous
  day or week (vertical scrolling and taps to select/book are unaffected). The Month views aren't
  offered on phones.
- On a desktop, **double-click a desk name** — or click the small 12-dot icon after it — to open that
  desk's **year planner**: a full-year calendar (12 month blocks, weekends greyed). Click a weekday to
  cycle occ → free → clear, or drag / Shift-click a range and use Free / Occ / Clear. **Right-click** a
  day for occ / free / morning-free / afternoon-free / clear. **⌘/Ctrl+← / →** switches year, **Esc**
  closes (the first Esc only clears a selection, if there is one), and **⌘/Ctrl+Z / Shift+Z** undo/redo within the planner. It sets whole days (both AM and PM)
  and saves through the same storage, so it stays in sync with the grid; a day set to different AM/PM
  colours in the grid shows as a split (mixed) cell.
- On a desktop, **double-click a bookable room's name** — an M/F/T room or an office's meeting-table
  row — or click the small icon after it (three stacked bars crossed by a column) to open its **3-month
  room planner**: 13 weeks stacked one per row, each laid out like that room's row in Week view
  (Mon–Fri, 9–16). The same slot in consecutive weeks lines up vertically, so a repeated meeting is
  one drag: **click or drag to select** (dragging selects a rectangle, e.g. straight down a column for
  "every Tuesday 10:00"), **⌘/Ctrl-click** to add single slots (e.g. every other week), then **type**
  and press **Enter** to fill them all; **Delete** clears. A plain click selects rather than one-tap
  booking, so slots can be dragged over. Today's slots have a thick blue frame and the current week's
  label is blue. ← / → move four weeks at a time; the **×** button or **Esc** returns to the grid (Week view) — with slots selected, the first Esc just
  clears the selection. Saving, undo
  /redo, the long-text tooltip, live refresh, and the occupied-office hint work exactly as in the grid.
- Both planner icons are desktop-only: they're hidden on narrow screens and touch devices.
- **⌘/Ctrl+↑ / ↓** scroll the page up/down when the table is taller than the window.
- Everything saves automatically (no save button); a small "Saved" indicator appears bottom-right.

### Multiple people at once

The grid keeps itself in sync when several people use it together. Each cell is an independent
record, so people editing different cells never collide. On top of that, every open page quietly
checks about once a minute whether anything changed and repaints only the cells that did (it won't
disturb a cell you're actively editing). This is deliberately cheap for tabs left open all day:

- It first checks a tiny "revision" counter and only fetches cell data when that counter has moved,
  so a check that finds no news costs a few bytes.
- It stops checking entirely while the tab is hidden (backgrounded, or the machine asleep).
- It stops checking while you're idle, and resumes the moment you interact or return to the tab
  (with one immediate catch-up).

It's still last-write-wins: if two people edit the *same* cell within the same minute, the later
save wins. For a small team this is normally fine; if you need hard guarantees, that would call for
per-cell version checks (a further enhancement).

### Keyboard

- **Tab / Shift+Tab** — move to the next / previous cell in the row (works from empty cells too).
- **Arrow keys** — move the selection left/right within a row and up/down between rows.
- **Ctrl/⌘ + F** — mark selected desks **Free** (green). **Ctrl/⌘ + O** — mark them **Occ** (red).
  **Ctrl/⌘ + C** — **Clear** the color. (These only override the browser's own find/open/copy
  when desk cells are actually selected; with nothing selected they behave normally.)
- **Ctrl/⌘ + Z** — undo; **Ctrl/⌘ + Shift+Z** — redo (this session).
- **Ctrl/⌘ + 1 / 2 / 3 / 4** — switch to Day / Week / Month / +Month+ view.
- **Ctrl/⌘ + E** — toggle meeting-space focus (hide desk-only offices).
- **Ctrl/⌘ + A** — show every room, ignoring all hide settings (a hidden admin override; press again to exit).
- **Ctrl/⌘ + .** (or **Home**) — jump to today.
- **Ctrl/⌘ + ← / →** — jump to the previous / next range.

(Today is not on Ctrl/⌘+T because browsers reserve that for a new tab and never pass it to a
web page. The view shortcuts stay on plain Ctrl/⌘+1/2/3, which browsers *do* deliver to the page.)
- **Enter / F2** — edit the selected cell. **Delete / Backspace** — clear it. **Esc** — deselect.

## Setup rooms (admin page)

The setup page is organized into three framed, tinted sections with a sticky jump bar at the top
(**Settings · Rooms · Danger zone**) so it stays navigable even with many rooms.

**Settings** — room-type visibility (Single S, Double D, General offices O, Early-career E, Meeting
rooms M, Focus rooms F, Calling cells T; everything defaults to shown, and hiding keeps the data);
Access control (the two passwords, below); Meeting-space focus (what the focus button hides — see
Notes); and Booking etiquette (the occupied-office confirmation toggle).

**Rooms** — **Room order** (sort buttons), the room cards, and **Add a new room**:
- Add a room by number + type (single offices listed first). S auto-creates 1 desk; D auto-creates 2;
  General office (O) and Early-career (E) ask for a desk count (default 1 for O, 3 for E; any number,
  add/remove later, minimum 1); M/F/T have no desks. After adding a room with desks the cursor lands
  in its first desk-name field, ready to type.
- Each office card has editable desk names, a **Show** checkbox (hide just that room), and a
  **Bookable meeting table** checkbox. M/F/T cards have a capacity field (capacity *is* shown in the
  grid next to the name).
- Room numbers, desk names, capacity, and all checkboxes **save automatically** (no Save button).
- Reorder with the ↑ / ↓ buttons, or use **Sort rooms by number** (understands a letter suffix: 5, 5a,
  5b, 6) or **Sort, early-career last** (same, but groups the E rooms at the end). Edit a room's number
  inline; its data stays attached.

**Danger zone** —
- **Maintenance → Delete data older than one month** removes presence/booking entries dated more than
  a month ago (rooms, desks, and the last month onward are kept). A backup is taken automatically just
  before it runs. This is permanent.
- **Backups** panel — see Backups, below.

### Access control (optional)

There are **two independent shared passwords** (no usernames), both set at the **top of `config.php`**
and both **off by default** — each enabled separately by a checkbox in Setup rooms → Access control:

- **`AUTH_PASSWORD`** — protects the whole tracker. Entered once per device, remembered ~90 days.
- **`ADMIN_PASSWORD`** — protects just the setup page, so it can be locked even when the tracker is
  open to everyone. Re-asked once a day (the session is bound to the calendar day).

Change a password by editing the word in `config.php`; that also signs everyone out of that gate (how
you revoke access). Cookies are signed and never contain the password itself. If you enable the setup
password and forget it, edit `config.php` or set `admin_auth_enabled` to 0 in the `meta` table to get
back in (same for `auth_enabled` for the tracker password).

This keeps outsiders out but doesn't identify individuals. For real per-person identity/audit, front
the app with your organization's SSO instead.

## Reading the grid

- Bookable rooms are tinted on their left label cells so they stand out at a glance: **M** (large
  meeting room) pale amber, **F** (focus room) pale blue, **T** (calling cell) pale violet. The tint
  is only on the label columns, never the schedule cells, so it never clashes with the green/red
  status coloring.
- Thicker horizontal lines separate rooms. In month view, a thicker vertical line marks each
  week boundary (between Friday and Monday).

## Requirements

- PHP 8+ with `pdo_sqlite` (enabled by default in almost all PHP installs).
- No build step, no Composer, no Node.

For step-by-step setup (passwords, permissions, protecting the data, MySQL, cron, upgrading), see
[`INSTALL.md`](INSTALL.md).

## Running it locally

```bash
cd office-tracker
php -S localhost:8000
```

Open http://localhost:8000. A `data/tracker.sqlite` file is created on first run, with demo rooms so
you can try it immediately. Delete the demo rooms via the "Setup rooms" page.

### Backups (SQLite)

The database is snapshotted automatically into `data/backups/` — **once a day**, taken on page load
whenever the app is in use (no cron required), and always just before a data purge. Snapshots use
SQLite's `VACUUM INTO`, which is consistent even while people are editing.

- **Rotation:** automatic snapshots are kept **daily for a week, then weekly for ~a month** (oldest
  ≈ 1 month). **Kept** backups — made with "Back up now", or taken automatically before a purge — are
  **never auto-deleted**; remove them yourself with the × in the panel. Each is tagged *Auto* or *Kept*.
- The backups folder is denied to the web (its own `.htaccess`); the panel serves downloads through a
  validated admin endpoint.
- **Restore** from the **Backups** panel in Setup rooms → Danger zone: **Restore** next to any snapshot,
  or **Upload & restore** a file you keep off-site. A restore first saves the current state as a *Kept*
  backup (so it's reversible), validates the source is a real Office Planner database, and then swaps it
  in. Restoring rolls the **whole** database back, so do it when nobody's mid-edit.

If the host has cron and you want a guaranteed schedule regardless of traffic, add a job that runs
`backup.php` (see the header of that file for the exact `crontab` line). The self-triggered backups and
the cron script are safe to use together.

### Concurrency

For many simultaneous users the SQLite database runs in **WAL mode** with a busy-timeout, so readers
and writers (including the backup snapshot) don't block each other and saves don't fail under load.

## Deploying

Copy the folder to any PHP host. The data directory must be writable by the web server user
(that's the only requirement for the default SQLite setup). When upgrading, replace the code files
but **leave the data directory alone** (it holds the live database and the backups).

### Where the data lives, and protecting it from the web

The SQLite database, its WAL side-files, and the `backups/` folder all live in the directory set by
`DATA_DIR` near the top of `config.php` (default: `data/` inside the app). On first run the app writes
a `.htaccess` ("Deny from all") into that folder and into `backups/`, which blocks web access **on
Apache**.

**nginx ignores `.htaccess`**, so on nginx you must protect the data yourself — otherwise
`https://yoursite/…/data/tracker.sqlite` would be downloadable. Two ways:

1. **Deny the folder in the nginx server block:**
   ```nginx
   location ^~ /data/ { deny all; return 404; }
   ```
   then reload nginx.
2. **Move the data outside the web root** — set, in `config.php`:
   ```php
   const DATA_DIR = __DIR__ . '/../office-planner-data';
   ```
   and make that directory writable. Nothing under the web root can then be fetched. (The most robust
   option, immune to web-server misconfiguration.)

**Quick check after deploying:** open `https://yoursite/…/data/tracker.sqlite` in a browser once. A
*Forbidden/404* means you're protected (Apache, or a rule/move applied); a *file download* means it's
exposed (nginx without a rule) — apply option 1 or 2.

### Using MySQL instead of SQLite

1. Optionally create the schema from `schema_mysql.sql` (the app also creates tables itself).
2. In `config.php` set `$USE_SQLITE = false` and fill in `$MYSQL_HOST/DB/USER/PASS`.

## File overview

```
config.php                     Passwords (top) + DB connection/schema/migration/seed, backups,
                               auth helpers; BOOKING_HOURS constant (9–16)
index.php                      The grid (day/week/month/+month+), all room types, current-time line,
                               and the 3-month room planner (view=room)
admin.php                      Setup rooms (Settings / Rooms / Danger-zone sections)
login.php                      Shared-password sign-in (tracker and setup passwords)
backup.php                     Optional cron entry point for scheduled backups
favicon.ico / assets/favicon.* Meeting-table app icon
api/save_desk_cell.php         Saves a desk AM/PM cell's text
api/save_booking_cell.php      Saves an hourly booking cell's text (auto-red on content)
api/save_batch_color.php       Saves green/red/none color for selected desk cells
api/desk_year.php              Returns a desk's AM/PM colors for a whole year (year planner)
api/revision.php               Returns the global change counter (polled for auto-refresh)
api/state.php                  Returns all cell values in a date range (fetched when the counter moves)
api/admin_update.php           Auto-saves admin edits + flags; backup restore/upload/delete
assets/app.js                  Selection, typing/paste, color buttons, undo/redo, context menus,
                               meeting-space focus, current-time line, year planner, touch gestures
assets/admin.js                Admin auto-save + scroll preservation
assets/theme.js                Sets light/dark on load (cookie override, else OS) — no flash
assets/style.css               Styling
assets/icons.php               Inline SVG pictograms (desk, round table, big meeting, calling cell)
schema_mysql.sql               Reference schema for MySQL setups
INSTALL.md                     Step-by-step installation guide
CHANGELOG.md                   Changes between versions (current version: APP_VERSION in config.php)
tests/smoke.php                Command-line smoke test (php tests/smoke.php)
LICENSE                        MIT licence
.gitignore                     Keeps the runtime data/ out of version control
```

## Testing changes

Before committing or publishing a new version, run the smoke test from the app folder:

```bash
php tests/smoke.php
```

It needs only PHP. It copies the app to a temporary folder, starts PHP's built-in server on it with a
throwaway database (your real data is never touched), and checks the server-side essentials: every
page loads, saving and reading back work, both password gates hold (including the setup API), backups
download / restore / purge correctly, and an old-format database upgrades cleanly. It prints one
PASS/FAIL line per check and exits with 0 when all pass, 1 otherwise — so it can also run from a git
hook or CI. Browser behaviour (dragging, typing, the planners, tooltips) isn't covered; the top of
`tests/smoke.php` lists what to click through by hand after a JavaScript change. Run over the web, the
script refuses to do anything.

**When you change the code:** if the change adds or alters server-side behaviour (a new page, endpoint,
setting, database column, or migration), add a check for it to `tests/smoke.php` in the same commit, and
make sure the whole suite still passes before committing.

## License

MIT — see [`LICENSE`](LICENSE). © 2026 Carsten Dominik.

## Notes

- Access is by the optional shared passwords above; without them, anyone with the URL can edit
  (the intended trust-based model for a small team). For per-person identity, front it with SSO.
- Booking hours are 9–17 (slots starting 9…16); change `BOOKING_HOURS` in `config.php` to adjust.
- Weeks are Monday–Friday. Month view shows all weekdays of the month.
- Colors are intentionally muted (soft green/red); tweak the `--green-*` / `--red-*` variables in
  `assets/style.css` to change them.
- **Meeting-space focus toggle:** the round-table disc in the toolbar focuses the view on finding a
  meeting space; it lights up blue when on. Per-user (cookie); also ⌘/Ctrl+E, or a vertical pinch on
  mobile (pinch in = focus on). What it hides is chosen in Setup rooms → Meeting-space focus:
  *Hide offices without a meeting table* (default — tabled offices stay fully visible) or *Hide all
  desks* (every office collapses to just its table, relying on the occupied tint; this option reloads
  on toggle).
- **Dark mode:** follows the device's OS light/dark setting automatically, and the sun/moon disc in
  the toolbar overrides it per device (saved in a cookie). Works with both colour palettes.
- **Colour-blind-friendly palette:** the small two-colour disc in the toolbar switches, per device,
  between the standard green/red and an orange / bluish-green pair (Okabe-Ito based) that stays
  distinct under red-green colour-vision deficiency; it also recolours the M/F/T label tints. The
  disc shows the active palette's colours and names it in a brief toast on tap. Saved in a cookie
  (per user, no login needed).
