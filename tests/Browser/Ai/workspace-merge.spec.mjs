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
const supplierProposalId = '44444444-4444-4444-8444-444444444444';
const supplierTaskId = '55555555-5555-4555-8555-555555555555';
const supplierPhotoId = '66666666-6666-4666-8666-666666666666';
const supplierVoiceId = '77777777-7777-4777-8777-777777777777';
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
        const mediaPrepare = url.pathname === '/ai/media/prepare';
        const body = mediaPrepare ? request.postData() : request.postDataJSON();
        requests.push({method: request.method(), path: url.pathname, body});
        if (mediaPrepare) {
            const audio = body.includes('filename="supplier.wav"');
            const data = audio ? {transcript_text: 'Phone is 9876543210'} : {supplier_name: 'Same Chat Supplier'};
            return route.fulfill({json: {status: audio ? 'voice_reply_ready' : 'supplier_photo_review_ready',
                thread_id: threadId, task_id: supplierTaskId, task_revision: audio ? 4 : 3,
                media: {id: audio ? supplierVoiceId : supplierPhotoId}, extraction: {revision: 1, extracted: data}}});
        }
        if (url.pathname.endsWith('/confirm')) return route.fulfill({json: {extraction: {revision: 2}}});
        if (url.pathname === '/ai/threads') return route.fulfill({json: {threads: []}});
        if (url.pathname === '/ai/chat' || url.pathname === '/ai/chat/stream') {
            if (rejectChat) return route.fulfill({status: 422, json: {message: 'Review attachment before sending.'}});
            const result = {thread_id: threadId, message: 'Verified fixture response.', facts: [], sources: []};
            const message = request.postDataJSON().message;
            const revisions = {'create a supplier': 2, 'Supplier name is Same Chat Supplier': 3,
                'Use confirmed supplier details': 4, 'Phone is 9876543210': 5, 'preview supplier': 6};
            if (revisions[message]) {
                const task_revision = revisions[message];
                result.message = message === 'create a supplier' ? 'What is the supplier’s name?' : 'Supplier draft ready for review.';
                result.workflow = {task_id: supplierTaskId, task_revision, action_key: 'setup.supplier.create.v1',
                    status: message === 'preview supplier' ? 'proposal_created' : 'needs_input'};
                result.facts = message === 'preview supplier' ? [{tool: 'setup.supplier.create.v1', data: {
                    name: 'Same Chat Supplier', proposal_id: supplierProposalId, task_id: supplierTaskId, task_revision, version: 1,
                }}] : [];
            }
            return route.fulfill(url.pathname.endsWith('/stream') ? {contentType: 'text/event-stream', body:
                'event: delta\ndata: {"text":"Verified fixture response."}\n\nevent: complete\ndata: ' + JSON.stringify(result) + '\n\n'} : {json: result});
        }
        if (url.pathname === '/ai/credits/balance') return route.fulfill({json: {available_credits: 10000, total_credits: 10000, reserved_credits: 0, spent_credits: 0}});
        if (url.pathname === '/ai/credits/packs') return route.fulfill({json: {packs: []}});
        if (url.pathname === '/ai/proposals/' + supplierProposalId + '/approve') return route.fulfill({json: {
            status: 'completed', execution: {receipt: {resource_type: 'supplier', resource_id: 17}},
        }});
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
        const plainChat = requests.filter(r => r.path.startsWith('/ai/chat')).at(-1);
        assert.equal(plainChat.body.message, 'Show customer outstanding');
        assert.equal('media_ids' in plainChat.body, false);
        assert.equal(await page.locator('#btn-send').isEnabled(), true);
        if (mode === 'disabled') continue;

        await page.evaluate(() => { window.zoloAiMultimodalConfig.conversationEnabled = true; });
        await page.addScriptTag({url: origin + '/js/ai-conversation-workflow.js'});
        await page.locator('#chat-input').fill('create a supplier');
        await page.locator('#btn-send').click();
        await page.getByText('What is the supplier’s name?', {exact: true}).waitFor();
        assert.equal(await page.getByRole('form', {name: 'Supplier details'}).count(), 0);
        await page.locator('#chat-input').fill('Supplier name is Same Chat Supplier');
        await page.locator('#btn-send').click();
        await page.waitForFunction(() => window.zoloAiConversation.getActiveTask().task_revision === 3);
        await page.evaluate(() => window.zoloAiMedia.stageFile(new File(['fixture'], 'supplier.png', {type: 'image/png'})));
        await page.locator('#btn-send').click();
        await page.getByRole('button', {name: 'Use these details'}).click();
        await page.waitForFunction(() => window.zoloAiConversation.getActiveTask().task_revision === 4);
        await page.evaluate(() => window.zoloAiMedia.stageFile(new File(['fixture'], 'supplier.wav', {type: 'audio/wav'})));
        await page.locator('#btn-send').click();
        await page.getByRole('button', {name: 'Confirm transcript and send'}).click();
        await page.waitForFunction(() => window.zoloAiConversation.getActiveTask().task_revision === 5);
        await page.locator('#chat-input').fill('preview supplier');
        await page.locator('#btn-send').click();
        const supplierCard = page.locator('.ai-proposal-review-card', {hasText: 'Same Chat Supplier'});
        await supplierCard.waitFor();
        const supplierReply = requests.find(r => r.body?.message === 'Supplier name is Same Chat Supplier').body;
        assert.equal(supplierReply.thread_id, threadId);
        assert.equal(supplierReply.active_task_id, supplierTaskId);
        assert.equal(supplierReply.expected_task_revision, 2);
        assert.equal(requests.find(r => r.body?.message === 'Use confirmed supplier details').body.supplier_photo_revision, 2);
        assert.equal(requests.find(r => r.body?.message === 'Phone is 9876543210').body.voice_extraction_revision, 2);
        assert.equal(requests.some(r => r.path === '/ai/proposals/' + supplierProposalId + '/approve'), false);
        await supplierCard.getByRole('button', {name: 'Approve & Create'}).click();
        await supplierCard.getByText('Supplier ready in ERP', {exact: true}).waitFor();
        assert.equal(await page.evaluate(() => window.zoloAiConversation.getActiveTask()), null);
        await page.evaluate(() => { delete window.zoloAiConversation; });

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
        const mediaChat = requests.filter(r => r.path.startsWith('/ai/chat')).at(-1);
        assert.deepEqual(mediaChat.body.media_ids, [mediaId]);
        assert.equal(mediaChat.body.thread_id, threadId);
        assert.equal(await page.locator('#btn-send-spinner').isHidden(), true);

        await mediaCard.locator('.ai-btn-media-handoff').click();
        const proposalCard = page.locator('.erp-fact-card', {hasText: 'Purchase Order Proposal'});
        await proposalCard.waitFor();
        await proposalCard.getByRole('button', {name: 'Approve & Create'}).click();
        const receipt = proposalCard.locator('.alert-success', {hasText: 'PO-FIXTURE'});
        await receipt.waitFor();
        await receipt.getByRole('button', {name: 'View PDF in Chat'}).click();
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
    console.log('PASS workspace merge: desktop/mobile, chat, same-thread supplier setup, media, approval, receipt/PDF, and clarification');
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
