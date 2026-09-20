# Office Presence Tracker

A PHP + SQL app for tracking who's in the office and how meeting/booking spaces are used,
laid out like a spreadsheet. Every day is split into 1-hour columns (9–17); desk presence is
recorded per morning (9–13) and afternoon (13–17), and those AM/PM fields line up exactly with
the hourly slots used by the bookable spaces.

## Room types

Each room shows its **type abbreviation + room number** in the left "Room" column (e.g. `DO 10101`,
`M 20001`). The room number is set in the admin page.

- **DO — Double occupancy office**: two desk lines, each with a desk pictogram, a name (set in admin),
  and per-day **AM / PM** presence fields. AM/PM cells can be colored green or red.
- **SO — Single occupancy office**: one desk line (name + AM/PM presence), plus a second line for the
  room's **meeting table** — a round-table pictogram with hourly (9–17) booking slots.
- **M — Large meeting room**: hourly booking slots only.
- **F — Focus room** (small meeting room): hourly booking slots only, same table pictogram as the SO
  meeting table.
- **T — Calling cell**: hourly booking slots only.

## How it works

- **Views**: Day / Week / Month buttons (top right). Previous / Today / Next buttons (top left) stay in
  a fixed position and never shift.
- **Desk AM/PM cells** (DO & SO desk lines):
  - Click a cell to select it; click-drag across a row to select several. **Shift+click** or
    **Shift+arrow keys** extend the selection into a rectangular block (spreadsheet style), and
    **Ctrl/⌘+click** toggles individual cells in/out. On a touch device, **long-press** a desk cell
    to enter selection mode, then tap desk cells to add/remove; a bottom bar shows the count and the
    Free / Occ / Clear / Cancel actions.
  - Type to fill in text, or paste (Ctrl/Cmd+V). Enter/F2 edits in place; Delete/Backspace clears.
    With several cells selected, type and press Enter to fill the same text into all of them.
  - Use the **Free** (green) / **Occ** (red) / **Clear** buttons to color the current selection.
- **Hourly booking cells** (SO meeting table, M, F, T):
  - Type or paste initials into a slot, or just **tap/click a slot to book it with your own
    initials** (set once via the "You:" chip and remembered on your device). Tapping your own
    booking again clears it; tapping someone else's leaves it alone. Filled slots turn red
    automatically; these cells aren't manually colorable — the color follows the content.
- **Month view** drops the hourly booking slots (booking rows show an inert striped band) and
  shows each day as two columns — morning (AM) and afternoon (PM) — so the whole month fits.
- **+Month+ view** (the fourth view button, or **⌘/Ctrl+4**) is the same month padded out to
  complete Mon–Fri weeks. The padding days (from the neighbouring months) are shown muted but stay
  editable — handy for marking leave or travel that spans a month boundary. It's a distinct view,
  so it stays while you page between months and you return to plain Month/Week/Day by choosing them.
- On a touch device in **Day or Week view**, swipe left/right on the grid to move to the next/previous
  day or week (vertical scrolling and taps to select/book are unaffected; Month views keep the buttons).
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
- **Ctrl/⌘ + 1 / 2 / 3 / 4** — switch to Day / Week / Month / +Month+ view.
- **Ctrl/⌘ + .** (or **Home**) — jump to today.
- **Ctrl/⌘ + ← / →** — jump to the previous / next range.

(Today is not on Ctrl/⌘+T because browsers reserve that for a new tab and never pass it to a
web page. The view shortcuts stay on plain Ctrl/⌘+1/2/3, which browsers *do* deliver to the page.)
- **Enter / F2** — edit the selected cell. **Delete / Backspace** — clear it. **Esc** — deselect.

## Admin (Manage rooms & desks)

- Add a room by number + type. DO auto-creates 2 desks; SO auto-creates 1 desk; M/F/T have no desks.
  After adding a DO/SO room the cursor lands in its first desk-name field, ready to type.
- Room numbers, desk names, capacity, and the checkboxes all **save automatically** as you edit them
  (no Save button); a small "Saved" indicator confirms each change.
- Reorder rooms with the ↑ / ↓ buttons; delete rooms with Delete. These reload the page but keep
  your scroll position.
- Edit a room's number inline; its data stays attached.
- Toggle whether each room *type* appears in the tracker — Double offices (D), Single offices (S),
  Meeting rooms (M), Focus rooms (F), Calling cells (T) — plus whether single offices show their
  shared meeting-table line. And each room has its own **Show** checkbox to hide just that one room.
  Everything defaults to shown; hiding keeps the data, which reappears when re-enabled.
- M rooms have an optional capacity field (not shown in the grid, just recorded).
- "Maintenance → Delete data older than one month" removes presence/booking entries dated more than
  a month ago (rooms, desks, and the last month onward are kept). This is permanent.

### Access control (optional)

The tracker can be protected by a single shared password (no usernames). It's **off by default**.

- Turn it on/off with the "Access control" checkbox on the admin page.
- The password is the word set as `AUTH_PASSWORD` in `config.php` (change it there).
- When on, each visitor enters the password once per device; it's remembered ~90 days (a signed
  cookie that never contains the password itself).
- If you ever lock yourself out (enabled it, forgot the password), just edit `AUTH_PASSWORD` in
  `config.php` — or set the `auth_enabled` row in the `meta` table back to 0.

This keeps outsiders out but doesn't identify individuals. For real per-person identity/audit,
front the app with your organization's SSO instead.

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

## Running it locally

```bash
cd office-tracker
php -S localhost:8000
```

Open http://localhost:8000. A `data/tracker.sqlite` file is created on first run, with demo rooms so
you can try it immediately. Delete the demo rooms via "Manage rooms & desks".

## Deploying

Copy the folder to any PHP host. The `data/` directory must be writable by the web server user
(that's the only requirement for the default SQLite setup).

### Using MySQL instead of SQLite

1. Optionally create the schema from `schema_mysql.sql` (the app also creates tables itself).
2. In `config.php` set `$USE_SQLITE = false` and fill in `$MYSQL_HOST/DB/USER/PASS`.

## File overview

```
config.php                     DB connection + schema + demo data; BOOKING_HOURS constant (9–16)
index.php                      The grid (day/week/month), all room types
admin.php                      Manage rooms & desks
login.php                      Shared-password sign-in (only used when access control is on)
api/save_desk_cell.php         Saves a desk AM/PM cell's text
api/save_booking_cell.php      Saves an hourly booking cell's text (auto-red on content)
api/save_batch_color.php       Saves green/red/none color for selected desk cells
api/revision.php               Returns the global change counter (polled for auto-refresh)
api/state.php                  Returns all cell values in a date range (fetched when the counter moves)
api/admin_update.php           Auto-saves admin field edits (room number, desk name, capacity, flags)
assets/admin.js                Admin auto-save + scroll preservation
assets/theme.js                Sets light/dark on load (cookie override, else OS) — no flash
assets/style.css               Styling
assets/app.js                  Selection, drag-select, typing/paste, color buttons
assets/icons.php               Inline SVG pictograms (desk, round table, big meeting, calling cell)
schema_mysql.sql               Reference schema for MySQL setups
```

## Notes

- No login/auth — anyone with the URL can edit. Add HTTP auth or a login layer if needed.
- Booking hours are 9–17 (slots starting 9…16); change `BOOKING_HOURS` in `config.php` to adjust.
- Weeks are Monday–Friday. Month view shows all weekdays of the month.
- Colors are intentionally muted (soft green/red); tweak the `--green-*` / `--red-*` variables in
  `assets/style.css` to change them.
- **Double offices toggle:** the "Double offices" checkbox in the toolbar collapses/expands the
  double-occupancy office rows, for a compact "find a room" overview. It's per-user (saved in a
  cookie). On a touch device you can also pinch vertically — pinch in to hide, spread to show.
- **Dark mode:** follows the device's OS light/dark setting automatically, and the sun/moon disc in
  the toolbar overrides it per device (saved in a cookie). Works with both colour palettes.
- **Colour-blind-friendly palette:** the small two-colour disc in the toolbar switches, per device,
  between the standard green/red and an orange / bluish-green pair (Okabe-Ito based) that stays
  distinct under red-green colour-vision deficiency; it also recolours the M/F/T label tints. The
  disc shows the active palette's colours and names it in a brief toast on tap. Saved in a cookie
  (per user, no login needed).
```
