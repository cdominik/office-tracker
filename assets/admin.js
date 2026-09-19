(function () {
    'use strict';

    // Take control of scroll restoration so our save/restore across form reloads wins.
    if ('scrollRestoration' in history) {
        try { history.scrollRestoration = 'manual'; } catch (e) {}
    }

    const saveIndicator = document.getElementById('saveIndicator');

    function showIndicator(text, isError) {
        if (!saveIndicator) return;
        saveIndicator.textContent = text;
        saveIndicator.classList.toggle('error', !!isError);
        saveIndicator.classList.add('show');
        clearTimeout(showIndicator._t);
        showIndicator._t = setTimeout(() => saveIndicator.classList.remove('show'), 1200);
    }

    function post(payload, okField) {
        fetch('api/admin_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then((r) => {
                if (r.status === 401) { window.location = 'login.php'; return null; }
                return r.json();
            })
            .then((d) => {
                if (!d) return;
                showIndicator(d.ok ? 'Saved' : (d.error || 'Error saving'), !d.ok);
                if (d.ok && okField) okField.classList.add('just-saved');
                if (okField) setTimeout(() => okField.classList.remove('just-saved'), 900);
            })
            .catch(() => showIndicator('Error saving', true));
    }

    // Text / number fields: save when the value changes and focus leaves (or Enter).
    document.querySelectorAll('.auto-field').forEach((input) => {
        let lastSaved = input.value;

        function maybeSave() {
            const val = input.value;
            if (val === lastSaved) return;
            // Don't save an empty room number (it must stay set).
            if (input.dataset.field === 'room_number' && val.trim() === '') {
                input.value = lastSaved;
                return;
            }
            lastSaved = val;
            const payload = { field: input.dataset.field, value: val };
            if (input.dataset.roomId) payload.room_id = input.dataset.roomId;
            if (input.dataset.deskId) payload.desk_id = input.dataset.deskId;
            post(payload, input);
        }

        input.addEventListener('change', maybeSave); // fires on blur if changed
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        });
    });

    // Checkboxes (visibility + access control): save immediately on toggle.
    document.querySelectorAll('.auto-flag').forEach((box) => {
        box.addEventListener('change', () => {
            post({ field: 'flag', key: box.dataset.key, value: box.checked ? 1 : 0 }, box.closest('label'));
        });
    });

    // Per-room "Show" checkboxes: hide/show an individual room in the tracker.
    document.querySelectorAll('.auto-roomvis').forEach((box) => {
        box.addEventListener('change', () => {
            post({ field: 'room_visible', room_id: box.dataset.roomId, value: box.checked ? 1 : 0 }, box.closest('label'));
        });
    });

    // Maintenance: delete data older than one month (with confirmation).
    const purgeBtn = document.getElementById('purgeBtn');
    if (purgeBtn) {
        purgeBtn.addEventListener('click', () => {
            if (!confirm('Delete all presence and booking data older than one month? This cannot be undone.')) return;
            fetch('api/admin_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ field: 'purge_old' }),
            })
                .then((r) => { if (r.status === 401) { window.location = 'login.php'; return null; } return r.json(); })
                .then((d) => {
                    if (!d) return;
                    if (!d.ok) { showIndicator(d.error || 'Error', true); return; }
                    showIndicator(d.deleted > 0 ? ('Deleted ' + d.deleted + ' old entries') : 'No old data to delete');
                })
                .catch(() => showIndicator('Error deleting', true));
        });
    }

    // After adding a DO/SO room, land the cursor in its first desk-name field.
    // Otherwise, keep the scroll position across the full-page actions that reload
    // (delete / move / add of a deskless room), so the page doesn't jump to the top.
    try {
        const KEY = 'admin_scroll';
        document.querySelectorAll('form').forEach((f) => {
            f.addEventListener('submit', () => {
                try { sessionStorage.setItem(KEY, String(window.scrollY)); } catch (e) {}
            });
        });

        const focusDesk = new URLSearchParams(window.location.search).get('focus_desk');
        if (focusDesk) {
            sessionStorage.removeItem(KEY); // don't fight the focus with a restore
            const field = document.querySelector('.desk-name-input[data-desk-id="' + focusDesk + '"]');
            if (field) {
                const go = () => { field.scrollIntoView({ block: 'center' }); field.focus(); };
                go();
                window.addEventListener('load', go);
                requestAnimationFrame(go);
                setTimeout(go, 60);
            }
            // Clean the param so a manual refresh doesn't re-focus.
            if (window.history.replaceState) {
                window.history.replaceState({}, '', 'admin.php');
            }
        } else {
            const saved = sessionStorage.getItem(KEY);
            if (saved !== null) {
                const y = parseInt(saved, 10) || 0;
                sessionStorage.removeItem(KEY);
                const restore = () => window.scrollTo(0, y);
                restore();
                window.addEventListener('load', restore);
                requestAnimationFrame(restore);
                setTimeout(restore, 60);
            }
        }
    } catch (e) {}
})();
