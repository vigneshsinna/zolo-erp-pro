/* Real desktop layout and keyboard regression checks. Use the disposable server in README.md. */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import path from 'node:path';
import {context, login, ready, BASE, check, errors, watch, finish, fail} from './support.mjs';

const page = await context.newPage();
watch(page);
const onScreen = async selector => page.locator(selector).evaluate(element => {
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0 && rect.top >= 0 && rect.left >= 0
        && rect.bottom <= innerHeight + 1 && rect.right <= innerWidth + 1;
});
try {
    await login(page);
    const fixturePages = [];
    if (process.env.DOCUMENT_LAYOUT_FIXTURES) {
        for (const [kind, form, save] of [['return','form.sale-return-form','#submit-button'],
            ['return_purchase','form.payment-form','#submit-button'], ['compliance-return','#return-form','#return-form > button[type=submit]'],
            ['sale-edit','#payment-form','#submit-button'], ['purchase-edit','#purchase-form','#submit-button'],
            ['quotation-edit','#payment-form','#submit-button'], ['transfer-edit','#transfer-form','#submit-button'],
            ['adjustment-edit','#adjustment-form','#submit-button'], ['return-edit','#payment-form','#submit-button'],
            ['return_purchase-edit','form.payment-form','#submit-button'],

            ['challan-create','#challan-form','#challan-form input[type=submit]'], ['exchange-create','#exchange-form','#exchange-form button[type=submit]']]) {
            const route = '/__document-layout-'+kind;
            const html = readFileSync(path.join(process.env.DOCUMENT_LAYOUT_FIXTURES, 'document-'+kind+'-fixture.html'),'utf8').replace(/http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?/g,BASE);
            await page.route(BASE+route, request => request.fulfill({contentType:'text/html',body:html}));
            fixturePages.push([kind,route,form,save]);
        }
    }
    for (const viewport of [{width:1366,height:768}, {width:1280,height:720}]) {
        await page.setViewportSize(viewport);
        for (const [kind, route, form, save] of [
            ['sale','/sales?new=1','#sale-entry-form','#btn-form-save'],
            ['purchase','/purchases?new=1','#purchase-entry-form','#btn-form-save'],
            ['dc','/delivery-challans?new=1','#challan-entry-form','#btn-save'],
            ['grn','/goods-received-notes?new=1','#grn-entry-form','#btn-save'],
            ['quotation','/quotations/create','#quotation-form','#submit-button'],
            ['transfer','/transfers/create','#transfer-form','#submit-button'],
            ['adjustment','/qty_adjustment/create','#adjustment-form','#submit-button'],
            ['purchase-existing-edit','/purchases/1/edit','#purchase-form','#submit-button'],
            ['voucher','/accounting/voucher/entry','#voucherEntryForm','#btnSaveVoucher'],
            ['voucher-hub','/accounting/vouchers','#accounting-voucher-form','#post-voucher'],
            ...fixturePages,
        ]) {
            if (process.env.DOCUMENT_KIND && process.env.DOCUMENT_KIND !== kind) continue;
            const response = await page.goto(BASE + route, {waitUntil:'domcontentloaded',timeout:60000});
            assert.equal(response.status(), 200, route);
            await page.locator('#loader').waitFor({state:'hidden'});
            await page.waitForFunction(() => [...document.querySelectorAll('link[rel=stylesheet],link[as=style]')]
                .filter(link=>link.href.startsWith(location.origin+'/')).every(link=>link.disabled||link.sheet),null,{timeout:60000});
            if (['sale','purchase','dc','grn','quotation'].includes(kind)) await ready(page);
            await page.locator(save).waitFor({state:'visible'});
            assert(await onScreen(save), `${kind} Save stays on screen at ${viewport.width}: `+JSON.stringify(await page.locator(form+' ,' +form+' > .row,'+form+' .document-lines-row,'+form+' .document-details-row,'+save).evaluateAll(elements=>elements.map(e=>({tag:e.tagName,id:e.id,classes:e.className,rect:e.getBoundingClientRect().toJSON(),flex:getComputedStyle(e).flex})))));
            assert(await page.evaluate(() => document.documentElement.scrollHeight <= innerHeight + 1), kind+' page scroll');
            if (kind==='voucher') {
                assert(await onScreen('#btnAddRow'), 'voucher Add Line stays visible');
                for (const hint of await page.locator('.optech-shortcut-banner > span').all()) {
                    assert(await hint.evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1;}), 'voucher shortcut hint stays visible');
                }
                const add=await page.locator('#btnAddRow').boundingBox();
                const footer=await page.locator('.optech-footer-card').boundingBox();
                assert(add.y+add.height<=footer.y, 'voucher Add Line does not overlap remarks');
            }
            for (const details of await page.locator(form+' details.document-secondary-fields').all()) {
                await details.locator('summary').focus();
                if (!(await details.evaluate(e=>e.open))) await page.keyboard.press('Enter');
                assert(await onScreen(save), kind+' Save stays visible with optional details open');
                await page.keyboard.press('Enter');
            }
            const fields = page.locator(form+' input:not([type=hidden]):not([type=file]):not([type=submit]):not([readonly]):not(.date):not(.ui-autocomplete-input), '+form+' select:not(.selectpicker):not([tabindex="-98"]):not(.bs-select-hidden):not(.bootstrap-select select)').filter({visible:true});
            const notes=page.locator(form+' textarea').filter({visible:true});
            const first = await fields.count() ? fields.first() : await notes.count() ? notes.first() : page.locator(form+' input.date').first();
            await first.focus();
            const before = await page.evaluate(() => document.activeElement.id || document.activeElement.name);
            await page.keyboard.press('Enter');
            const after = await page.evaluate(() => document.activeElement.id || document.activeElement.name);
            if (await first.evaluate(e=>e.tagName==='TEXTAREA')) assert.equal(after, before, kind+' notes retain native Enter');
            else if (!(await first.evaluate(e=>e.classList.contains('date')))) assert.notEqual(after, before, kind+' Enter advances '+JSON.stringify(await page.locator(':focus').evaluate(e=>({element:e.outerHTML.slice(0,180),keyboard:!!document.querySelector('script[src*="document-keyboard"]'),modal:!!document.querySelector('dialog[open],.modal.show,[data-owns-keys]')}))));
            assert.equal(page.url().split('?')[0], BASE+route.split('?')[0], kind+' Enter never posts');
            const focused = await page.locator(':focus').elementHandle();
            await page.keyboard.press('Tab');
            await page.keyboard.press('Shift+Tab');
            assert(await page.evaluate(element=>element===document.activeElement, focused), kind+' Tab and Shift+Tab reverse focus');
            if (['sale','purchase'].includes(kind)) {
                assert.equal(await page.locator('#btn-form-submit,#btn-top-new,#btn-side-new,#btn-open-details').count(), 0, 'duplicate actions removed');
                await page.keyboard.press('Alt+i');
                assert(await page.locator('#lims_productcodeSearch').evaluate(e=>e===document.activeElement), 'search survives new draft');
                assert(await onScreen('#lims_productcodeSearch'), 'search stays on screen');
                assert(await onScreen('#btn-header-toggle-list'), 'header actions visible');
                const grid = await page.locator('#order-table').evaluate(e=>({width:e.scrollWidth,available:e.parentElement.clientWidth}));
                assert(grid.width<=grid.available+2, 'all item columns fit');
                await page.click('#btn-navbar-workspace-toggle');
                assert(await onScreen('#fullwidth-register-view'), 'register stays in workspace');
                await page.click('#document-tab-add');
                await page.waitForFunction(() => document.querySelector('#sale-entry-form,#purchase-entry-form').getBoundingClientRect().height > 0
                    && document.activeElement.matches('.dropdown-toggle,#customer_id,#supplier_id'));
                await page.keyboard.press('Alt+i');
                const state = await page.locator('#lims_productcodeSearch').evaluate(e=>({focused:e===document.activeElement,
                    active:document.activeElement.outerHTML.slice(0,200), rect:e.getBoundingClientRect().toJSON(),
                    modal:!!document.querySelector('dialog[open],.modal.show,[data-owns-keys]')}));
                assert(state.focused, 'search survives returning to entry '+JSON.stringify(state));
                await page.focus('#btn-create-item-modal');
                await page.keyboard.press('Enter');
                await page.locator('#quick-create-item-modal.show').waitFor();
                await page.waitForFunction(() => document.activeElement.closest('#quick-create-item-modal'));
                await page.keyboard.press('Alt+i');
                assert(!(await page.locator('#lims_productcodeSearch').evaluate(e=>e===document.activeElement)), 'dialog owns keyboard');
                await page.keyboard.press('Escape');
                await page.locator('#quick-create-item-modal').waitFor({state:'hidden'});
                await page.waitForFunction(() => document.activeElement.id === 'btn-create-item-modal');
            }
            if (process.env.DOCUMENT_LAYOUT_FIXTURES) await page.screenshot({path:path.join(process.env.DOCUMENT_LAYOUT_FIXTURES,`document-layout-${kind}-${viewport.width}.png`)});
            const lines = page.locator(form+' .document-lines-row .table-responsive, '+form+' .order-table-responsive, '+form+' > .table-responsive, '+form+' > .card > .table-responsive, '+form+' .card-body > .table-responsive').filter({visible:true}).first();
            if (await lines.count()) {
                const grid=await lines.evaluate(container=>({available:container.clientWidth,width:container.querySelector('table')?.scrollWidth||0}));
                assert(grid.width<=grid.available+2,kind+' line columns fit '+JSON.stringify(grid));
                if(kind==='purchase-existing-edit') {
                    const firstRow=await lines.locator('tbody tr').first().boundingBox();
                    const box=await lines.boundingBox();
                    assert(firstRow&&firstRow.y+firstRow.height<=box.y+box.height+1,'existing purchase first line stays visible');
                }
                await lines.evaluate(container => {
                    const body=container.querySelector('tbody');
                    if (!body) return;
                    for(let i=0;i<40;i++) {
                        const row=body.insertRow(); row.dataset.layoutProof='true';
                        const cell=row.insertCell(); cell.colSpan=12; cell.textContent='Layout proof row';
                    }
                });
                assert(await onScreen(save),kind+' growing lines keep Save visible');
                await lines.locator('[data-layout-proof]').evaluateAll(rows=>rows.forEach(row=>row.remove()));
            }
            check(`${kind}: viewport, Save, Enter and Tab at ${viewport.width}x${viewport.height}`, true);
        }
    }
    assert.deepEqual(errors, []);
    check('document screens have no JavaScript errors', true);
} catch(error) {
    fail(); console.error(error.stack);
    if(process.env.DOCUMENT_LAYOUT_FIXTURES) await page.screenshot({path:path.join(process.env.DOCUMENT_LAYOUT_FIXTURES,'document-layout-failure.png')});
}
await finish();
