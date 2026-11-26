/* Export DocumentIntakeTest's workspace with ERP_EXPORT_AI_WORKSPACE_BROWSER=1 first.
 * Every network request is intercepted. No document, embedding, model or mail service is contacted. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browser, context} from '../support.mjs';

const origin = 'http://erp-fixture.local', root = path.resolve('public');
const errors = [], requests = [];
let current = null;
const documentId = '11111111-1111-4111-8111-111111111111';
await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === '/ai/documents-workspace') return route.fulfill({contentType: 'text/html',
        body: fs.readFileSync('scratch/ai-documents-finish.html', 'utf8')
            .replace(/https?:\/\/(?:localhost|127\.0\.0\.1|zolo-erp-pro\.test)(?::\d+)?/g, origin)});
    if (url.pathname === '/ai/documents' && request.method() === 'POST') {
        const body = request.postData();
        const classification = body.includes('opening_import') ? 'opening_import' : body.includes('product_import') ? 'product_import' : 'sop';
        assert.ok(request.headers()['x-csrf-token']);
        current = {id: documentId, status: 'quarantined', classification, expires_at: '2026-11-18T10:00:00Z'};
        return route.fulfill({json: {data: current}});
    }
    if (url.pathname === '/ai/documents/' + documentId + '/process') {
        current = {...current, status: current.classification === 'sop' ? 'extracted' : 'scanned', extraction: {needs_review: true}};
        return route.fulfill({json: {data: {status: 'queued'}}});
    }
    if (url.pathname === '/ai/documents/' + documentId) {
        if (request.method() === 'DELETE') { current = null; return route.fulfill({json: {data: {status: 'revoked'}}}); }
        return route.fulfill({json: {data: current}});
    }
    if (url.pathname === '/ai/product-imports') return route.fulfill({json: {data: {id: 'batch1', review_hash: 'review1',
        counts: {valid: 1, invalid: 1}, rows: [
            {row: 2, data: {name: '<img src=x onerror=window.__xss=1>', code: 'P1'}, status: 'valid'},
            {row: 3, data: {name: 'Invalid product', code: 'P2'}, status: 'invalid', errors: {price: ['Price must be positive']}}
        ]}}});
    if (url.pathname === '/ai/proposals') { requests.push(request.postDataJSON()); return route.fulfill({json: {proposal: {id: 'product-proposal'}}}); }
    if (url.pathname === '/ai/opening-imports/preview') { requests.push(request.postDataJSON()); return route.fulfill({json: {data: {data: {proposal_id: 'opening-proposal'}}}}); }
    if (url.pathname.endsWith('/approve-knowledge')) { current.status = 'approved'; return route.fulfill({json: {data: current}}); }
    if (url.pathname === '/ai/knowledge/search') return route.fulfill({json: {data: {citations: [{document_id: documentId,
        source_version: 2, page: 3, excerpt: '<script>window.__xss=1</script>Reviewed guide excerpt.'}]}}});
    const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
    if (file.startsWith(root + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
        const types = {'.js': 'application/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff': 'font/woff', '.woff2': 'font/woff2'};
        return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
    }
    return route.fulfill({json: {data: []}});
});
const page = await context.newPage();
page.setDefaultTimeout(10000);
page.on('pageerror', error => errors.push(error.message));
page.on('dialog', dialog => dialog.accept());
async function upload(classification) {
    await page.locator('#document-classification').selectOption(classification);
    await page.locator('#document-file').setInputFiles({name: classification === 'sop' ? 'guide.txt' : 'source.csv',
        mimeType: classification === 'sop' ? 'text/plain' : 'text/csv', buffer: Buffer.from('Reviewed source fixture')});
    await page.getByRole('button', {name: 'Upload to quarantine'}).click();
    await page.locator('#document-status').filter({hasText: 'quarantined'}).waitFor();
    assert.equal(await page.locator('#document-opening-panel').isVisible(), false);
    await page.getByRole('button', {name: 'Scan and extract'}).click();
    await page.locator('#document-status').filter({hasText: classification === 'sop' ? 'extracted' : 'scanned'}).waitFor();
}
try {
    await page.goto(origin + '/ai/documents-workspace', {waitUntil: 'networkidle'});
    await upload('product_import');
    await page.getByRole('button', {name: 'Validate product rows'}).click();
    await page.getByText('Price must be positive', {exact: true}).waitFor();
    assert.equal(await page.getByRole('checkbox', {name: 'Include row 3'}).isDisabled(), true);
    assert.equal(await page.locator('#document-import-rows img').count(), 0);
    await page.getByRole('button', {name: 'Prepare selected rows for approval'}).click();
    await page.locator('#document-import-result').filter({hasText: 'product-proposal'}).waitFor();
    assert.deepEqual(requests[0].slots.accepted_rows, [2]);
    assert.equal(requests[0].slots.review_hash, 'review1');

    await upload('opening_import');
    assert.equal(await page.getByRole('button', {name: 'Validate product rows'}).isVisible(), false);
    assert.equal(await page.getByRole('button', {name: 'Review and approve SOP indexing'}).isVisible(), false);
    await page.getByLabel('Opening type').selectOption('setup.opening_stock.create.v1');
    await page.getByLabel('Source description').fill('Reviewed migration');
    await page.getByLabel('Source reference').fill('OPEN-1');
    await page.getByLabel('Opening date').fill('2026-11-17');
    await page.getByLabel('Mapping source key').fill('P1');
    await page.getByLabel('Mapping record type').selectOption('product');
    await page.getByLabel('Company record ID').fill('42');
    await page.getByLabel('Expected stock quantity').fill('10');
    assert.equal(await page.getByLabel('Mapping record type').inputValue(), 'product', 'Selected product before adding another mapping');
    await page.getByRole('button', {name: 'Add mapping'}).click();
    assert.equal(await page.getByLabel('Mapping record type').first().inputValue(), 'product', 'Adding a mapping must retain previous selection');
    await page.getByLabel('Mapping record type').last().selectOption('warehouse');
    await page.getByLabel('Mapping source key').last().fill('W1');
    await page.getByLabel('Company record ID').last().fill('12');
    await page.getByRole('button', {name: 'Dry-run and prepare for approval'}).click();
    await page.locator('#opening-result').filter({hasText: 'opening-proposal'}).waitFor();
    assert.equal(requests[1].document_id, documentId);
    assert.equal(requests[1].action_key, 'setup.opening_stock.create.v1');
    assert.equal(requests[1].source.expected.stock_qty, '10');
    assert.deepEqual(requests[1].source.mappings, [{entity: 'product', source_key: 'P1', target_id: 42},
        {entity: 'warehouse', source_key: 'W1', target_id: 12}]);
    assert.equal(requests.some(request => 'approve' in request), false);
    for (const width of [320, 768, 1440]) {
        await page.setViewportSize({width, height: 1000});
        await page.waitForFunction(() => document.querySelector('.page').getAnimations().every(animation => animation.playState !== 'running'));
        await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 2);
        assert.equal(await page.getByRole('button', {name: 'Dry-run and prepare for approval'}).isVisible(), true);
    }
    fs.mkdirSync('output/playwright', {recursive: true});
    await page.screenshot({path: 'output/playwright/ai-documents-drift.png', fullPage: true});

    await upload('sop');
    await page.getByRole('button', {name: 'Review and approve SOP indexing'}).click();
    await page.locator('#document-status').filter({hasText: 'approved'}).waitFor();
    await page.getByLabel('Question about a company guide').fill('How do receipts work?');
    await page.getByRole('button', {name: 'Find excerpts'}).click();
    await page.locator('#document-citations').filter({hasText: 'Page 3'}).waitFor();
    assert.equal(await page.locator('#document-citations script').count(), 0);
    assert.equal(await page.evaluate(() => window.__xss), undefined);
    await page.getByRole('button', {name: 'Revoke access'}).click();
    await page.getByText('Document access revoked.', {exact: true}).waitFor();
    assert.equal(await page.getByRole('button', {name: 'Scan and extract'}).isDisabled(), true);
    assert.deepEqual(errors, []);
    console.log('PASS: quarantine/scan, safe product rows, exact reviewed selection, opening mappings/totals/proposal, SOP approval/citations/revocation, 320/768/1440 layouts. All services mocked.');
} finally { await browser.close(); }
