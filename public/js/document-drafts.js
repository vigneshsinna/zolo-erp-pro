/*
 * Server-side draft tabs for every document entry page (one engine, one adapter per page).
 *
 * Owns: dirty detection, draft tabs (create, autosave on every edit, switch, discard, conflict handling, the 10-tab
 * limit), "new document" (the current document is saved as a draft, then a fresh one starts), the one-shot ?new=1 and
 * ?draft=ID starts, and a best-effort save when the page is hidden.
 *
 * The page adapter owns everything about its form: snapshot / restore / reset, which events belong to the document,
 * and how a fresh document starts. A draft is an unfinished workspace state only: it never posts stock, accounting,
 * payments or a document number. Pages keep posting through their own forms; the server drops the draft on success.
 *
 * Adapter: {kind, label, baseUrl, enabled, form?, snapshot(), restore(payload), reset(options), party(), focus(),
 *   inDocument(target), rows?, handles(entry), stable?(state), empty?(state), closed?(), idle?(), legacy?(payload),
 *   foreign?(payload) -> url when the draft must open on another page, startNew?() -> page-owned new start,
 *   listOnly? (a register page that only lists the drafts), localShortcuts?}
 */
(() => {
    'use strict';
    const $ = window.jQuery;
    if (!$ || window.ZoloDocumentDrafts) return;

    function attach(adapter) {
        const enabled = adapter.enabled !== false;
        const listOnly = !!adapter.listOnly;
        const form = adapter.form || null;
        const workspace = {kind: adapter.kind, adapter};
        const snapshot = () => adapter.snapshot();
        const stable = adapter.stable || (state => JSON.stringify([state.form.fields, state.form.lines]));
        const hash = () => (listOnly ? '' : stable(snapshot()));

        /* -------------------------------------------------------------------- tabs */
        const tabs = [];
        let activeId = null;
        let untabbedBaseline = '';
        let counter = 0;
        let limit = 10;
        let inflight = Promise.resolve(true);
        let muted = false;
        let leaving = false;
        // Pages keep filling hidden fields after a form is shown (series previews, pickers, totals). Only a real user
        // edit since the form was last loaded makes it worth saving, so those never turn into phantom blank drafts.
        let edited = false;
        const byId = id => tabs.find(tab => tab.id === id) || null;
        const current = () => byId(activeId);
        const blankTab = () => ({id: 'tab-' + (++counter), draftId: null, version: 0, title: '', partyName: '', snapshot: null, baseline: '',
            conflict: false, blocking: [], warnings: []});
        // Row changes made while muted (restore, reset) are dropped from the observer queue, so only user-driven ones count.
        let rowObserver = null;
        const unmute = () => { muted = false; if (rowObserver) rowObserver.takeRecords(); };
        const mute = fn => { muted = true; try { return fn(); } finally { unmute(); } };

        function markClean() {
            const tab = current();
            if (tab) tab.baseline = hash(); else untabbedBaseline = hash();
            edited = false;
        }
        function isDirty() {
            if (listOnly || !edited) return false;
            const tab = current();
            return hash() !== (tab ? tab.baseline : untabbedBaseline);
        }

        const strip = document.getElementById('document-tabs');
        const list = document.getElementById('document-tab-list');
        const banner = document.getElementById('document-draft-banner');
        const statusLine = document.getElementById('document-tab-status');
        let statusTimer = null;
        function status(text, failed = false) {
            if (!statusLine) return;
            statusLine.textContent = text;
            statusLine.classList.toggle('is-error', failed);
            clearTimeout(statusTimer);
            if (text && !failed) statusTimer = setTimeout(() => { statusLine.textContent = ''; }, 4000);
        }

        function showBanner(text, actions = [], failed = true) {
            if (!banner) { status(text, failed); return; }
            banner.hidden = !text;
            banner.className = 'document-draft-banner ' + (failed ? 'is-error' : 'is-info');
            banner.replaceChildren();
            if (!text) return;
            const message = document.createElement('span');
            message.textContent = text;
            banner.appendChild(message);
            actions.forEach(([label, handler]) => {
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-sm btn-outline-secondary'; button.textContent = label;
                button.addEventListener('click', handler);
                banner.appendChild(button);
            });
        }

        function label(tab) {
            return (tab.title || (tab.draftId ? 'Draft #' + tab.draftId : 'New draft')) + (tab.partyName ? ' · ' + tab.partyName : '');
        }

        function render() {
            if (!list) return;
            if (strip) strip.hidden = !enabled;
            list.replaceChildren();
            tabs.forEach(tab => {
                const item = document.createElement('div');
                item.className = 'document-tab' + (tab.id === activeId ? ' is-active' : '') + (tab.conflict ? ' has-conflict' : '');
                item.setAttribute('role', 'presentation');
                const open = document.createElement('button');
                open.type = 'button'; open.className = 'document-tab-open'; open.setAttribute('role', 'tab');
                open.setAttribute('aria-selected', tab.id === activeId ? 'true' : 'false');
                open.textContent = label(tab); open.title = label(tab);
                open.addEventListener('click', () => { workspace.openTab(tab.id); });
                const close = document.createElement('button');
                close.type = 'button'; close.className = 'document-tab-close';
                close.setAttribute('aria-label', 'Discard ' + (tab.title || 'draft')); close.title = 'Discard this draft'; close.textContent = '×';
                close.addEventListener('click', () => { workspace.discardTab(tab.id); });
                item.append(open, close);
                list.appendChild(item);
            });
            const add = document.getElementById('document-tab-add');
            if (add) add.disabled = tabs.length >= limit;
            const count = document.getElementById('document-tab-count');
            if (count) count.textContent = tabs.length + '/' + limit;
        }

        function setDraftId(id) {
            if (!form) return;
            $(form).find('[name="draft_id"]').remove();
            if (id) $('<input type="hidden" name="draft_id">').val(id).appendTo(form);
        }

        function limitMessage() {
            return 'You already have ' + limit + ' open ' + adapter.label + ' drafts. Close or post one before starting another.';
        }

        const token = () => $('meta[name="csrf-token"]').attr('content') || (form && $(form).find('[name="_token"]').val()) || '';
        function request(method, path, body) {
            const options = {url: adapter.baseUrl + path, method, dataType: 'json', headers: {'X-CSRF-TOKEN': token()}};
            if (body !== undefined) {
                options.contentType = 'application/json';
                options.data = JSON.stringify({...body, _token: token()});
            }
            return $.ajax(options);
        }
        const messageOf = xhr => (xhr && xhr.responseJSON && (xhr.responseJSON.errors && Object.values(xhr.responseJSON.errors)[0][0] || xhr.responseJSON.message)) || 'Unable to complete this action.';

        /** A tab always exists for a form with changes, so edits can never live outside a persisted workspace. */
        function ensureTab() {
            let tab = current();
            if (!tab) {
                tab = blankTab(); tab.baseline = untabbedBaseline;
                tabs.push(tab); activeId = tab.id;
            }
            return tab;
        }

        /** Persist the active tab. Returns true when the server holds what is on screen (or there is nothing to keep). */
        function saveActive({explicit = false} = {}) {
            if (!enabled || listOnly) return Promise.resolve(false);
            inflight = inflight.catch(() => false).then(() => persist(explicit));
            return inflight;
        }

        function persist(explicit) {
            if (!explicit && !edited) return Promise.resolve(true);
            const state = hash();
            let tab = current();
            if (!tab) {
                if (state === untabbedBaseline && !explicit) return Promise.resolve(true);
                if (tabs.length >= limit) { showBanner(limitMessage()); return Promise.resolve(false); }
                tab = ensureTab();
            }
            if (tab.discarded) return Promise.resolve(true);
            if (tab.conflict) return Promise.resolve(false);
            if (tab.blocking.length) { showBanner(tab.blocking.join(' ')); return Promise.resolve(false); }
            if (!explicit && state === tab.baseline) return Promise.resolve(true);
            if (!tab.draftId && tabs.filter(other => other.draftId).length >= limit) { showBanner(limitMessage()); return Promise.resolve(false); }
            const sent = snapshot();
            // Autosave never creates a draft for a form holding only its defaults (no party, no item).
            if (!tab.draftId && !explicit && adapter.empty && adapter.empty(sent)) { tab.baseline = stable(sent); edited = false; return Promise.resolve(true); }
            return new Promise(resolve => {
                request('POST', '/drafts', {payload: sent, id: tab.draftId, version: tab.version || 0, ...adapter.party()}).done(response => {
                    const saved = response.data;
                    tab.draftId = saved.id; tab.version = saved.version; tab.title = saved.title;
                    tab.partyName = saved.party_name_snapshot || ''; tab.baseline = stable(sent);
                    setDraftId(tab.draftId);
                    if ((response.warnings || []).length) showBanner(response.warnings.join(' '), [], false); else if (banner && !banner.hidden && !tab.conflict) showBanner('');
                    render(); status(explicit ? 'Draft saved.' : 'Saved');
                    resolve(true);
                }).fail(xhr => {
                    if (xhr.status === 409) { tab.conflict = true; conflict(tab); }
                    else if (xhr.status === 404) {
                        tab.draftId = null; tab.version = 0; setDraftId(null);
                        showBanner('This draft no longer exists (it may have been posted or removed in another window). Your changes are still here and will be saved as a new draft.', [], false);
                    } else showBanner(messageOf(xhr));
                    render(); resolve(false);
                });
            });
        }

        function conflict(tab) {
            showBanner('Draft changed in another window.', [
                ['Load latest', () => loadLatest(tab)],
                ['Keep mine as a new tab', () => keepMine(tab)],
            ]);
        }

        function fetchDraft(tab) {
            return new Promise((resolve, reject) => request('GET', '/drafts/' + tab.draftId).done(response => resolve(response.data)).fail(reject));
        }

        function loadLatest(tab) {
            confirmBox('Load latest?', 'Your unsaved changes in this tab will be discarded and replaced by the latest saved version.', 'Load latest').then(ok => {
                if (!ok) return;
                fetchDraft(tab).then(data => applyDraft(tab, data)).then(() => showBanner('')).catch(xhr => showBanner(messageOf(xhr)));
            });
        }

        function keepMine(tab) {
            if (tabs.filter(other => other.draftId).length >= limit) {
                showBanner('You already have ' + limit + ' open ' + adapter.label + ' drafts. Close one first, then choose "Keep mine as a new tab" again.', [
                    ['Load latest', () => loadLatest(tab)], ['Keep mine as a new tab', () => keepMine(tab)]]);
                return;
            }
            const sent = snapshot();
            request('POST', '/drafts', {payload: sent, id: null, version: 0, ...adapter.party()}).done(response => {
                const saved = response.data;
                // The conflicting draft stays exactly as the other window saved it; this tab becomes a new draft.
                const original = {...tab, id: 'tab-' + (++counter), snapshot: null, baseline: '', conflict: false};
                tabs.splice(tabs.indexOf(tab), 0, original);
                Object.assign(tab, {draftId: saved.id, version: saved.version, title: saved.title, partyName: saved.party_name_snapshot || '',
                    conflict: false, baseline: stable(sent)});
                setDraftId(tab.draftId); showBanner('Your copy was kept as ' + tab.title + '. The other window\'s draft is unchanged.', [], false); render();
            }).fail(xhr => showBanner(messageOf(xhr)));
        }

        async function applyDraft(tab, data) {
            if (data.legacy && adapter.legacy) data = {...data, payload: adapter.legacy(data.payload || {})};
            tab.snapshot = data.payload; tab.version = data.draft.version; tab.title = data.draft.title;
            tab.partyName = data.draft.party_name_snapshot || ''; tab.blocking = data.blocking || []; tab.warnings = data.warnings || [];
            muted = true;
            try { await adapter.restore(data.payload); } finally { unmute(); }
            activeId = tab.id; setDraftId(tab.draftId); tab.baseline = hash(); tab.conflict = false; edited = false;
            if (tab.blocking.length) showBanner(tab.blocking.join(' '));
            else if (tab.warnings.length) showBanner(tab.warnings.join(' '), [], false);
            else showBanner('');
            render();
        }

        /** Save what is on screen (if anything changed) before the form is replaced. */
        async function leaveActive() {
            if (!enabled || listOnly) return true;
            const tab = current();
            const dirty = isDirty();
            const unused = tab && !tab.draftId && (!dirty || (adapter.empty && adapter.empty(snapshot())));
            if (unused) { tabs.splice(tabs.indexOf(tab), 1); activeId = null; render(); return true; }
            if (!dirty) return true;
            return saveActive();
        }

        function go(url) { leaving = true; window.location.href = url; }

        workspace.openTab = async id => {
            const tab = byId(id);
            if (!tab || id === activeId) return;
            if (!(await leaveActive())) return;
            const target = byId(id);
            if (!target) return;
            try {
                const data = target.snapshot && !target.conflict ? {payload: target.snapshot, draft: {version: target.version, title: target.title, party_name_snapshot: target.partyName}, blocking: target.blocking, warnings: target.warnings}
                    : await fetchDraft(target);
                const elsewhere = adapter.foreign && adapter.foreign(data.payload || {});
                if (elsewhere) { go(elsewhere + (elsewhere.includes('?') ? '&' : '?') + 'draft=' + target.draftId); return; }
                await applyDraft(target, data);
                target.snapshot = null;
            } catch (xhr) {
                showBanner(xhr && xhr.status === 404 ? 'This draft no longer exists.' : messageOf(xhr));
                if (xhr && xhr.status === 404) { tabs.splice(tabs.indexOf(target), 1); render(); }
            }
        };

        workspace.discardTab = async id => {
            const tab = byId(id);
            if (!tab) return;
            // An untouched blank tab holds nothing: close it without asking.
            const blank = !tab.draftId && !(tab.id === activeId && isDirty());
            if (!blank && !(await confirmBox('Discard ' + (tab.title || 'this draft') + '?', 'This draft and everything entered in it will be permanently removed.', 'Discard'))) return;
            // A closed tab must stay closed: no further autosave for it, and a save already on its way (which may be
            // creating this very draft on a slow server) is awaited so the draft it creates is deleted below too.
            tab.discarded = true;
            if (tab.id === activeId) { clearTimeout(timer); touched = false; }
            await inflight.catch(() => false);
            const finish = () => {
                const wasActive = tab.id === activeId;
                if (tabs.includes(tab)) tabs.splice(tabs.indexOf(tab), 1);
                if (wasActive) {
                    activeId = null;
                    mute(() => (adapter.closed ? adapter.closed() : adapter.reset({})));
                    setDraftId(null); untabbedBaseline = hash(); edited = false; showBanner('');
                }
                render();
            };
            if (!tab.draftId) { finish(); return; }
            request('DELETE', '/drafts/' + tab.draftId).done(finish).fail(xhr => { if (xhr.status === 404) finish(); else { tab.discarded = false; showBanner(messageOf(xhr)); } });
        };

        /** The page's own Discard button: drop the active draft (asks first), or just clear an untabbed form. */
        workspace.discardActive = () => {
            const tab = current();
            if (tab && (tab.draftId || isDirty())) return workspace.discardTab(tab.id);
            if (tab) tabs.splice(tabs.indexOf(tab), 1);
            activeId = null;
            mute(() => adapter.reset({}));
            setDraftId(null); untabbedBaseline = hash(); edited = false; showBanner(''); render();
        };

        /* ----------------------------------------------------------------- dialogs */
        function dialogBase(title, text) {
            const dialog = document.createElement('dialog');
            dialog.setAttribute('data-owns-keys', '');
            dialog.setAttribute('aria-label', title);
            const heading = document.createElement('h3'); heading.textContent = title;
            const paragraph = document.createElement('p'); paragraph.textContent = text;
            dialog.append(heading, paragraph);
            return dialog;
        }
        function present(dialog, focusTarget) {
            document.body.appendChild(dialog);
            if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
            focusTarget.focus();
        }
        function choose(title, text, choices, cancelValue) {
            return new Promise(resolve => {
                const dialog = dialogBase(title, text);
                const done = value => { dialog.remove(); resolve(value); };
                let last = null;
                choices.forEach(([text, value, cls]) => {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-sm ' + cls; button.textContent = text;
                    button.addEventListener('click', () => done(value)); dialog.appendChild(button); last = button;
                });
                dialog.addEventListener('cancel', event => { event.preventDefault(); done(cancelValue); });
                present(dialog, last);
            });
        }
        const confirmBox = (title, text, yes) => choose(title, text, [[yes, true, 'btn-danger'], ['Cancel', false, 'btn-outline-secondary']], false);
        /** Only used when the changes could not be kept as a draft (drafts off, or the save was refused). */
        const ask = () => choose('Unsaved ' + adapter.label.toLowerCase(), 'These changes could not be saved as a draft. What should happen to them?',
            [['Discard', 'discard', 'btn-outline-danger'], ['Cancel', 'cancel', 'btn-outline-secondary']], 'cancel');

        /* ---------------------------------------------------------------- new / start */
        /** Blank document in a blank, client-only tab. No server draft exists until the first meaningful edit. */
        function start(options = {}) {
            mute(() => { adapter.reset(options); setDraftId(null); });
            if (enabled) {
                const tab = blankTab();
                tabs.push(tab); activeId = tab.id; tab.baseline = hash(); edited = false;
            } else {
                activeId = null; untabbedBaseline = hash(); edited = false;
            }
            showBanner('');
            render();
            adapter.focus();
        }

        /**
         * "New document" (F-key, + tab, New button). The document on screen is kept as a draft first, so every press
         * opens one more draft; an untouched blank tab is reused instead of piling up empty tabs.
         */
        async function newDocument(options = {}) {
            const tab = current();
            // A never-saved form holding only defaults is simply dropped, not kept as an empty draft.
            const emptyNew = !(tab && tab.draftId) && !!adapter.empty && !listOnly && adapter.empty(snapshot());
            if (isDirty() && !emptyNew) {
                if (!(await saveActive({explicit: true}))) {
                    const choice = await ask();
                    if (choice === 'cancel') return false;
                    if (tab && tab.draftId) await new Promise(resolve => request('DELETE', '/drafts/' + tab.draftId).always(resolve));
                    if (tab) { tabs.splice(tabs.indexOf(tab), 1); activeId = null; }
                }
            } else if (tab && !tab.draftId) {
                tabs.splice(tabs.indexOf(tab), 1); activeId = null;
            }
            if (adapter.startNew) { render(); adapter.startNew(options, go); return true; }
            if (enabled && tabs.length >= limit) { showBanner(limitMessage()); render(); return false; }
            start(options);
            return true;
        }

        /** Make sure the server holds what is on screen before the page navigates away (shortcuts to another page). */
        async function flush() {
            if (!enabled || listOnly || !isDirty()) return true;
            return saveActive();
        }

        workspace.handles = entry => !!adapter.handles && adapter.handles(entry);
        workspace.newDocument = options => newDocument(options || {});
        workspace.flush = flush;
        workspace.leave = () => { leaving = true; };
        workspace.isDirty = isDirty;
        workspace.markClean = markClean;
        workspace.start = start;
        workspace.mute = mute;
        workspace.saveDraft = options => saveActive({explicit: true, ...options});
        workspace.canSaveDraft = () => enabled && !listOnly;
        workspace.activeDraftId = () => (current() || {}).draftId || null;
        workspace.activeBlocking = () => (current() || {blocking: []}).blocking;
        workspace.leaveActive = leaveActive;
        workspace.tabs = tabs;
        workspace.ask = ask;
        workspace.confirmBox = confirmBox;
        workspace.status = status;
        workspace.showBanner = showBanner;
        workspace.render = render;
        workspace.localShortcuts = adapter.localShortcuts || [];
        workspace.touch = () => { edited = true; scheduleAutosave(); };
        /** A saved document opened for editing is a fresh, untabbed form until it is changed. */
        workspace.detach = () => { if (!muted) setTimeout(() => { activeId = null; setDraftId(null); showBanner(''); render(); untabbedBaseline = hash(); edited = false; }, 0); };
        workspace.detachNow = () => { activeId = null; setDraftId(null); render(); };
        window.zoloDocumentWorkspace = workspace;

        /* ------------------------------------------------------------------ autosave */
        let touched = false;
        let timer = null;
        function scheduleAutosave() {
            if (!enabled || listOnly || muted) return;
            touched = true;
            clearTimeout(timer);
            timer = setTimeout(() => { if (touched) { touched = false; saveActive(); } }, 1500);
        }
        if (!listOnly) {
            // Only the user's own typing, picking and clicking inside the form counts as an edit (see 'edited').
            const userEdit = event => { if (event.isTrusted && adapter.inDocument(event.target)) { edited = true; scheduleAutosave(); } };
            document.addEventListener('input', userEdit, true);
            document.addEventListener('change', userEdit, true);
            document.addEventListener('click', event => { if (event.target.closest && event.target.closest('button, a, [role=button], input[type=checkbox], input[type=radio]')) userEdit(event); }, true);
            // Rows added from the item search or the multi-item picker (whose menus sit outside the form) are edits too.
            if (adapter.rows && window.MutationObserver) {
                rowObserver = new MutationObserver(() => { if (!muted) { edited = true; scheduleAutosave(); } });
                rowObserver.observe(adapter.rows, {childList: true});
            }
        }

        // Unloading is best effort only: the request may not finish, so the UI never claims a save for it.
        function flushOnHide() {
            if (!enabled || listOnly || muted || leaving || !edited) return;
            const tab = current();
            const state = hash();
            if (!tab && state === untabbedBaseline) return;
            if (tab && (tab.conflict || tab.blocking.length || state === tab.baseline)) return;
            if (!tab && tabs.length >= limit) return;
            if (!(tab && tab.draftId) && adapter.empty && adapter.empty(snapshot())) return;
            const target = tab || ensureTab();
            try {
                fetch(adapter.baseUrl + '/drafts', {method: 'POST', keepalive: true, credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token()},
                    body: JSON.stringify({payload: snapshot(), id: target.draftId, version: target.version || 0, ...adapter.party()})});
            } catch (error) { /* nothing to report while the page is going away */ }
        }
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushOnHide(); });
        window.addEventListener('pagehide', flushOnHide);

        /* -------------------------------------------------------------- page wiring */
        if (enabled) {
            $('#document-tab-add').on('click', () => { newDocument({}); });
            if (form) {
                form.addEventListener('submit', event => {
                    // A draft whose required references are gone cannot be posted; the server refuses it too.
                    const blocking = workspace.activeBlocking();
                    if (blocking.length) { event.preventDefault(); event.stopImmediatePropagation(); showBanner(blocking.join(' ')); return; }
                    // A real (non-AJAX) post is leaving the page: the draft must not be re-saved behind it.
                    setTimeout(() => { if (!event.defaultPrevented) leaving = true; }, 0);
                }, true);
            }
        }

        function loadList() {
            if (!enabled) return Promise.resolve();
            return new Promise(resolve => {
                request('GET', '/drafts').done(response => {
                    limit = response.limit || limit;
                    (response.data || []).forEach(row => tabs.push({...blankTab(), draftId: row.id, version: row.version, title: row.title,
                        partyName: row.party_name_snapshot || ''}));
                    render(); resolve();
                }).fail(xhr => { status(messageOf(xhr), true); resolve(); });
            });
        }

        /* ---------------------------------------------------- ?draft=ID and ?new=1 start */
        $(async () => {
            await loadList();
            const query = new URLSearchParams(window.location.search);
            const draftId = Number(query.get('draft')) || 0;
            const fresh = query.get('new') === '1';
            if (draftId || fresh) {
                query.delete('draft'); query.delete('new');
                window.history.replaceState(null, '', window.location.pathname + (query.toString() ? '?' + query : '') + window.location.hash);
            }
            if (adapter.ready) await adapter.ready();
            untabbedBaseline = hash(); edited = false;
            const wanted = draftId && tabs.find(tab => tab.draftId === draftId);
            if (wanted) await workspace.openTab(wanted.id);
            else if (fresh && !listOnly && !adapter.startNew) {
                if (enabled && tabs.length >= limit) { if (adapter.idle) adapter.idle(); showBanner(limitMessage()); }
                else start(options(query));
            } else if (adapter.idle) adapter.idle(tabs.length > 0);
            workspace.ready = true;
            document.dispatchEvent(new CustomEvent('zolo-workspace-ready'));
        });
        const options = query => (adapter.startOptions ? adapter.startOptions(query) : {});

        return workspace;
    }

    window.ZoloDocumentDrafts = {attach};
})();
