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
import { check, watch, login, ready, openKind, api, resetDrafts, pickParty, addRow, setTracking, setPayment, draftCount, waitDrafts, snap, settled, tabsOf, dialogButtons, errors, context, browser, BASE, SLOW, fail, finish } from './support.mjs';

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

    /* --------------------------- the unassigned and accounting keys do nothing here */
    await openKind(page, 'purchase');
    for (const key of ['Control+F2', 'F6']) {
        const before = page.url();
        await page.keyboard.press(key);
        check(`${key} does not navigate away from the purchase page`, page.url() === before);
    }

    /* ----------------------------------------------- visible New Bill fallback */
    await openKind(page, 'purchase');
    await resetDrafts(page, 'purchase');
    await openKind(page, 'purchase');
    await page.click('.command-center-new');
    check('visible "+ New Bill" works without any key', (await tabsOf(page)).length === 1);

    /* ------------------------------------------------------------ dirty dialog */
    await openKind(page, 'purchase', '?new=1');
    await pickParty(page, 'purchase', '1');
    await addRow(page, 'ITEM-DIRTY', 2, 5);
    await page.keyboard.press('F12');
    await page.waitForSelector('dialog[open]', { timeout: SLOW });
    const buttons = await dialogButtons(page);
    check('dirty F12 offers Save draft / Discard / Cancel', ['Save draft', 'Discard', 'Cancel'].every(label => buttons.includes(label)), buttons.join(','));
    await page.click('dialog[open] >> text=Cancel');
    check('Cancel keeps the same bill', (await snap(page)).form.fields.supplier_id === '1' && (await tabsOf(page)).length === 1);
    await page.keyboard.press('F12');
    await page.waitForSelector('dialog[open]');
    await page.click('dialog[open] >> text=Discard');
    await page.waitForFunction(() => window.zoloDocumentWorkspace.tabs.length === 1 && window.zoloDocumentWorkspace.tabs[0].draftId === null);
    check('Discard replaces the bill with a blank one and keeps no server draft', (await snap(page)).form.fields.supplier_id === '' && (await draftCount(page, 'purchase')) === 0);
    await pickParty(page, 'purchase', '1');
    await addRow(page, 'ITEM-SAVED', 4, 7);
    await page.keyboard.press('F12');
    await page.waitForSelector('dialog[open]');
    await page.click('dialog[open] >> text=Save draft');
    await waitDrafts(page, 'purchase', 1);
    const afterSave = await tabsOf(page);
    check('Save draft persists the bill, then opens a new tab', afterSave.length === 2 && afterSave[0].draftId !== null && afterSave[1].draftId === null, JSON.stringify(afterSave));
    check('the saved tab is labelled with its title and party', /^Draft \d+/.test(afterSave[0].title) && /John Doe/.test(afterSave[0].party), JSON.stringify(afterSave[0]));
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
    check('reload leaves the register as the workspace (no blank bill auto-opened)', await page.isVisible('#fullwidth-register-view') && !(await page.isVisible('#purchase-entry-form')));
    await page.locator('.document-tab-open').first().click();
    await page.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.some(line => line.controls.some(c => c.value === 'ITEM-A')));
    check('a restored tab rebuilds its tracking after reload', (await snap(page)).form.lines[0].controls.some(c => c.value === 'BATCH-A'));

    /* --------------------------------------------------- 409: two windows */
    const draftA = (await tabsOf(page))[0].draftId;
    const second = await context.newPage(); watch(second);
    await openKind(second, 'purchase');
    await second.locator('.document-tab-open').first().click();
    await second.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.length === 1);
    await second.fill('#order-table-body tr.order-item-row .row-qty', '8');
    await second.locator('#order-table-body tr.order-item-row .row-qty').dispatchEvent('input');
    await second.waitForFunction(async id => { const r = await fetch('/commercial/purchase/drafts', { headers: { Accept: 'application/json' } }); return (await r.json()).data.some(d => d.id === id && d.version >= 3); }, draftA, { timeout: SLOW });
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
    await second.fill('#order-table-body tr.order-item-row .row-qty', '9');
    await second.locator('#order-table-body tr.order-item-row .row-qty').dispatchEvent('input');
    await second.waitForFunction(async id => { const r = await fetch('/commercial/purchase/drafts', { headers: { Accept: 'application/json' } }); return (await r.json()).data.some(d => d.id === id && d.version >= 4); }, draftA, { timeout: SLOW });
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
        await page.click('#btn-form-submit');
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

    check('no JavaScript errors on any exercised page', errors.length === 0, errors.slice(0, 5).join(' | '));
} catch (error) {
    fail(); console.log('FAIL unexpected error :: ' + (error && error.stack || error));
} finally {
    await finish();
}
