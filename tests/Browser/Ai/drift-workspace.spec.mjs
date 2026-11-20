/* Run after exporting workspace fixtures with ERP_EXPORT_AI_WORKSPACE_BROWSER=1.
 * All endpoints are intercepted. Speech recognition is simulated; no microphone or model is contacted. */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { browser, context } from '../support.mjs';

const encoder = new TextEncoder();
const parserWindow = {};
let frames = '';
vm.runInNewContext(fs.readFileSync('public/js/ai-workspace-stream.js', 'utf8'), {
    window: parserWindow, TextDecoder, Uint8Array,
    fetch: async () => new Response(new ReadableStream({start(controller) {
        const bytes = encoder.encode(frames);
        for (let i = 0; i < bytes.length; i += 3) controller.enqueue(bytes.slice(i, i + 3));
        controller.close();
    }}), {headers: {'Content-Type': 'text/event-stream'}}),
});
frames = 'event: delta\r\ndata: {"text":"₹ தமிழ்"}\r\n\r\nevent: complete\r\ndata: {"message":"₹ தமிழ்"}\r\n\r\n';
let received = '';
const parsed = await parserWindow.ZoloAiStream.request('/fixture', {}, {}, text => received += text);
assert.equal(received, '₹ தமிழ்');
assert.equal(parsed.message, received);
frames = 'event: delta\ndata: {"text":"unfinished"}\n\n';
await assert.rejects(parserWindow.ZoloAiStream.request('/fixture', {}, {}, () => {}), error => error.responseJSON.error_code === 'AI_STREAM_INTERRUPTED');
frames = 'event: error\ndata: {"http_status":429,"error_code":"AI_QUOTA_EXCEEDED"}\n\n';
await assert.rejects(parserWindow.ZoloAiStream.request('/fixture', {}, {}, () => {}), error => error.status === 429);

const origin = 'http://erp-fixture.local', root = path.resolve('public');
const errors = [], patches = [], chatRequests = [];
let conflict = true, revision = 3, streamFailure = false;
const taskId = '33333333-3333-4333-8333-333333333333';
const clarification = (withSecond = true) => ({task_id: taskId, task_revision: revision, action_key: 'purchase_order.create.v1',
    confirmed_fields: [{field: 'items[keep].qty', label: 'Line 1 Quantity', display: '2.00'}],
    line_items: [{client_line_id: 'keep', label: 'Kept product'}, ...(withSecond ? [{client_line_id: 'remove', label: 'Unneeded product'}] : [])],
    required_inputs: [{field: 'supplier_id', label: 'Supplier', status: 'missing', reason: 'Select a supplier', control: 'text'},
        ...(withSecond ? [{field: 'items[remove].product_id', label: 'Line 2 Product', status: 'missing', reason: 'Choose a product', control: 'text'}] : [])]});
await context.addInitScript(() => {
    window.__recognitions = [];
    window.SpeechRecognition = class {
        constructor() { window.__recognitions.push(this); }
        start() { this.onstart?.(); }
        stop() { this.onend?.(); }
        abort() { this.onend?.(); }
    };
});
await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === '/ai') return route.fulfill({contentType: 'text/html', body: fs.readFileSync('scratch/ai-workspace-enabled.html', 'utf8')
        .replace(/https?:\/\/(?:localhost|127\.0\.0\.1|zolo-erp-pro\.test)(?::\d+)?/g, origin)});
    if (url.pathname === '/ai/threads') return route.fulfill({json: {threads: []}});
    if (url.pathname === '/ai/credits/balance') return route.fulfill({json: {data: {available_credits: 10000, included_granted: 10000, topups_granted: 0, spent_credits: 0, reserved_credits: 0}}});
    if (url.pathname === '/ai/chat/stream') {
        chatRequests.push({body: request.postDataJSON(), headers: request.headers()});
        const final = {thread_id: '11111111-1111-4111-8111-111111111111', message: 'Verified stream finished.', facts: [], sources: []};
        return route.fulfill({contentType: 'text/event-stream', body: streamFailure
            ? 'event: error\ndata: {"http_status":429,"error_code":"AI_QUOTA_EXCEEDED","error_message":"Wait for quota reset."}\n\n'
            : 'event: delta\ndata: {"text":"<img src=x onerror=window.__xss=1>₹"}\n\nevent: complete\ndata: ' + JSON.stringify(final) + '\n\n'});
    }
    if (url.pathname.endsWith('/inputs')) {
        patches.push(request.postDataJSON());
        if (conflict) { conflict = false; revision++; return route.fulfill({status: 409, json: {message: 'Task changed'}}); }
        revision++;
        return route.fulfill({json: {task: {revision}, evaluation: {is_ready: false}, clarification: clarification(false)}});
    }
    if (url.pathname === '/ai/workflow-tasks/' + taskId) return route.fulfill({json: {task: {revision}, clarification: clarification()}});
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
    await page.goto(origin + '/ai', {waitUntil: 'networkidle'});
    // SSE regression is opt-in; production Copilot uses JSON by default.
    await page.evaluate(() => { window.ZoloAiStreamEnabled = true; });
    await page.getByRole('button', {name: 'Start voice input'}).click();
    await page.evaluate(() => {
        const result = [{transcript: 'Review customer balances'}]; result.isFinal = true;
        const recognition = window.__recognitions.at(-1);
        recognition.onresult({results: [result]}); recognition.onend();
    });
    assert.equal(await page.locator('#chat-input').inputValue(), 'Review customer balances');
    assert.equal(chatRequests.length, 0, 'Dictation must not submit a request');
    await page.locator('#btn-send').click();
    await page.getByText('Verified stream finished.', {exact: true}).waitFor();
    assert.equal(chatRequests[0].body.message, 'Review customer balances');
    assert.match(chatRequests[0].body.client_turn_id, /^[0-9a-f]{8}-[0-9a-f-]{27,}$/i);
    assert.ok(chatRequests[0].headers['x-csrf-token']);
    assert.ok(chatRequests[0].headers['x-ai-scope']);
    assert.equal(await page.evaluate(() => window.__xss), undefined);
    assert.equal(await page.locator('#btn-send').isEnabled(), true);
    streamFailure = true;
    await page.locator('#chat-input').fill('Retry quota check');
    await page.locator('#btn-send').click();
    await page.getByText('[AI_QUOTA_EXCEEDED] Wait for quota reset.', {exact: true}).waitFor();
    assert.equal(chatRequests.length, 2, 'An error must not retry the model request');
    await page.getByRole('button', {name: 'Start voice input'}).click();
    await page.evaluate(() => { const recognition = window.__recognitions.at(-1); recognition.onerror({error: 'not-allowed'}); recognition.onend(); });
    await page.getByText('Microphone access denied. Type your message instead.', {exact: true}).waitFor();

    await page.evaluate(data => window.zoloAiWorkspace.appendMessage('assistant', 'Review line items.', [], [], [{tool: 'workflow.clarification.v1', data}]), clarification());
    let card = page.locator('.ai-clarification-card');
    await card.getByRole('button', {name: 'Remove line: Unneeded product', exact: true}).click();
    assert.equal(await card.locator('.ai-clarification-issue[data-field="items[remove].product_id"]').isVisible(), false);
    await card.getByRole('button', {name: 'Undo removal: Unneeded product', exact: true}).click();
    assert.equal(await card.locator('.ai-clarification-issue[data-field="items[remove].product_id"]').isVisible(), true);
    await card.getByRole('button', {name: 'Remove line: Unneeded product', exact: true}).click();
    await card.getByRole('button', {name: 'Submit Details', exact: true}).click();
    await page.getByText('Task changed. Review the latest details and submit again.', {exact: true}).waitFor();
    assert.equal(patches.length, 1, 'Revision conflict must not silently retry deletion');
    card = page.locator('.ai-clarification-card');
    await card.getByRole('button', {name: 'Remove line: Unneeded product', exact: true}).click();
    await card.getByRole('button', {name: 'Submit Details', exact: true}).click();
    await page.getByText('Review the remaining details.', {exact: true}).waitFor();
    assert.equal(patches.length, 2);
    assert.deepEqual(patches[1], {expected_revision: 4, corrections: [{field: 'items[remove]._destroy', value: true}]});
    assert.equal(await page.getByRole('button', {name: 'Remove line: Unneeded product', exact: true}).count(), 0);
    for (const width of [320, 768, 1440]) {
        await page.setViewportSize({width, height: 1000});
        await page.waitForFunction(() => document.querySelector('.page').getAnimations().every(animation => animation.playState !== 'running'));
        await page.waitForFunction(() => {
            const send = document.querySelector('#btn-send').getBoundingClientRect();
            return send.x >= 0 && send.right <= innerWidth;
        });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        const send = await page.locator('#btn-send').boundingBox();
        assert.ok(send.x >= 0 && send.x + send.width <= width, JSON.stringify({width, send}));
    }
    fs.mkdirSync('output/playwright', {recursive: true});
    await page.screenshot({path: 'output/playwright/ai-drift-workspace.png', fullPage: true});
    assert.deepEqual(errors, []);
    console.log('PASS: split UTF-8/CRLF SSE frames, interrupted/error streams, CSRF/scope headers, safe deltas, dictation/no autosend/denial, remove/undo/revision conflict, 320/768/1440 layouts.');
} catch (error) {
    console.error({errors, clarification: await page.locator('.ai-clarification-card').allTextContents()});
    throw error;
} finally { await browser.close(); }
