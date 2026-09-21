(function () {
    'use strict';

    // Show only the modifier key for this platform in shortcut hints: ⌘ on Mac, Ctrl elsewhere.
    // (The key handler accepts both either way — this is display only.)
    (function localizeShortcutHints() {
        const plat = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || navigator.userAgent || '';
        const mac = /mac|iphone|ipad|ipod/i.test(plat);
        const mod = mac ? '⌘' : 'Ctrl';
        document.querySelectorAll('[title]').forEach((el) => {
            if (el.title.indexOf('⌘/Ctrl') !== -1) el.title = el.title.split('⌘/Ctrl').join(mod);
        });
        const tip = document.querySelector('.help-tip');
        if (tip && tip.innerHTML.indexOf('⌘/Ctrl') !== -1) {
            tip.innerHTML = tip.innerHTML.split('⌘/Ctrl').join(mod);
        }
    })();

    const cells = Array.from(document.querySelectorAll('.cell'));

    // Feed the 2nd-header-row offset to CSS (so it sticks just below the 1st header row).
    function setStickyOffsets() {
        const r1 = document.querySelector('.grid thead tr');
        document.documentElement.style.setProperty('--hdr1-h', (r1 ? r1.offsetHeight : 0) + 'px');
    }
    setStickyOffsets();
    window.addEventListener('load', setStickyOffsets);
    window.addEventListener('resize', setStickyOffsets);

    // Admin-gate: clicking "Manage rooms & desks" asks for confirmation first, so a regular
    // user doesn't wander into the setup page and change things by accident.
    (function adminGate() {
        const link = document.getElementById('adminLink');
        const gate = document.getElementById('adminGate');
        if (!link || !gate) return;
        const open = () => { gate.hidden = false; };
        const close = () => { gate.hidden = true; };
        link.addEventListener('click', (e) => { e.preventDefault(); open(); });
        const cancel = document.getElementById('adminGateCancel');
        const go = document.getElementById('adminGateGo');
        if (cancel) cancel.addEventListener('click', close);
        if (go) go.addEventListener('click', () => { window.location = 'admin.php'; });
        gate.addEventListener('click', (e) => { if (e.target === gate) close(); }); // click backdrop
        document.addEventListener('keydown', (e) => { if (!gate.hidden && e.key === 'Escape') { e.preventDefault(); close(); } });
    })();

    // If we just arrived here via a day-swipe, slide the new grid in from the swipe direction.
    (function slideInOnArrival() {
        let dir = null;
        try { dir = sessionStorage.getItem('op_slide'); if (dir) sessionStorage.removeItem('op_slide'); } catch (e) {}
        if (!dir || (window.TRACKER.view !== 'day' && window.TRACKER.view !== 'week')) return;
        const gw = document.querySelector('.grid-wrap');
        if (!gw) return;
        const from = (dir === 'next' ? window.innerWidth : -window.innerWidth);
        gw.style.transition = 'none';
        gw.style.transform = 'translateX(' + from + 'px)';
        requestAnimationFrame(() => requestAnimationFrame(() => {
            gw.style.transition = 'transform .2s ease-out';
            gw.style.transform = 'translateX(0)';
        }));
        setTimeout(() => { gw.style.transition = ''; gw.style.transform = ''; }, 300);
    })();
    const saveIndicator = document.getElementById('saveIndicator');
    const toolbarHint = document.getElementById('toolbarHint');
    const defaultHint = toolbarHint ? toolbarHint.textContent : '';

    // Group cells by row-key (one desk line, or one room's booking line) in DOM order.
    const rowsByKey = {};
    const orderedRowKeys = [];
    cells.forEach((cell) => {
        const key = cell.dataset.rowKey;
        if (!rowsByKey[key]) {
            rowsByKey[key] = [];
            orderedRowKeys.push(key);
        }
        rowsByKey[key].push(cell);
    });

    let selected = new Set();
    let activeCell = null;
    let anchorCell = null; // fixed corner for shift-click / shift-arrow range selection
    let isDragging = false;
    let dragKey = null;
    let dragAnchorIndex = null;
    let editorInput = null;
    let editorPrevText = ''; // text a cell held before its editor opened (for undo)
    let touchSelectMode = false; // mobile long-press multi-select mode
    let currentRevision = (window.TRACKER && typeof window.TRACKER.revision !== 'undefined')
        ? window.TRACKER.revision : null;

    function indexInRow(cell) {
        return rowsByKey[cell.dataset.rowKey].indexOf(cell);
    }

    function clearSelection() {
        selected.forEach((c) => c.classList.remove('selected'));
        selected.clear();
    }

    function setActive(cell) {
        if (activeCell) activeCell.classList.remove('active-cell');
        activeCell = cell;
        if (activeCell) activeCell.classList.add('active-cell');
    }

    function selectSingle(cell) {
        clearSelection();
        selected.add(cell);
        cell.classList.add('selected');
        anchorCell = cell;
        setActive(cell);
    }

    function selectRangeInRow(key, fromIndex, toIndex) {
        const row = rowsByKey[key];
        const lo = Math.min(fromIndex, toIndex);
        const hi = Math.max(fromIndex, toIndex);
        clearSelection();
        for (let i = lo; i <= hi; i++) {
            const c = row[i];
            selected.add(c);
            c.classList.add('selected');
        }
    }

    // Spreadsheet-style rectangular selection: every cell whose centre falls inside the
    // box spanning the anchor and the focus. Handles rows of different column widths.
    function selectRectTo(focus) {
        const a = anchorCell || focus;
        const ar = a.getBoundingClientRect();
        const fr = focus.getBoundingClientRect();
        const left = Math.min(ar.left, fr.left) - 1;
        const right = Math.max(ar.right, fr.right) + 1;
        const top = Math.min(ar.top, fr.top) - 1;
        const bottom = Math.max(ar.bottom, fr.bottom) + 1;
        clearSelection();
        cells.forEach((c) => {
            const r = c.getBoundingClientRect();
            const cx = r.left + r.width / 2;
            const cy = r.top + r.height / 2;
            if (cx >= left && cx <= right && cy >= top && cy <= bottom) {
                selected.add(c);
                c.classList.add('selected');
            }
        });
        setActive(focus);
    }

    // Neighbour lookups used by both plain moves and shift-extend.
    function neighborH(cell, delta) {
        const row = rowsByKey[cell.dataset.rowKey];
        const idx = indexInRow(cell) + delta;
        return (idx >= 0 && idx < row.length) ? row[idx] : null;
    }
    function neighborV(cell, dir) {
        const ri = orderedRowKeys.indexOf(cell.dataset.rowKey);
        const tri = ri + dir;
        if (tri < 0 || tri >= orderedRowKeys.length) return null;
        const targetCells = rowsByKey[orderedRowKeys[tri]];
        if (!targetCells || !targetCells.length) return null;
        const r = cell.getBoundingClientRect();
        const cx = r.left + r.width / 2;
        let best = targetCells[0], bestDist = Infinity;
        targetCells.forEach((c) => {
            const cr = c.getBoundingClientRect();
            const d = Math.abs((cr.left + cr.width / 2) - cx);
            if (d < bestDist) { bestDist = d; best = c; }
        });
        return best;
    }

    function showIndicator(text, isError) {
        saveIndicator.textContent = text;
        saveIndicator.classList.toggle('error', !!isError);
        saveIndicator.classList.add('show');
        clearTimeout(showIndicator._t);
        showIndicator._t = setTimeout(() => saveIndicator.classList.remove('show'), 1200);
    }

    function noteSaved(d) {
        showIndicator(d.ok ? 'Saved' : 'Error saving', !d.ok);
        // Record our own write so the poller doesn't treat it as someone else's change.
        if (d.ok && typeof d.revision !== 'undefined') {
            currentRevision = d.revision;
        }
    }

    function saveCellText(cell) {
        const text = cell.textContent;
        if (cell.dataset.kind === 'desk') {
            fetch('api/save_desk_cell.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    desk_id: cell.dataset.desk,
                    date: cell.dataset.date,
                    period: cell.dataset.period,
                    text: text,
                }),
            })
                .then((r) => r.json())
                .then(noteSaved)
                .catch(() => showIndicator('Error saving', true));
        } else {
            // booking cell: color is derived from content, not stored
            cell.classList.toggle('color-red', text.trim() !== '');
            fetch('api/save_booking_cell.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    room_id: cell.dataset.room,
                    date: cell.dataset.date,
                    hour: cell.dataset.hour,
                    text: text,
                }),
            })
                .then((r) => r.json())
                .then(noteSaved)
                .catch(() => showIndicator('Error saving', true));
        }
    }

    function saveBatchColor(cellList, color) {
        const payload = cellList.map((c) => ({
            desk_id: c.dataset.desk,
            date: c.dataset.date,
            period: c.dataset.period,
        }));
        fetch('api/save_batch_color.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cells: payload, color: color }),
        })
            .then((r) => r.json())
            .then(noteSaved)
            .catch(() => showIndicator('Error saving', true));
    }

    // ---- One-tap room booking with your own initials (stored per device) ----

    function getCookie(name) {
        const m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
        return m ? decodeURIComponent(m[1]) : '';
    }
    function setCookie(name, value, days) {
        const exp = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + exp + '; path=/; samesite=lax';
    }

    let myInitials = (getCookie('op_initials') || '').trim();

    const youChip = document.getElementById('youChip');
    const youInitialsEl = document.getElementById('youInitials');
    function renderChip() {
        if (youInitialsEl) youInitialsEl.textContent = myInitials || '—';
    }
    renderChip();

    function askInitials(preset) {
        const val = window.prompt('Your initials for one-tap room booking:', preset || myInitials || '');
        if (val === null) return false; // cancelled
        myInitials = val.trim().slice(0, 6);
        setCookie('op_initials', myInitials, 365);
        renderChip();
        return true;
    }

    if (youChip) {
        youChip.addEventListener('click', () => askInitials());
    }

    // Per-user palette toggle (standard vs colour-blind-friendly), saved on this device.
    // The disc shows the active palette's two status colours; a brief toast names it on tap.
    const paletteBtn = document.getElementById('paletteBtn');
    const paletteToast = document.getElementById('paletteToast');
    let paletteToastTimer = null;
    function showPaletteToast(on) {
        if (!paletteToast) return;
        paletteToast.textContent = on ? 'Accessible colours' : 'Standard colours';
        paletteToast.classList.add('show');
        clearTimeout(paletteToastTimer);
        paletteToastTimer = setTimeout(() => paletteToast.classList.remove('show'), 1500);
    }
    if (paletteBtn) {
        paletteBtn.addEventListener('click', () => {
            const on = document.body.classList.toggle('palette-cb');
            setCookie('op_palette', on ? 'cb' : '', 365);
            showPaletteToast(on);
        });
    }

    // Per-user light/dark toggle (overrides the OS default), saved on this device.
    const themeBtn = document.getElementById('themeBtn');
    const themeToast = document.getElementById('themeToast');
    let themeToastTimer = null;
    function showThemeToast(dark) {
        if (!themeToast) return;
        themeToast.textContent = dark ? 'Dark theme' : 'Light theme';
        themeToast.classList.add('show');
        clearTimeout(themeToastTimer);
        themeToastTimer = setTimeout(() => themeToast.classList.remove('show'), 1500);
    }
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const dark = document.documentElement.classList.toggle('theme-dark');
            setCookie('op_theme', dark ? 'dark' : 'light', 365);
            showThemeToast(dark);
        });
    }

    // Double-offices toggle: collapse the DO rooms for a compact "find a room" overview.
    // Per-user (cookie); the body class is already set server-side so there's no flash.
    const doToggle = document.getElementById('doToggle');
    const doToast = document.getElementById('doToast');
    let doToastTimer = null;
    function applyHideDo(hide, save) {
        document.body.classList.toggle('hide-do', hide);
        if (doToggle) doToggle.setAttribute('aria-pressed', String(!hide));
        if (save) setCookie('op_hide_do', hide ? '1' : '', 365);
        if (save && doToast) {
            doToast.textContent = hide ? 'Double offices hidden' : 'Double offices shown';
            doToast.classList.add('show');
            clearTimeout(doToastTimer);
            doToastTimer = setTimeout(() => doToast.classList.remove('show'), 1500);
        }
    }
    if (doToggle) {
        doToggle.addEventListener('click', () => applyHideDo(!document.body.classList.contains('hide-do'), true));
    }

    // Mobile: a vertical two-finger pinch toggles the double offices. Pinch in (fingers move
    // together) hides them; spread apart shows them. Only claims clearly-vertical pinches, so
    // ordinary (diagonal) pinch-zoom still works.
    (function verticalPinch() {
        let active = false, startSpread = 0, curSpread = 0;
        document.addEventListener('touchstart', (e) => {
            if (e.touches.length === 2) {
                const a = e.touches[0], b = e.touches[1];
                const dx = Math.abs(a.clientX - b.clientX), dy = Math.abs(a.clientY - b.clientY);
                active = dy > dx; // fingers stacked vertically
                startSpread = curSpread = dy;
            } else {
                active = false;
            }
        }, { passive: false });
        document.addEventListener('touchmove', (e) => {
            if (active && e.touches.length === 2) {
                curSpread = Math.abs(e.touches[0].clientY - e.touches[1].clientY);
                e.preventDefault(); // claim the vertical pinch (suppress zoom for this gesture)
            }
        }, { passive: false });
        document.addEventListener('touchend', (e) => {
            if (!active || e.touches.length >= 2) return;
            const delta = curSpread - startSpread;
            active = false;
            if (Math.abs(delta) > 60) applyHideDo(delta < 0, true); // pinch-in hides, spread shows
        }, { passive: false });
    })();

    // Tap a booking slot: book it with your initials, or clear it if it already holds yours.
    function quickBook(cell) {
        if (editorInput) { const ec = editorInput.closest('.cell'); commitEditor(ec, null); }
        clearSelection();
        setActive(cell);
        anchorCell = cell;

        if (!myInitials) {
            if (!askInitials()) return;      // first use: ask once, store in cookie
            if (!myInitials) return;         // still empty (blank entry) — do nothing
        }

        const before = cell.textContent;
        const current = cell.textContent.trim();
        if (current === '') {
            cell.textContent = myInitials;
            saveCellText(cell);
            recordOp('text', [{ cell, from: before, to: cell.textContent }]);
        } else if (current === myInitials) {
            cell.textContent = '';           // tapping your own booking clears it
            saveCellText(cell);
            recordOp('text', [{ cell, from: before, to: '' }]);
        } else {
            // Someone else's booking — leave it, just note who.
            flashHint('That slot is booked by ' + current + '. Double-click to edit it.');
        }
    }

    // ---- Selection (click & drag within a row) ----

    cells.forEach((cell) => {
        cell.addEventListener('mousedown', (e) => {
            // Clicking another cell mid-edit should SAVE the typed text, not discard it.
            if (editorInput) {
                const editing = editorInput.closest('.cell');
                commitEditor(editing, null);
            }
            e.preventDefault();
            // One-tap booking: a plain click on a meeting-room / table slot inserts your
            // initials (or clears them if the slot already holds yours). Desk cells and
            // modifier-clicks fall through to normal selection.
            if (cell.dataset.kind === 'booking' && !e.shiftKey && !e.metaKey && !e.ctrlKey) {
                quickBook(cell);
                return;
            }
            // Shift-click extends the current selection from the anchor (spreadsheet style).
            if (e.shiftKey && (anchorCell || activeCell)) {
                if (!anchorCell) anchorCell = activeCell;
                selectRectTo(cell);
                return;
            }
            // Cmd/Ctrl-click toggles a single cell in or out of the selection, keeping the rest
            // (discontiguous multi-select). No drag is started.
            if (e.metaKey || e.ctrlKey) {
                if (selected.has(cell)) {
                    selected.delete(cell);
                    cell.classList.remove('selected');
                    if (activeCell === cell) {
                        const rest = selected.size ? Array.from(selected)[selected.size - 1] : null;
                        setActive(rest);
                        anchorCell = rest;
                    }
                } else {
                    selected.add(cell);
                    cell.classList.add('selected');
                    setActive(cell);
                    anchorCell = cell;
                }
                return;
            }
            isDragging = true;
            dragKey = cell.dataset.rowKey;
            dragAnchorIndex = indexInRow(cell);
            selectSingle(cell);
        });

        cell.addEventListener('dblclick', () => {
            openEditor(cell, cell.textContent, false);
        });
    });

    document.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        const el = document.elementFromPoint(e.clientX, e.clientY);
        if (!el) return;
        const cell = el.closest('.cell');
        if (!cell || cell.dataset.rowKey !== dragKey) return;
        const idx = indexInRow(cell);
        selectRangeInRow(dragKey, dragAnchorIndex, idx);
        setActive(rowsByKey[dragKey][dragAnchorIndex]);
    });

    document.addEventListener('mouseup', () => {
        isDragging = false;
    });

    // ---- Keyboard ----

    function gotoView(v) {
        window.location = '?view=' + encodeURIComponent(v) + '&ref=' + encodeURIComponent(window.TRACKER.ref);
    }
    function gotoRef(ref) {
        // Staying in the same view (incl. monthx) is carried in the view itself.
        window.location = '?view=' + encodeURIComponent(window.TRACKER.view) + '&ref=' + encodeURIComponent(ref);
    }
    function pageScroll(dir) {
        const gw = document.querySelector('.grid-wrap');
        const amount = dir * Math.round((gw ? gw.clientHeight : window.innerHeight) * 0.85);
        if (gw) gw.scrollBy({ top: amount, behavior: 'smooth' });
        else window.scrollBy({ top: amount, behavior: 'smooth' });
    }

    // Plain arrow / Tab: move the single-cell selection.
    function moveVertical(fromCell, dir) {
        const next = neighborV(fromCell, dir);
        if (next) { selectSingle(next); next.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
    }

    // Arrow with optional Shift: Shift extends the rectangular selection, plain moves.
    function arrowMove(next, extend) {
        if (!next) return;
        if (extend) selectRectTo(next);
        else selectSingle(next);
        next.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    document.addEventListener('keydown', (e) => {
        if (editorInput) return; // the inline editor handles its own keys

        const mod = e.metaKey || e.ctrlKey;

        // ---- Global shortcuts (Cmd on Mac / Ctrl elsewhere) ----
        // A few Cmd/Ctrl combos are hard-reserved by the browser and never reach the page
        // (Cmd/Ctrl+T new tab, +N new window, +W close). Cmd/Ctrl+1..9 (switch tab) DO reach
        // the page and are cancelable, so the view shortcuts below work. "Today" can't use the
        // reserved Cmd/Ctrl+T, so it's on Home and Cmd/Ctrl+. instead.
        // Undo / redo (this session). Redo = Cmd/Ctrl+Shift+Z or Cmd/Ctrl+Y.
        if (mod && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); if (e.shiftKey) redo(); else undo(); return; }
        if (mod && (e.key === 'y' || e.key === 'Y') && !e.altKey) { e.preventDefault(); redo(); return; }

        if (mod && !e.altKey && !e.shiftKey) {
            const k = e.key.toLowerCase();
            // Colors: only override the browser default when desk cells are selected,
            // so Cmd/Ctrl+F, +O, +C otherwise behave normally (find, open, copy).
            if (k === 'f') { if (selected.size) { e.preventDefault(); applyColor('green'); } return; }
            if (k === 'o') { if (selected.size) { e.preventDefault(); applyColor('red'); } return; }
            if (k === 'c') { if (selected.size) { e.preventDefault(); applyColor('none'); } return; }
            // Views: Cmd/Ctrl + 1 / 2 / 3 / 4.
            if (k === '1' || e.code === 'Digit1') { e.preventDefault(); gotoView('day'); return; }
            if (k === '2' || e.code === 'Digit2') { e.preventDefault(); gotoView('week'); return; }
            if (k === '3' || e.code === 'Digit3') { e.preventDefault(); gotoView('month'); return; }
            if (k === '4' || e.code === 'Digit4') { e.preventDefault(); gotoView('monthx'); return; }
            // Toggle the double-offices display (compact room overview).
            if (k === 'e') { e.preventDefault(); applyHideDo(!document.body.classList.contains('hide-do'), true); return; }
            // Today: Cmd/Ctrl+.  (Cmd/Ctrl+T is reserved by the browser and can't be caught).
            if (k === '.') { e.preventDefault(); gotoRef(window.TRACKER.todayRef); return; }
            // Prev / next period: Cmd/Ctrl + arrow.
            if (e.key === 'ArrowRight') { e.preventDefault(); gotoRef(window.TRACKER.nextRef); return; }
            if (e.key === 'ArrowLeft') { e.preventDefault(); gotoRef(window.TRACKER.prevRef); return; }
            // Scroll the page when the table is taller than the window.
            if (e.key === 'ArrowUp') { e.preventDefault(); pageScroll(-1); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); pageScroll(1); return; }
            return; // leave other modifier combos to the browser
        }

        // "Home" (no modifier) jumps to today — a reliable, conflict-free alternative
        // that the browser always delivers to the page.
        if (!mod && e.key === 'Home') {
            e.preventDefault();
            gotoRef(window.TRACKER.todayRef);
            return;
        }

        // ---- Cell navigation / editing (no modifier) ----
        if (!activeCell) return;

        if (e.key === 'Enter' || e.key === 'F2') {
            e.preventDefault();
            openEditor(activeCell, activeCell.textContent, true);
        } else if (e.key === 'Tab') {
            e.preventDefault();
            moveActive(activeCell, e.shiftKey ? -1 : 1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            arrowMove(neighborH(activeCell, 1), e.shiftKey);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            arrowMove(neighborH(activeCell, -1), e.shiftKey);
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            arrowMove(neighborV(activeCell, 1), e.shiftKey);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            arrowMove(neighborV(activeCell, -1), e.shiftKey);
        } else if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            const items = Array.from(selected).map((c) => ({ cell: c, from: c.textContent, to: '' }));
            selected.forEach((c) => {
                c.textContent = '';
                saveCellText(c);
            });
            recordOp('text', items);
        } else if (e.key === 'Escape') {
            clearSelection();
            setActive(null);
        } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            openEditor(activeCell, '', false, e.key);
        }
    });

    // ---- Paste ----

    document.addEventListener('paste', (e) => {
        if (editorInput || !activeCell) return;
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text');
        if (text == null) return;

        const parts = text.split(/\r\n|\r|\n|\t/).filter((p) => p.length > 0);
        const targets = selected.size > 1 ? Array.from(selected) : [activeCell];

        if (parts.length > 1 && targets.length > 1) {
            const items = targets.map((c, i) => ({ cell: c, from: c.textContent, to: (parts[i] !== undefined ? parts[i] : '') }));
            targets.forEach((c, i) => {
                const v = parts[i] !== undefined ? parts[i] : '';
                c.textContent = v;
                saveCellText(c);
            });
            recordOp('text', items);
        } else {
            const v = text.trim();
            const items = targets.map((c) => ({ cell: c, from: c.textContent, to: v }));
            targets.forEach((c) => {
                c.textContent = v;
                saveCellText(c);
            });
            recordOp('text', items);
        }
    });

    // ---- Inline editor overlay ----

    function openEditor(cell, initialValue, keepCursorAtEnd, forceChar) {
        closeEditor(false);
        editorPrevText = cell.textContent; // remember for undo (cleared below)
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'cell-editor';
        input.value = forceChar !== undefined ? forceChar : initialValue;
        cell.textContent = '';
        cell.appendChild(input);
        editorInput = input;
        input.focus();
        if (keepCursorAtEnd || forceChar !== undefined) {
            const len = input.value.length;
            input.setSelectionRange(len, len);
        } else {
            input.select();
        }

        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                commitEditor(cell, moveActive.bind(null, cell, 1));
            } else if (ev.key === 'Tab') {
                ev.preventDefault();
                commitEditor(cell, moveActive.bind(null, cell, ev.shiftKey ? -1 : 1));
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                closeEditor(true);
            }
            ev.stopPropagation();
        });

        input.addEventListener('blur', () => {
            commitEditor(cell, null);
        });
    }

    function commitEditor(cell, after) {
        if (!editorInput) return;
        const value = editorInput.value;
        editorInput = null;

        // Fill-down: if several cells are selected, put the same text in each of them
        // (limited to the same kind as the edited cell, so desk edits don't spill into
        // meeting-room slots and vice-versa). Otherwise just this cell.
        let targets = [cell];
        if (selected.size > 1 && selected.has(cell)) {
            targets = Array.from(selected).filter((c) => c.dataset.kind === cell.dataset.kind);
            if (targets.length === 0) targets = [cell];
        }
        const items = targets.map((c) => ({
            cell: c,
            from: (c === cell) ? editorPrevText : c.textContent, // edited cell's text was cleared on open
            to: value,
        }));
        targets.forEach((c) => {
            c.textContent = value;
            saveCellText(c);
        });
        recordOp('text', items);

        // Move to the next cell only for a single-cell edit; a multi-fill keeps the block.
        if (after && targets.length === 1) after();
    }

    function closeEditor(revert) {
        if (!editorInput) return;
        const input = editorInput;
        editorInput = null;
        input.remove();
    }

    function moveActive(fromCell, delta) {
        const next = neighborH(fromCell, delta);
        if (next) selectSingle(next);
    }

    // ---- Color (desk cells only — booking cells auto-color from content) ----

    function flashHint(msg) {
        if (!toolbarHint) return;
        toolbarHint.textContent = msg;
        clearTimeout(toolbarHint._t);
        toolbarHint._t = setTimeout(() => { toolbarHint.textContent = defaultHint; }, 3500);
    }

    // ---- Undo / redo (this session, this browser; a few steps) ----
    const undoStack = [];
    const redoStack = [];
    const UNDO_MAX = 40;
    let restoring = false; // true while an undo/redo is re-applying, so it isn't itself recorded

    function cellColorOf(c) {
        return c.classList.contains('color-green') ? 'green'
            : c.classList.contains('color-red') ? 'red' : 'none';
    }
    function setCellColorClass(c, color) {
        c.classList.remove('color-green', 'color-red');
        if (color === 'green') c.classList.add('color-green');
        else if (color === 'red') c.classList.add('color-red');
    }
    // Record one operation. items: [{cell, from, to}]. No-op items (from === to) are dropped.
    function recordOp(type, items) {
        if (restoring) return;
        const changed = items.filter((it) => it.from !== it.to);
        if (!changed.length) return;
        undoStack.push({ type, items: changed });
        if (undoStack.length > UNDO_MAX) undoStack.shift();
        redoStack.length = 0; // a fresh action invalidates the redo trail
    }
    function applyOp(op, key) { // key = 'from' (undo) or 'to' (redo)
        restoring = true;
        try {
            if (op.type === 'color') {
                op.items.forEach((it) => setCellColorClass(it.cell, it[key]));
                const groups = {};
                op.items.forEach((it) => { (groups[it[key]] = groups[it[key]] || []).push(it.cell); });
                Object.keys(groups).forEach((col) => saveBatchColor(groups[col], col));
            } else {
                op.items.forEach((it) => { it.cell.textContent = it[key]; saveCellText(it.cell); });
            }
        } finally {
            restoring = false;
        }
    }
    function undo() {
        const op = undoStack.pop();
        if (!op) { showIndicator('Nothing to undo'); return; }
        redoStack.push(op);
        applyOp(op, 'from');
        showIndicator('Undone');
    }
    function redo() {
        const op = redoStack.pop();
        if (!op) { showIndicator('Nothing to redo'); return; }
        undoStack.push(op);
        applyOp(op, 'to');
        showIndicator('Redone');
    }

    function applyColor(color) {
        if (selected.size === 0) {
            showIndicator('Select one or more desk cells first', true);
            return;
        }
        const all = Array.from(selected);
        const deskCells = all.filter((c) => c.dataset.kind === 'desk');
        const skipped = all.length - deskCells.length;

        if (deskCells.length === 0) {
            flashHint('Meeting-room / table slots color automatically — type initials into them instead.');
            return;
        }

        const items = deskCells.map((c) => ({ cell: c, from: cellColorOf(c), to: color }));
        deskCells.forEach((c) => {
            c.classList.remove('color-green', 'color-red');
            if (color === 'green') c.classList.add('color-green');
            if (color === 'red') c.classList.add('color-red');
        });
        saveBatchColor(deskCells, color);
        recordOp('color', items);

        if (skipped > 0) {
            flashHint(skipped + ' meeting-room cell(s) in the selection were left untouched (they color automatically).');
        }
    }

    document.querySelectorAll('.color-btn').forEach((btn) => {
        btn.addEventListener('click', () => applyColor(btn.dataset.color));
    });

    // Click outside the grid clears selection
    document.addEventListener('mousedown', (e) => {
        if (!e.target.closest('.grid') && !e.target.closest('.toolbar') && !e.target.closest('.select-bar')) {
            if (touchSelectMode) { exitSelectMode(); return; }
            clearSelection();
            setActive(null);
            anchorCell = null;
        }
    });

    // ---- Auto-refresh: keep the grid in sync when others make changes ----
    //
    // Three measures keep this cheap for tabs left open for days:
    //  1. Poll only a tiny "revision" counter each minute; fetch the full cell
    //     state only when that counter has actually moved.
    //  2. Skip polling entirely while the tab is hidden (backgrounded / laptop asleep).
    //  3. Skip polling while the user is idle; resume on the next interaction.
    // Returning to the tab or interacting after idle triggers one immediate catch-up.

    const POLL_MS = 60000;      // check once a minute
    const IDLE_MS = 3 * 60000;  // treat as idle after 3 minutes of no interaction
    let lastActivity = Date.now();

    function isIdle() { return (Date.now() - lastActivity) >= IDLE_MS; }

    function refreshCells() {
        if (editorInput || isDragging) return; // don't yank content from under the user
        const editingCell = editorInput ? editorInput.closest('.cell') : null;
        const t = window.TRACKER;
        fetch('api/state.php?start=' + encodeURIComponent(t.rangeStart) + '&end=' + encodeURIComponent(t.rangeEnd), { cache: 'no-store' })
            .then((r) => r.json())
            .then((state) => {
                if (!state || !state.ok) return;
                const deskMap = {};
                state.desks.forEach((d) => { deskMap[d.desk_id + '|' + d.date + '|' + d.period] = d; });
                const bookMap = {};
                state.bookings.forEach((b) => { bookMap[b.room_id + '|' + b.date + '|' + b.hour] = b; });

                cells.forEach((cell) => {
                    if (cell === editingCell) return;
                    if (cell.dataset.kind === 'desk') {
                        const d = deskMap[cell.dataset.desk + '|' + cell.dataset.date + '|' + cell.dataset.period];
                        const text = d ? d.text : '';
                        const color = d ? d.color : 'none';
                        if (cell.textContent !== text) cell.textContent = text;
                        cell.classList.remove('color-green', 'color-red');
                        if (color === 'green') cell.classList.add('color-green');
                        else if (color === 'red') cell.classList.add('color-red');
                    } else if (cell.dataset.kind === 'booking') {
                        const b = bookMap[cell.dataset.room + '|' + cell.dataset.date + '|' + cell.dataset.hour];
                        const text = b ? b.text : '';
                        if (cell.textContent !== text) cell.textContent = text;
                        cell.classList.toggle('color-red', text.trim() !== '');
                    }
                });
                if (typeof state.revision !== 'undefined') currentRevision = state.revision;
            })
            .catch(() => {});
    }

    function pollNow() {
        fetch('api/revision.php', { cache: 'no-store' })
            .then((r) => {
                if (r.status === 401) { window.location = 'login.php'; return null; }
                return r.json();
            })
            .then((d) => {
                if (!d || typeof d.revision === 'undefined') return;
                if (currentRevision === null) { currentRevision = d.revision; return; }
                if (d.revision !== currentRevision) {
                    currentRevision = d.revision;
                    refreshCells();
                }
            })
            .catch(() => {});
    }

    function pollTick() {
        if (document.hidden || isIdle()) return; // stay quiet when nobody's watching
        pollNow();
    }

    function markActivity() {
        const wasIdle = isIdle();
        lastActivity = Date.now();
        if (wasIdle && !document.hidden) pollNow(); // immediate catch-up on resume
    }

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach((ev) =>
        document.addEventListener(ev, markActivity, { passive: true }));

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { lastActivity = Date.now(); pollNow(); }
    });

    setInterval(pollTick, POLL_MS);

    // ---- Touch long-press multi-select (mobile) ----
    // Long-press a desk cell to enter selection mode; then tap desk cells to add/remove.
    // A bottom bar shows the count and the apply actions. Booking cells and non-desk taps
    // are ignored while in mode. Movement cancels the press (so scroll/swipe still work).
    const selectBar = document.getElementById('selectBar');
    const selectCount = document.getElementById('selectCount');

    function updateSelectBar() {
        if (selectCount) selectCount.textContent = selected.size + ' selected';
        if (selectBar) selectBar.hidden = false;
    }
    function hideSelectBar() { if (selectBar) selectBar.hidden = true; }
    function enterSelectMode(cell) {
        touchSelectMode = true;
        clearSelection();
        selected.add(cell);
        cell.classList.add('selected');
        anchorCell = cell;
        setActive(cell);
        cell.classList.add('lp-flash');
        setTimeout(() => cell.classList.remove('lp-flash'), 300);
        updateSelectBar();
    }
    function toggleTouchCell(cell) {
        if (selected.has(cell)) { selected.delete(cell); cell.classList.remove('selected'); }
        else { selected.add(cell); cell.classList.add('selected'); setActive(cell); }
        if (selected.size === 0) exitSelectMode();
        else updateSelectBar();
    }
    function exitSelectMode() {
        touchSelectMode = false;
        clearSelection();
        setActive(null);
        anchorCell = null;
        hideSelectBar();
    }

    (function longPressSetup() {
        let lpTimer = null, lpX = 0, lpY = 0, lpFired = false;
        const LONG_MS = 500, MOVE_CANCEL = 10;
        const clearTimer = () => { if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; } };

        document.addEventListener('touchstart', (e) => {
            lpFired = false;
            if (e.touches.length !== 1) { clearTimer(); return; }
            const t = e.touches[0];
            const cell = e.target.closest ? e.target.closest('.cell') : null;
            if (cell && cell.dataset.kind === 'desk') {
                lpX = t.clientX; lpY = t.clientY;
                clearTimer();
                lpTimer = setTimeout(() => { lpTimer = null; lpFired = true; enterSelectMode(cell); }, LONG_MS);
            }
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!lpTimer) return;
            const t = e.touches[0];
            if (Math.abs(t.clientX - lpX) > MOVE_CANCEL || Math.abs(t.clientY - lpY) > MOVE_CANCEL) clearTimer();
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            clearTimer();
            if (lpFired) { e.preventDefault(); lpFired = false; return; } // press already handled
            if (touchSelectMode) {
                const cell = e.target.closest ? e.target.closest('.cell') : null;
                if (cell && cell.dataset.kind === 'desk') { e.preventDefault(); toggleTouchCell(cell); return; }
                if (e.target.closest && e.target.closest('.grid')) { e.preventDefault(); return; } // ignore booking taps in mode
            }
        }, { passive: false });
    })();

    if (selectBar) {
        // Handle both click (mouse) and touchend (touch) so the action is reliable on phones,
        // where the synthesized click after a tap can be swallowed. preventDefault on touchend
        // suppresses the ghost click so the handler runs exactly once.
        selectBar.querySelectorAll('.sb-btn').forEach((btn) => {
            const run = (e) => { if (e.cancelable) e.preventDefault(); applyColor(btn.dataset.color); exitSelectMode(); };
            btn.addEventListener('click', run);
            btn.addEventListener('touchend', run, { passive: false });
        });
        const cancelBtn = document.getElementById('selectCancel');
        if (cancelBtn) {
            const run = (e) => { if (e.cancelable) e.preventDefault(); exitSelectMode(); };
            cancelBtn.addEventListener('click', run);
            cancelBtn.addEventListener('touchend', run, { passive: false });
        }
    }

    // ---- Day / Week view: swipe left/right on the grid to change the day or week ----
    // Axis-locked so vertical scroll and taps are untouched; the grid follows the finger, then
    // slides out and navigates (or snaps back) on release. Touch-only, so desktop mouse
    // drag-select is unaffected. (Month views keep the buttons.)
    if (window.TRACKER.view === 'day' || window.TRACKER.view === 'week') {
        const gw = document.querySelector('.grid-wrap');
        if (gw) {
            let sx = 0, sy = 0, axis = null, active = false;
            const H_THRESH = 50; // min horizontal travel (px) to commit to a day change

            function resetTransform(animate) {
                gw.style.transition = animate ? 'transform .18s ease-out' : 'none';
                gw.style.transform = 'translateX(0)';
                setTimeout(() => { gw.style.transition = ''; gw.style.transform = ''; }, animate ? 200 : 0);
            }

            gw.addEventListener('touchstart', (e) => {
                if (touchSelectMode || e.touches.length !== 1) { active = false; return; }
                active = true; axis = null;
                sx = e.touches[0].clientX; sy = e.touches[0].clientY;
                gw.style.transition = 'none';
            }, { passive: true });

            gw.addEventListener('touchmove', (e) => {
                if (!active || e.touches.length !== 1) return;
                const dx = e.touches[0].clientX - sx;
                const dy = e.touches[0].clientY - sy;
                if (axis === null) {
                    if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
                    axis = Math.abs(dx) > Math.abs(dy) ? 'h' : 'v';
                }
                if (axis === 'h') {
                    e.preventDefault();                     // claim the gesture (no scroll / drag-select)
                    gw.style.transform = 'translateX(' + dx + 'px)'; // follow the finger
                }
            }, { passive: false });

            gw.addEventListener('touchend', (e) => {
                if (!active) return;
                active = false;
                if (axis !== 'h') return;                  // vertical scroll or a tap — leave alone
                e.preventDefault();                        // suppress the synthesized click
                const dx = e.changedTouches[0].clientX - sx;
                if (Math.abs(dx) >= H_THRESH) {
                    const dir = dx < 0 ? 'next' : 'prev';
                    try { sessionStorage.setItem('op_slide', dir); } catch (err) {}
                    gw.style.transition = 'transform .16s ease-in';
                    gw.style.transform = 'translateX(' + (dx < 0 ? -window.innerWidth : window.innerWidth) + 'px)';
                    setTimeout(() => {
                        gotoRef(dx < 0 ? window.TRACKER.nextRef : window.TRACKER.prevRef);
                    }, 150);
                } else {
                    resetTransform(true);                  // not far enough — snap back
                }
            }, { passive: false });
        }
    }
})();
