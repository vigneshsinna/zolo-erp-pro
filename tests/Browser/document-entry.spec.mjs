/*
 * Real-browser proof for unified document entry on the NORMAL /sales and /purchases pages.
 * It never uses a standalone entry screen: it logs in, presses real keys and drives the pages that production users see.
 *
 *   PLAYWRIGHT_CORE=<path to playwright-core/index.mjs or the package dir>  (optional when installed locally)
 *   BASE=http://127.0.0.1:8091  ADMIN_USER=admin  ADMIN_PASSWORD=...  node tests/Browser/document-entry.spec.mjs
 *
 * Point BASE at an application instance wired to a disposable database (see tests/Browser/README.md). The spec cleans up
 * the drafts it creates and posts exactly one purchase bill.
 */
import { check, watch, login, ready, openKind, api, resetDrafts, pickParty, addRow, setTracking, setPayment, draftCount, waitDrafts, snap, settled, tabsOf, waitUntil, errors, context, browser, BASE, SLOW, fail, finish } from './support.mjs';

const page = await context.newPage();
watch(page);
try {
    await login(page);
    check('signed in', !page.url().includes('/login'), page.url());

    /* -------------------------------------------------------- shortcuts, any page */
    for (const [kind, key, target] of [['purchase', 'F12', /\/purchases/], ['sale', 'F2', /\/sales/]]) {
        await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' });
        await resetDrafts(page, kind);
        await page.keyboard.press(key);
        await page.waitForURL(target, { waitUntil: 'commit', timeout: SLOW });
        await ready(page);
        const url = new URL(page.url());
        check(`${key} from an unrelated page opens the ${kind} page`, target.test(url.pathname));
        check(`${key}: one-shot new=1 was removed from the URL`, !url.searchParams.has('new'), page.url());
        check(`${key}: the bill form is shown`, await page.isVisible('#' + kind + '-entry-form'));
        const tabs = await tabsOf(page);
        check(`${key}: a blank client-only tab was opened (no server draft yet)`, tabs.length === 1 && tabs[0].draftId === null && (await draftCount(page, kind)) === 0, JSON.stringify(tabs));
        const focused = await page.evaluate(() => (document.activeElement && (document.activeElement.className + ' ' + document.activeElement.id)) || '');
        check(`${key}: focus lands on the party control`, /dropdown-toggle|supplier_id|customer_id/.test(focused), focused);
        const preventedReserved = await page.evaluate(() => { const e = new KeyboardEvent('keydown', { key: 'F12', cancelable: true, bubbles: true }); document.dispatchEvent(e); return e.defaultPrevented; });
        check('F12 default action is prevented by the registry', preventedReserved === true);
    }

    /* ---------------------------------- the other documents open their existing page */
    for (const [key, target, label] of [['Shift+F2', /\/sales\?returns=1/, 'Sales Return'], ['Alt+F2', /\/delivery-challans/, 'Delivery Challan'], ['Shift+F12', /\/purchases\?returns=1/, 'Purchase Return'],
        ['Alt+F12', /\/goods-received-notes/, 'GRN'], ['Alt+F10', /\/quotations\/create/, 'Quotation']]) {
        await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' });
        await page.keyboard.press(key);
        await page.waitForURL(target, { waitUntil: 'commit', timeout: SLOW }).then(() => check(`${key} opens the existing ${label} page`, true), () => check(`${key} opens the existing ${label} page`, false, page.url()));
    }

    /* --------------------------- the unassigned and accounting keys do nothing here */
    await openKind(page, 'purchase');
    for (const key of ['Control+F2', 'F6']) {
        const before = page.url();
        await page.keyboard.press(key);
        check(`${key} does not navigate away from the purchase page`, page.url() === before);
    }

    /* ------------------------------- keys are accelerators only: no duplicate menus */
    await openKind(page, 'purchase');
    await resetDrafts(page, 'purchase');
    await openKind(page, 'purchase');
    check('no sidebar or toolbar entry duplicates a shortcut key', (await page.locator('[data-document-shortcut], .command-center-new, .sidebar-action').count()) === 0);
    await page.click('#document-tab-add');
    check('the + draft tab button starts a bill without any key', (await tabsOf(page)).length === 1);

    /* ------------------------------------ the key again = one more draft, no dialog */
    await openKind(page, 'purchase', '?new=1');
    await pickParty(page, 'purchase', '1');
    await addRow(page, 'ITEM-FIRST', 2, 5);
    await page.keyboard.press('F12');
    await page.waitForFunction(() => window.zoloDocumentWorkspace.tabs.length === 2, null, { timeout: SLOW });
    check('F12 on an edited bill asks nothing', (await page.locator('dialog[open]').count()) === 0);
    const afterFirst = await tabsOf(page);
    check('F12 again keeps the edited bill as a draft and opens a new blank one', afterFirst[0].draftId !== null && afterFirst[1].draftId === null && (await snap(page)).form.fields.supplier_id === '', JSON.stringify(afterFirst));
    check('the kept draft is labelled with its title and party', /^Draft \d+/.test(afterFirst[0].title) && /John Doe/.test(afterFirst[0].party), JSON.stringify(afterFirst[0]));
    await page.keyboard.press('F12');
    check('F12 on an untouched blank draft reuses it instead of piling up empty tabs', (await tabsOf(page)).length === 2);
    await pickParty(page, 'purchase', '1');
    await addRow(page, 'ITEM-SECOND', 4, 7);
    await page.keyboard.press('F12');
    await page.waitForFunction(() => window.zoloDocumentWorkspace.tabs.length === 3, null, { timeout: SLOW });
    check('each press with edits adds one more server draft', (await draftCount(page, 'purchase')) === 2);
    await resetDrafts(page, 'purchase');

    /* ---------------------------------------------- autosave + two-tab isolation */
    await openKind(page, 'purchase', '?new=1');
    await pickParty(page, 'purchase', '1');
    await addRow(page, 'ITEM-A', 3, 10);
    await setTracking(page, 'BATCH-A', 'SER-1,SER-2');
    await setPayment(page, 15);
    await waitDrafts(page, 'purchase', 1);
    await settled(page);
    const autosaved = await draftCount(page, 'purchase');
    check('autosave created exactly one server draft after the edit', autosaved === 1, String(autosaved));
    await page.click('#document-tab-add');
    await page.waitForFunction(() => window.zoloDocumentWorkspace.tabs.length === 2);
    const blank = await snap(page);
    const zero = value => Number(value || 0) === 0;
    check('new tab starts empty: no party, no lines, no payment', blank.form.fields.supplier_id === '' && blank.form.lines.length === 0 && zero(blank.form.fields['drawer-paid-amount']) && zero(blank.form.fields['hidden-paid-amount']), JSON.stringify(blank.form.fields['drawer-paid-amount']));
    await pickParty(page, 'purchase', '1');
    await addRow(page, 'ITEM-B', 1, 99);
    await waitDrafts(page, 'purchase', 2);
    await settled(page);
    // back to tab A
    await page.locator('.document-tab-open').first().click();
    await page.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.some(line => line.controls.some(c => c.value === 'ITEM-A')));
    const a = await snap(page);
    const control = (line, name) => (line.controls.find(c => c.name === name) || {}).value;
    check('tab A: party restored', a.form.fields.supplier_id === '1');
    check('tab A: only its own line is present (no leakage from B)', a.form.lines.length === 1 && control(a.form.lines[0], 'product_name_text[]') === 'ITEM-A', JSON.stringify(a.form.lines.map(l => control(l, 'product_name_text[]'))));
    check('tab A: quantity and rate restored', control(a.form.lines[0], 'qty[]') === '3' && Number(control(a.form.lines[0], 'net_unit_cost[]')) === 10);
    check('tab A: batch and serial tracking restored', control(a.form.lines[0], 'batch_no[]') === 'BATCH-A' && control(a.form.lines[0], 'imei_number[]') === 'SER-1,SER-2');
    check('tab A: payment restored', a.form.fields['drawer-paid-amount'] === '15' && a.form.fields['hidden-paid-amount'] === '15', JSON.stringify([a.form.fields['drawer-paid-amount'], a.form.fields['hidden-paid-amount']]));
    await page.locator('.document-tab-open').nth(1).click();
    await page.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.some(line => line.controls.some(c => c.value === 'ITEM-B')));
    const b = await snap(page);
    check('tab B: only its own line', b.form.lines.length === 1 && control(b.form.lines[0], 'product_name_text[]') === 'ITEM-B');
    check('tab B: no batch, serial or payment from A', control(b.form.lines[0], 'batch_no[]') === '' && control(b.form.lines[0], 'imei_number[]') === '' && zero(b.form.fields['drawer-paid-amount']) && zero(b.form.fields['hidden-paid-amount']),
        JSON.stringify([control(b.form.lines[0], 'batch_no[]'), b.form.fields['drawer-paid-amount'], b.form.fields['hidden-paid-amount']]));

    /* ------------------------------------------------------- reload restores tabs */
    await openKind(page, 'purchase');
    const restored = await tabsOf(page);
    check('reload restores the server draft tabs', restored.length === 2 && restored.every(tab => tab.draftId), JSON.stringify(restored));
    check('reload opens split view (blank bill + list) without creating a draft tab', await page.isVisible('#purchase-entry-form') && !(await page.isVisible('#fullwidth-register-view'))
        && (await tabsOf(page)).length === 2);
    await page.locator('.document-tab-open').first().click();
    await page.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.some(line => line.controls.some(c => c.value === 'ITEM-A')));
    check('a restored tab rebuilds its tracking after reload', (await snap(page)).form.lines[0].controls.some(c => c.value === 'BATCH-A'));

    /* --------------------------------------------------- 409: two windows */
    const draftA = (await tabsOf(page))[0].draftId;
    const versionOf = async () => (((await api(page, 'purchase', 'GET', '/drafts')).json.data || []).find(d => d.id === draftA) || {}).version || 0;
    let before = await versionOf();
    const second = await context.newPage(); watch(second);
    await openKind(second, 'purchase');
    await second.locator('.document-tab-open').first().click();
    await second.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.length === 1);
    await second.fill('#order-table-body tr.order-item-row .row-qty', '8');
    await second.locator('#order-table-body tr.order-item-row .row-qty').dispatchEvent('input');
    await waitUntil(async () => (await versionOf()) > before, 'the other window to save a newer version');
    await page.fill('#order-table-body tr.order-item-row .row-qty', '5');
    await page.locator('#order-table-body tr.order-item-row .row-qty').dispatchEvent('input');
    await page.waitForSelector('#document-draft-banner:not([hidden])', { timeout: SLOW });
    const bannerText = await page.textContent('#document-draft-banner');
    check('stale save shows the 409 conflict banner', /Draft changed in another window/.test(bannerText) && /Load latest/.test(bannerText) && /Keep mine as a new tab/.test(bannerText), bannerText);
    await page.click('#document-draft-banner >> text=Load latest');
    await page.waitForSelector('dialog[open]');
    await page.click('dialog[open] button:text-is("Load latest")');
    const loaded = await page.waitForFunction(() => { const lines = window.zoloDocumentWorkspace.snapshot().form.lines; return lines.length === 1 && lines[0].controls.some(c => c.name === 'qty[]' && c.value === '8'); }, null, { timeout: 15000 }).then(() => true, () => false);
    check('Load latest replaces the form with the other window\'s version', loaded, JSON.stringify(await page.evaluate(() => ({ lines: window.zoloDocumentWorkspace.snapshot().form.lines.map(l => l.controls.filter(c => c.name === 'qty[]').map(c => c.value)), banner: document.getElementById('document-draft-banner').textContent, tabs: window.zoloDocumentWorkspace.tabs.map(t => [t.draftId, t.version, t.conflict]) }))));
    if (!loaded) throw new Error('Load latest did not restore the latest version');
    // conflict again, this time keep mine
    before = await versionOf();
    await second.fill('#order-table-body tr.order-item-row .row-qty', '9');
    await second.locator('#order-table-body tr.order-item-row .row-qty').dispatchEvent('input');
    await waitUntil(async () => (await versionOf()) > before, 'the other window to save a newer version');
    await page.fill('#order-table-body tr.order-item-row .row-qty', '6');
    await page.locator('#order-table-body tr.order-item-row .row-qty').dispatchEvent('input');
    await page.waitForSelector('#document-draft-banner:not([hidden]) >> text=Keep mine as a new tab', { timeout: SLOW });
    const beforeKeep = (await tabsOf(page)).length;
    await page.click('#document-draft-banner >> text=Keep mine as a new tab');
    await page.waitForFunction(count => window.zoloDocumentWorkspace.tabs.length === count + 1, beforeKeep, { timeout: SLOW });
    const kept = await tabsOf(page);
    check('Keep mine creates a new draft id and keeps the conflicting draft', new Set(kept.map(t => t.draftId)).size === kept.length && kept.some(t => t.draftId === draftA), JSON.stringify(kept));
    const original = await api(page, 'purchase', 'GET', '/drafts/' + draftA);
    const originalQty = original.json.data.payload.form.lines[0].controls.find(c => c.name === 'qty[]').value;
    check('the conflicting draft was not overwritten', originalQty === '9', originalQty);
    await second.close();
    await resetDrafts(page, 'purchase');

    /* ------------------------------------------------------------- the 10-tab limit */
    await openKind(page, 'purchase');
    const payload = { schema_version: 1, document_kind: 'purchase', form: { fields: {}, lines: [], ui: {}, context: {} } };
    for (let i = 0; i < 10; i++) await api(page, 'purchase', 'POST', '/drafts', { payload, version: 0 });
    await openKind(page, 'purchase');
    check('ten drafts restore as ten tabs', (await tabsOf(page)).length === 10);
    check('the + button is disabled at the limit', await page.isDisabled('#document-tab-add'));
    await page.keyboard.press('F12');
    await page.waitForSelector('#document-draft-banner:not([hidden])', { timeout: SLOW });
    check('11th tab is rejected with the limit message, nothing evicted', /already have 10 open Purchase drafts/.test(await page.textContent('#document-draft-banner')) && (await tabsOf(page)).length === 10);
    await resetDrafts(page, 'purchase');

    /* ---------------------------------------- post: draft removed on success only */
    await openKind(page, 'purchase', '?new=1');
    await pickParty(page, 'purchase', '1');
    await page.evaluate(() => { window.jQuery('#form_warehouse_id').val(window.jQuery('#form_warehouse_id option:first').val()); });
    await page.click('#btn-add-item-row');
    const row = page.locator('#order-table-body tr.order-item-row').last();
    await row.locator('.row-item-name').fill('Zenbook');
    const suggestion = page.locator('.ui-autocomplete li, .ui-menu-item').first();
    await suggestion.waitFor({ timeout: SLOW }).catch(() => {});
    if (await suggestion.count()) await suggestion.click();
    await row.locator('.row-qty').fill('2');
    await row.locator('.row-qty').dispatchEvent('input');
    await waitDrafts(page, 'purchase', 1);
    await settled(page);
    const draftId = (await tabsOf(page))[0].draftId;
    check('a draft exists before posting', !!draftId);
    const productId = await row.locator('.row-product-id').inputValue();
    if (productId && productId !== '0') {
        await page.click('#btn-form-save');
        // Either the post is accepted (page navigates to the register) or the server refuses it and the page reports why.
        const outcome = await page.waitForFunction(() => {
            if (!document.querySelector('#purchase-entry-form')) return 'navigating';
            const alert = Array.from(document.querySelectorAll('.alert')).find(a => a.style.display !== 'none' && a.textContent.trim());
            return alert ? 'refused:' + alert.textContent.trim() : false;
        }, null, { timeout: SLOW }).then(handle => handle.jsonValue(), () => 'timeout').catch(() => 'navigated');
        if (String(outcome).startsWith('refused:')) {
            const kept = await api(page, 'purchase', 'GET', '/drafts/' + draftId);
            check('a refused post keeps the draft and its tab (' + outcome.slice(8) + ')', kept.status === 200 && (await tabsOf(page)).some(tab => tab.draftId === draftId));
        } else {
            await ready(page);
            const gone = await api(page, 'purchase', 'GET', '/drafts/' + draftId);
            check('after a successful post the draft is deleted and the tab strip is empty', gone.status === 404 && (await tabsOf(page)).length === 0, 'status ' + gone.status);
        }
    } else {
        check('catalog item could be chosen for the posting check', false, 'autocomplete did not resolve a product');
    }

    /* ------------------------- the other document pages autosave into their own drafts */
    for (const [kind, path, key] of [['dc', '/delivery-challans', 'Alt+F2'], ['grn', '/goods-received-notes', 'Alt+F12']]) {
        await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
        await ready(page);
        await resetDrafts(page, kind);
        await page.goto(BASE + path + '?new=1', { waitUntil: 'domcontentloaded' });
        await ready(page);
        await page.fill('#transport_name', 'Draft Transport ' + kind);
        await page.locator('#transport_name').dispatchEvent('input');
        await page.click('#btn-add-row');
        await page.locator('#order-table-body .item-row .row-qty').last().fill('3');
        await page.locator('#order-table-body .item-row .row-qty').last().dispatchEvent('input');
        await waitDrafts(page, kind, 1);
        await settled(page);
        check(`${kind}: an edit is autosaved as a draft`, (await draftCount(page, kind)) === 1);
        await page.keyboard.press(key);
        await page.waitForFunction(() => window.zoloDocumentWorkspace.tabs.length === 2, null, { timeout: SLOW });
        check(`${kind}: ${key} again opens one more, blank draft`, (await page.inputValue('#transport_name')) === '' && (await page.locator('#order-table-body .item-row').count()) === 0);
        await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
        await ready(page);
        await page.locator('.document-tab-open').first().click();
        await page.waitForFunction(kind => document.getElementById('transport_name').value === 'Draft Transport ' + kind, kind, { timeout: SLOW });
        check(`${kind}: the draft reopens after reload with its fields and lines`, (await page.locator('#order-table-body .item-row').count()) === 1
            && (await page.locator('#order-table-body .item-row .row-qty').inputValue()) === '3');
        await resetDrafts(page, kind);
    }

    await page.goto(BASE + '/quotations/create', { waitUntil: 'domcontentloaded' });
    await ready(page);
    await resetDrafts(page, 'quotation');
    await page.goto(BASE + '/quotations/create', { waitUntil: 'domcontentloaded' });
    await ready(page);
    await page.fill('textarea[name="note"]', 'Quotation draft note');
    await page.locator('textarea[name="note"]').dispatchEvent('input');
    await waitDrafts(page, 'quotation', 1);
    check('quotation: an edit is autosaved as a draft', (await draftCount(page, 'quotation')) === 1);
    await page.goto(BASE + '/quotations/create', { waitUntil: 'domcontentloaded' });
    await ready(page);
    await page.locator('.document-tab-open').first().click();
    await page.waitForFunction(() => document.querySelector('textarea[name="note"]').value === 'Quotation draft note', null, { timeout: SLOW })
        .then(() => check('quotation: the draft reopens after reload', true), () => check('quotation: the draft reopens after reload', false));
    await new Promise(resolve => setTimeout(resolve, 2500));
    check('quotation: opening a draft right after load creates no extra blank draft', (await draftCount(page, 'quotation')) === 1);
    await resetDrafts(page, 'quotation');

    /* ------------------- closing a tab while its autosave is still on the way (slow server) */
    await openKind(page, 'purchase');
    await resetDrafts(page, 'purchase');
    const slowSave = async route => { if (route.request().method() === 'POST') await new Promise(resolve => setTimeout(resolve, 3000)); route.continue(); };
    await page.route('**/commercial/purchase/drafts', slowSave);
    await openKind(page, 'purchase', '?new=1');
    await addRow(page, 'ITEM-CLOSED', 1, 1);
    await page.waitForFunction(() => document.querySelectorAll('.document-tab').length === 1);
    await new Promise(resolve => setTimeout(resolve, 2000)); // the autosave POST is now in flight
    await page.click('.document-tab.is-active .document-tab-close');
    await page.waitForSelector('dialog[open]');
    await page.click('dialog[open] button:text-is("Discard")');
    await waitUntil(async () => (await tabsOf(page)).length === 0, 'the closed tab to go');
    await page.unroute('**/commercial/purchase/drafts', slowSave);
    await openKind(page, 'purchase');
    check('a tab closed while its save was in flight stays closed (no draft comes back)', (await tabsOf(page)).length === 0 && (await draftCount(page, 'purchase')) === 0, JSON.stringify(await tabsOf(page)));
    await resetDrafts(page, 'purchase');

    check('no JavaScript errors on any exercised page', errors.length === 0, errors.slice(0, 5).join(' | '));
} catch (error) {
    fail(); console.log('FAIL unexpected error :: ' + (error && error.stack || error));
} finally {
    await finish();
}
