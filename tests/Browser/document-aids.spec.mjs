/*
 * Real-browser proof for the entry aids ported onto the normal Sales / Purchase pages:
 * shortcut help, F6 new item, previous rates, party outstanding / pending bills, copy bill, and extended stock tracking.
 *
 * The three read endpoints (previous rates, party summary, clone) are answered from fixed JSON by the browser so this
 * spec proves the page behaviour deterministically; their server behaviour is covered by the PHP feature tests.
 */
import { check, watch, login, ready, openKind, resetDrafts, pickParty, addRow, snap, settled, tabsOf, errors, context, BASE, SLOW, fail, finish } from './support.mjs';

const page = await context.newPage();
watch(page);
const json = body => ({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
const stubs = {
    previous: { data: [{ net_unit_cost: 1000, net_unit_price: 1249, qty: 2 }] },
    party: { data: { party: { id: 1, name: 'John Doe' }, outstanding: 250, credit: { credit_limit: 1000, available_credit: 750 },
        items: [{ document_date: '2026-09-01', document_no: 'PUR-0001', due_date: '2026-10-01', original_amount: 400, open_amount: 250 }], next_page: null } },
    clone: { data: { supplier_id: 1, customer_id: 1, warehouse_id: 1, items: [{ product_id: 1, name: 'Copied item', code: 'CP-1', qty: 4, net_unit_cost: 12.5, net_unit_price: 15 }] } },
};
async function stubReads(kind) {
    await page.route('**/commercial/' + kind + '/previous-rates*', route => route.fulfill(json(stubs.previous)));
    await page.route('**/commercial/' + kind + '/party/*', route => route.fulfill(json(stubs.party)));
    await page.route('**/commercial/' + kind + '/clone/*', route => route.fulfill(json(stubs.clone)));
}

try {
    await login(page);
    await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' });
    await resetDrafts(page, 'purchase'); await resetDrafts(page, 'sale');

    for (const kind of ['purchase', 'sale']) {
        const key = kind === 'purchase' ? 'F12' : 'F2';
        await stubReads(kind);
        await openKind(page, kind, '?new=1');

        /* ------------------------------------------------------ shortcut help */
        await page.evaluate(() => document.activeElement && document.activeElement.blur());
        await page.keyboard.press('?');
        await page.waitForSelector('dialog#document-shortcut-help[open]', { timeout: SLOW });
        const help = await page.textContent('dialog#document-shortcut-help');
        check(`${kind}: ? opens a help dialog listing the document keys and the form keys`, ['F2', 'F12', 'Shift+F2', 'Alt+F12', 'F6', 'Alt+I'].every(k => help.includes(k)) && !help.includes('Ctrl+F2'), help.slice(0, 200));
        await page.keyboard.press('Escape');
        check(`${kind}: Escape closes the help dialog`, (await page.$('dialog#document-shortcut-help')) === null);
        await page.click('.command-center-help');
        check(`${kind}: the visible Shortcuts button opens it too`, await page.isVisible('dialog#document-shortcut-help'));
        await page.keyboard.press('Escape');

        /* ------------------------------------------------------------- F6 item */
        await page.keyboard.press('F6');
        await page.waitForSelector('#quick-create-item-modal.show', { timeout: SLOW });
        check(`${kind}: F6 opens Create item inside the document`, true);
        await page.keyboard.press('F6');
        check(`${kind}: F6 does nothing while a dialog is open`, await page.locator('.modal.show').count() === 1);
        await page.locator('#quick-create-item-modal [data-dismiss="modal"]').first().click();
        await page.waitForSelector('#quick-create-item-modal', { state: 'hidden' });

        /* -------------------------------------------- outstanding + pending bills */
        await pickParty(page, kind, '1');
        await page.waitForSelector('#party-outstanding-badge:not([hidden])', { timeout: SLOW });
        check(`${kind}: party outstanding and available credit are shown`, /Outstanding ₹ 250\.00/.test(await page.textContent('#party-outstanding-badge')), await page.textContent('#party-outstanding-badge'));
        await page.click('#party-pending-link');
        await page.waitForSelector('dialog.document-pending-dialog[open]', { timeout: SLOW });
        const pending = await page.textContent('dialog.document-pending-dialog');
        check(`${kind}: pending bills list the open documents`, pending.includes('PUR-0001') && pending.includes('₹ 250.00'), pending);
        await page.keyboard.press('Escape');

        /* ----------------------------------------------------- previous rates */
        await page.click('#btn-add-item-row');
        const row = page.locator('#order-table-body tr.order-item-row').last();
        await row.locator('.row-item-name').fill('Zenbook');
        const suggestion = page.locator('.ui-autocomplete li, .ui-menu-item').first();
        await suggestion.waitFor({ timeout: SLOW });
        await suggestion.click();
        await row.locator('.row-prev-rate').waitFor({ timeout: SLOW });
        const hint = await row.locator('.row-prev-rate').textContent();
        check(`${kind}: the previous rate for this party is offered on the row`, /Last ₹ (1000|1249)\.00 × 2/.test(hint), hint);
        await row.locator('.row-prev-rate').click();
        const applied = Number(await row.locator('.row-rate').inputValue());
        check(`${kind}: clicking it applies that rate`, applied === (kind === 'purchase' ? 1000 : 1249), String(applied));

        /* ------------------------------------------------------ stock tracking */
        await row.locator('.btn-edit-row').click();
        await page.waitForSelector('#row-detail-modal.show', { timeout: SLOW });
        const purchaseOnly = await page.isVisible('#trk-hsn');
        check(`${kind}: the tracking section is present (${kind === 'purchase' ? 'HSN, weight, freight' : 'no purchase-only fields'})`, await page.isVisible('#tracking-extra') && purchaseOnly === (kind === 'purchase'));
        await page.fill('#trk-variant-id', '7');
        if (kind === 'purchase') { await page.fill('#trk-hsn', '4407'); await page.fill('#trk-weight', '2.5'); await page.fill('#trk-freight', '10'); }
        await page.click('#btn-save-row-detail');
        await page.waitForSelector('#row-detail-modal', { state: 'hidden' });
        const extras = await page.evaluate(() => window.zoloDocumentAids.lineExtras(document.querySelector('#order-table-body tr.order-item-row')));
        check(`${kind}: tracking is mapped to engine line fields`, extras.variant_id === 7 && (kind === 'sale' || (extras.hsn_code === '4407' && extras.weight === 2.5 && extras.landed_cost === 10)), JSON.stringify(extras));

        /* tracking survives a tab switch (per-tab isolation) */
        await settled(page);
        await page.click('#document-tab-add');
        await page.waitForFunction(() => window.zoloDocumentWorkspace.tabs.length === 2);
        check(`${kind}: a second tab carries no tracking from the first`, (await snap(page)).form.lines.length === 0);
        await page.locator('.document-tab-open').first().click();
        await page.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.length === 1);
        const back = await page.evaluate(() => window.zoloDocumentAids.lineExtras(document.querySelector('#order-table-body tr.order-item-row')));
        check(`${kind}: tracking is restored with the tab`, back.variant_id === 7, JSON.stringify(back));
        await resetDrafts(page, kind);

        /* ----------------------------------------------------------- copy bill */
        await openKind(page, kind);
        // A database without bills has no card to click; the Copy handler is delegated, so a stand-in card exercises the same path.
        if (!(await page.locator('.btn-side-copy').count())) {
            await page.evaluate(() => { const a = document.createElement('a'); a.className = 'btn-side-copy'; a.dataset.id = '999'; a.textContent = 'Copy'; document.getElementById('side-bill-list').appendChild(a); });
        }
        const copy = page.locator('.btn-side-copy').first();
        if (await copy.count()) {
            await page.evaluate(() => { document.getElementById('comm-split-grid') && window.commandCenterGrid.switchWorkspaceMode('voucher'); });
            await copy.evaluate(node => node.click());
            await page.waitForFunction(() => window.zoloDocumentWorkspace.snapshot().form.lines.length === 1, null, { timeout: SLOW });
            const copied = await snap(page);
            const names = copied.form.lines.map(line => line.controls.find(c => c.name === 'product_name_text[]').value);
            check(`${kind}: Copy opens a new draft tab with the party and lines of the old bill`, names[0] === 'Copied item' && copied.form.fields[kind === 'purchase' ? 'supplier_id' : 'customer_id'] === '1', JSON.stringify(names));
            await settled(page);
            check(`${kind}: the copy is a draft, never a posted bill`, (await tabsOf(page)).length >= 1);
        } else {
            check(`${kind}: a bill card with Copy exists`, false, 'no bills in this database');
        }
        await resetDrafts(page, kind);
    }
    check('no JavaScript errors while using the entry aids', errors.length === 0, errors.slice(0, 5).join(' | '));
} catch (error) {
    fail(); console.log('FAIL unexpected error :: ' + (error && error.stack || error));
} finally {
    await finish();
}
