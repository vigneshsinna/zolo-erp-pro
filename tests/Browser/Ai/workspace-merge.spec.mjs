/* Export fixtures with ERP_EXPORT_AI_WORKSPACE_BROWSER=1 and AiChatApiAndWorkspaceTest.
 * Every request is intercepted; this spec never writes ERP records. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { browser, context } from '../support.mjs';

const origin = 'http://erp-fixture.local';
const root = path.resolve('public');
const requests = [], errors = [];
let mode = 'enabled', rejectChat = false;
const threadId = '11111111-1111-4111-8111-111111111111';
const mediaId = '22222222-2222-4222-8222-222222222222';
const pdfUrl = '/ai/documents/purchase/42/pdf';
const proposal = {
    proposal_id: 'proposal-fixture', version: 1, document_type: 'Purchase Order', supplier: {name: 'Optical Supplier'},
    items: [{product_name: 'Optical Lens', qty: '2.00', net_unit_cost: '50.00', line_total: '100.00'}],
    totals: {subtotal: '100.00', tax_total: '0.00', grand_total: '100.00'},
};
await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === '/ai') {
        const html = fs.readFileSync(`scratch/ai-workspace-${mode}.html`, 'utf8')
            .replace(/https?:\/\/(?:localhost|127\.0\.0\.1|zolo-erp-pro\.test)(?::\d+)?/g, origin);
        return route.fulfill({contentType: 'text/html', body: html});
    }
    if (url.pathname.startsWith('/ai/')) {
        requests.push({method: request.method(), path: url.pathname, body: request.postDataJSON()});
        if (url.pathname === '/ai/threads') return route.fulfill({json: {threads: []}});
        if (url.pathname === '/ai/chat') return route.fulfill(rejectChat
            ? {status: 422, json: {message: 'Review attachment before sending.'}}
            : {json: {thread_id: threadId, message: 'Verified fixture response.', facts: [], sources: []}});
        if (url.pathname.endsWith('/handoff')) return route.fulfill({json: {
            status: 'proposal_created', action_key: 'purchase_order.create.v1',
            proposal: {proposal_id: proposal.proposal_id, version: 1, preview: proposal},
        }});
        if (url.pathname.endsWith('/approve')) return route.fulfill({json: {
            status: 'completed',
            execution: {receipt: {resource_type: 'purchase_order', resource_id: 42,
                details: {reference_no: 'PO-FIXTURE', supplier_name: 'Optical Supplier', grand_total: '100.00'}}},
        }});
        if (url.pathname === '/ai/monitoring') return route.fulfill({json: {
            rpm: {provider: 'fixture', current: 1, limit: 35, status: 'healthy'},
            today: {model_invocations: 1, erp_tool_calls: 1, total_tokens: 100, daily_token_limit: 10000},
        }});
        if (url.pathname === pdfUrl) return route.fulfill({contentType: 'application/pdf', body: '%PDF-1.4\n%%EOF'});
        throw new Error(`Unexpected AI request: ${request.method()} ${url.pathname}`);
    }
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
try {
    for (mode of ['disabled', 'enabled']) {
        await page.goto(origin + '/ai', {waitUntil: 'networkidle'});
        assert.equal(await page.evaluate(() => typeof window.zoloAiWorkspace?.appendMessage), 'function');
        assert.equal(await page.locator('#ai-response-status').count(), 1);
        assert.equal(await page.locator('.ai-composer-toolbar').count(), 1);
        assert.equal(await page.locator('#btn-ai-attach-media').count(), mode === 'enabled' ? 1 : 0);
        assert.equal((await page.locator('#chat-form').textContent()).includes('maxlength="4000"'), false);
        for (const width of [320, 768, 1440]) {
            await page.setViewportSize({width, height: 1000});
            await page.waitForFunction(() => {
                const bounds = document.querySelector('#btn-send').getBoundingClientRect();
                return bounds.x >= 0 && bounds.right <= innerWidth;
            }, null, {timeout: 5000});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Page overflow at ${width}`);
            const send = await page.locator('#btn-send').boundingBox();
            assert.ok(send.x >= 0 && send.x + send.width <= width, `Send outside viewport at ${width}`);
        }
        await page.locator('#chat-input').fill('Show customer outstanding');
        await page.locator('#btn-send').click();
        await page.getByText('Verified fixture response.', {exact: true}).waitFor();
        assert.equal(await page.evaluate(() => sessionStorage.getItem('ai_active_thread')), threadId);
        const plainChat = requests.filter(r => r.path === '/ai/chat').at(-1);
        assert.equal(plainChat.body.message, 'Show customer outstanding');
        assert.equal('media_ids' in plainChat.body, false);
        assert.equal(await page.locator('#btn-send').isEnabled(), true);
        if (mode === 'disabled') continue;

        await page.evaluate(({mediaId}) => window.zoloAiMedia.renderExtractionCard({
            id: mediaId, file_name: 'optical-invoice.png', source_kind: 'image', preview_url: '/fixture-preview.png',
        }, {revision: 1, confirmation_status: 'confirmed', extracted: {supplier_name: 'Optical Supplier', lines: []}}), {mediaId});
        const mediaCard = page.locator('.ai-media-review-card');
        await mediaCard.locator('.ai-btn-media-attach').click();
        rejectChat = true;
        await page.locator('#chat-input').fill('Use this invoice');
        await page.locator('#btn-send').click();
        await page.getByText('[AI_ERROR] Review attachment before sending.', {exact: true}).waitFor();
        assert.deepEqual(await page.evaluate(() => window.zoloAiMedia.getAttachedMediaIds()), [mediaId]);
        rejectChat = false;
        await page.locator('#chat-input').fill('Use this invoice again');
        await page.locator('#btn-send').click();
        await page.waitForFunction(() => window.zoloAiMedia.getAttachedMediaIds().length === 0);
        const mediaChat = requests.filter(r => r.path === '/ai/chat').at(-1);
        assert.deepEqual(mediaChat.body.media_ids, [mediaId]);
        assert.equal(mediaChat.body.thread_id, threadId);
        assert.equal(await page.locator('#btn-send-spinner').isHidden(), true);

        await mediaCard.locator('.ai-btn-media-handoff').click();
        const proposalCard = page.locator('.erp-fact-card', {hasText: 'Purchase Order Proposal'});
        await proposalCard.waitFor();
        await proposalCard.getByRole('button', {name: 'Approve & Execute'}).click();
        const receipt = page.locator('.ai-message-assistant', {hasText: 'Official Receipt'});
        await receipt.waitFor();
        await receipt.locator('.btn-preview-pdf').click();
        await page.locator('#ai-pdf-preview-modal.show').waitFor();
        assert.ok((await page.locator('#ai-pdf-iframe').getAttribute('src')).endsWith(pdfUrl));
        assert.equal(page.url(), origin + '/ai');
        await page.locator('#ai-pdf-preview-modal .close').click();
        await page.waitForFunction(() => document.querySelector('#ai-pdf-iframe').getAttribute('src') === '');

        await page.evaluate(() => window.zoloAiWorkspace.appendMessage('assistant', 'Choose matching product.', [], [], [{
            tool: 'workflow.clarification.v1', data: {task_id: 'task-fixture', task_revision: 3,
                required_inputs: [{field: 'supplier_id', label: 'Supplier', status: 'ambiguous',
                    candidates: [{id: 8, name: 'Optical Supplier'}], resolution_options: [{key: 'select_existing'}, {key: 'correct_name'}]}]},
        }]));
        const clarification = page.locator('.ai-clarification-card');
        await clarification.getByRole('button', {name: 'Select existing', exact: true}).click();
        await clarification.getByRole('button', {name: 'Optical Supplier', exact: true}).click();
        assert.equal(await clarification.locator('.ai-candidate-btn.active').count(), 1);
        await page.screenshot({path: 'scratch/ai-workspace-merge-fixed.png', fullPage: true});
    }
    assert.deepEqual(errors, [], 'No uncaught browser errors');
    console.log('PASS workspace merge: disabled/enabled, desktop/mobile, chat, attachment retry/clear, handoff, approval, receipt/PDF, clarification');
} catch (error) {
    await page.screenshot({path: 'scratch/ai-workspace-merge-failure.png', fullPage: true});
    console.error(JSON.stringify(await page.evaluate(() => ({
        width: innerWidth, scrollX, status: document.querySelector('#ai-response-status')?.textContent,
        card: document.querySelector('.ai-chat-card')?.getBoundingClientRect().toJSON(),
        send: document.querySelector('#btn-send')?.getBoundingClientRect().toJSON(),
        toolbar: document.querySelector('.ai-composer-toolbar')?.getBoundingClientRect().toJSON(),
    })), null, 2));
    console.error({mode, errors});
    throw error;
} finally {
    await browser.close();
}
