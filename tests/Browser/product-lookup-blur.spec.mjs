/* Executes the actual sales/purchase change handlers with the real lookup module.
 * Uses an isolated DOM and intercepted HTTP; never writes ERP records. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {browser, context} from './support.mjs';

const page = await context.newPage(), errors = [], alerts = [];
page.on('pageerror', error => errors.push(error.message));
page.on('dialog', async dialog => { alerts.push(dialog.message()); await dialog.dismiss(); });
let responseMode = 'alias', releaseResponse, responseStarted, calls = 0;
await context.route('**/*', async route => {
    if (route.request().url().includes('/search/products')) {
        calls++;
        if (responseMode === 'delayed') {
            responseStarted();
            await new Promise(resolve => {releaseResponse = resolve;});
        }
        return route.fulfill(responseMode === 'error'
            ? {status: 503, json: {message: 'Unavailable'}}
            : {json: {data: responseMode === 'unknown' ? [] : [{id: 1, match_rank: 1}]}});
    }
    return route.fulfill({contentType: 'text/html', body: '<!doctype html><input name="customer_id" value="10"><input name="supplier_id" value="20"><table><tr><td><input class="product-name"><input class="row-product-id"><input class="row-product-code"></td></tr></table><button id="blur-target">Next</button>'});
});
try {
    for (const kind of ['sale', 'purchase']) {
        const source = fs.readFileSync(`resources/views/backend/${kind}/index.blade.php`, 'utf8');
        const start = source.indexOf("        $input.on('change', async function() {");
        assert.ok(start >= 0, `${kind} change handler exists`);
        const end = source.indexOf("        $input.on('keydown'", start);
        assert.ok(end > start);
        const binding = source.slice(start, end);
        for (const scenario of ['local', 'alias', 'unknown', 'error', 'changed-input', 'changed-party']) {
            responseMode = scenario.startsWith('changed-') ? 'delayed' : scenario;
            const before = calls, alertCount = alerts.length;
            const started = new Promise(resolve => { responseStarted = resolve; });
            await page.goto('http://erp-fixture.local/lookup', {waitUntil: 'domcontentloaded'});
            await page.addScriptTag({path: 'public/vendor/jquery/jquery.min.js'});
            await page.addScriptTag({path: 'public/js/commercial-product-lookup.js'});
            await page.evaluate(binding => {
                const $input = window.jQuery('.product-name');
                const allProducts = [{id: 1, name: 'Optical Lens', code: 'LENS-1'}];
                const matchesProductMode = () => true;
                const applyProductToRow = (row, product) => row.find('.row-product-id').val(product.id);
                const recalcTableSummary = () => {};
                window.created = [];
                window.lookupFinished = false;
                window.zoloOpenQuickCreateProduct = value => { window.created.push(value); return true; };
                const bind = $input.on.bind($input);
                $input.on = (event, handler) => bind(event, async function (...args) {
                    try { await handler.apply(this, args); }
                    finally { window.lookupFinished = true; }
                });
                eval(binding);
            }, binding);
            await page.locator('.product-name').fill(scenario === 'local' ? 'LENS-1' : 'Customer lens alias');
            await page.locator('#blur-target').click();
            if (responseMode === 'delayed') {
                await started;
                if (scenario === 'changed-input') await page.locator('.product-name').fill('New text');
                else await page.locator(`[name="${kind === 'sale' ? 'customer_id' : 'supplier_id'}"]`).fill('99');
                releaseResponse();
            }
            await page.waitForFunction(() => window.lookupFinished);
            const result = await page.evaluate(() => ({id: document.querySelector('.row-product-id').value, created: window.created}));
            assert.equal(result.id, ['local', 'alias'].includes(scenario) ? '1' : '', `${kind}: ${scenario}`);
            assert.equal(result.created.length, scenario === 'unknown' ? 1 : 0, `${kind}: ${scenario} creation`);
            assert.equal(calls - before, scenario === 'local' ? 0 : 1);
            assert.equal(alerts.length - alertCount, scenario === 'error' ? 1 : 0);
        }
    }
    assert.deepEqual(errors, []);
    console.log('PASS product blur: sale/purchase local match, alias match, unknown, HTTP error, changed input, changed party (12 cases)');
} finally {
    await browser.close();
}
