// Mocked Edge browser: first voice instruction must never render a Purchase Bill
// or execute a financial action before confirmed PO conversation intent.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browser, context} from '../support.mjs';

const origin = 'http://127.0.0.1:8091';
const publicRoot = path.resolve('public');
const threadId = '11111111-1111-4111-8111-111111111111';
const mediaId = '22222222-2222-4222-8222-222222222222';
const taskId = '33333333-3333-4333-8333-333333333333';
const transcript = 'Create a purchase order for ABC Traders';
const requests = [];
const errors = [];

await context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/ai') {
        return route.fulfill({contentType: 'text/html', body:
            fs.readFileSync('scratch/ai-workspace-enabled.html', 'utf8')
                .replace(/https?:\/\/(?:localhost|127\.0\.0\.1|zolo-erp-pro\.test)(?::\d+)?/g, origin)});
    }
    if (url.pathname === '/ai/threads') {
        return route.fulfill({json: request.method() === 'POST'
            ? {id: threadId} : {threads: []}});
    }
    if (url.pathname === '/ai/credits/balance') {
        return route.fulfill({json: {data: {available_credits: 10000, included_granted: 10000,
            topups_granted: 0, spent_credits: 0}}});
    }
    if (url.pathname === '/ai/media/prepare') {
        assert.equal(request.method(), 'POST');
        const data = request.postDataBuffer().toString('utf8');
        assert.match(data, /name="thread_id"/);
        requests.push({kind: 'media_prepare'});
        return route.fulfill({json: {status: 'voice_intent_review_ready', thread_id: threadId,
            media: {id: mediaId, source_kind: 'audio'},
            extraction: {revision: 1, extracted: {transcript_text: transcript,
                intent_guess: 'purchase_bill.create.v1', warnings: []}},
            intent_key: 'purchase_order.create.v1',
            message: 'Review and confirm the transcription.'}});
    }
    if (url.pathname === '/ai/media/' + mediaId + '/confirm') {
        const body = request.postDataJSON();
        assert.equal(body.transcript_text, transcript);
        assert.equal(body.expected_revision, 1);
        requests.push({kind: 'voice_confirm'});
        return route.fulfill({json: {status: 'ok',
            extraction: {revision: 2, confirmation_status: 'confirmed',
                extracted: {transcript_text: transcript, warnings: []}}}});
    }
    if (url.pathname === '/ai/chat') {
        const body = request.postDataJSON();
        assert.equal(body.thread_id, threadId);
        assert.deepEqual(body.media_ids, [mediaId]);
        assert.equal(body.voice_extraction_revision, 2);
        assert.equal(body.message, transcript);
        requests.push({kind: 'chat', body});
        return route.fulfill({json: {thread_id: threadId, interaction_type: 'workflow_continuation',
            message: 'Select your supplier and products to continue the Purchase Order.',
            workflow: {status: 'needs_input', task_id: taskId, task_revision: 1,
                action_key: 'purchase_order.create.v1',
                message: 'Select supplier and product to continue.',
                directories: [{entity_type: 'product', items: [{name: 'Cotton Shirt Blue', code: 'CSB'}],
                    total: 1, has_more: false, can_create: true}],
                required_inputs: [], confirmed_fields: [], can_prepare_proposal: false},
            facts: [], sources: [], warnings: []}});
    }
    if (url.pathname === '/ai/threads/' + threadId) {
        return route.fulfill({json: {thread: {id: threadId}, messages: []}});
    }
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
        const types = {'.js': 'application/javascript', '.css': 'text/css', '.svg': 'image/svg+xml'};
        return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream',
            body: fs.readFileSync(file)});
    }
    return route.fulfill({json: {data: [], today: {}, threads: []}});
});

const page = await context.newPage();
page.setDefaultTimeout(10000);
page.on('pageerror', err => errors.push(err.message));
try {
    await page.goto(origin + '/ai', {waitUntil: 'networkidle'});
    assert.equal(await page.evaluate(() => Boolean(window.zoloAiConversation)), true);
    await page.locator('#chat-input').fill('Analyze this voice instruction');
    await page.evaluate(() => window.zoloAiMedia.stageFile(
        new File([new Uint8Array(70)], 'voice_first.webm', {type: 'audio/webm'})));
    await page.locator('#btn-send').click();
    await page.locator('#ai-voice-initial-transcript').waitFor();
    assert.equal(await page.getByText('Purchase Bill Review', {exact: false}).count(), 0);
    assert.equal(await page.locator('.ai-unified-card').count(), 0);
    assert.equal(requests.filter(x => x.kind === 'chat').length, 0, 'No draft before confirmation');
    assert.equal(await page.locator('#ai-voice-initial-transcript').inputValue(), transcript);
    await page.getByRole('button', {name: 'Confirm transcript & continue'}).click();
    await page.locator('#ai-active-task-header').waitFor({state: 'visible'});
    await page.locator('.ai-conversation-task').getByText('Cotton Shirt Blue').waitFor();
    assert.equal(await page.getByText('Purchase Bill Review', {exact: false}).count(), 0);
    assert.deepEqual(requests.map(r => r.kind), ['media_prepare', 'voice_confirm', 'chat']);

    // Missing or contradictory action keys never become a Purchase Bill UI.
    await page.evaluate(() => window.zoloAiMedia.renderUnifiedWorkflowCard({
        status: 'needs_input', task_id: taskId, clarification: {required_inputs: []},
    }, '', null));
    assert.equal(await page.getByText('Purchase Bill Review', {exact: false}).count(), 0);
    await page.getByText('The requested ERP transaction type is missing or conflicting.', {exact: false}).waitFor();
    assert.deepEqual(errors, []);
    console.log('PASS first-voice confirmation, PO continuation and no purchase-bill fallback');
} finally {
    await page.close();
    await context.close();
    await browser.close();
}
