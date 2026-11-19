// Real Edge browser; every request is intercepted. Voice and API responses are fixtures.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {browser, context} from '../support.mjs';

const origin = 'http://127.0.0.1:8091';
const publicRoot = path.resolve('public');
const threadId = '11111111-1111-4111-8111-111111111111';
const taskId = '22222222-2222-4222-8222-222222222222';
const choiceSet = '33333333-3333-4333-8333-333333333333';
const directoryProductChoice = '77777777-7777-4777-8777-777777777777';
const directorySupplierChoice = '88888888-8888-4888-8888-888888888888';
const requests = [], errors = [], patches = [];
const taskSlots = {date: '2026-11-17', items: []};
let revision = 2, conflict = false, readyWorkflow = null;
let approvals = 0;
const mediaId = '55555555-5555-4555-8555-555555555555';
const workflow = () => ({status: 'needs_input', task_id: taskId, task_revision: revision,
    action_key: 'purchase_order.create.v1', message: 'Which supplier and product quantities?',
    required_inputs: [{field: 'items', label: 'Line Items', status: 'missing', control: 'line_items'}],
    confirmed_fields: [], can_prepare_proposal: false});
await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname === '/ai') return route.fulfill({contentType: 'text/html', body: fs.readFileSync('scratch/ai-workspace-enabled.html', 'utf8')
        .replace(/https?:\/\/(?:localhost|127\.0\.0\.1|zolo-erp-pro\.test)(?::\d+)?/g, origin)});
    if (url.pathname === '/ai/threads') return route.fulfill({json: request.method() === 'POST' ? {id: threadId} : {threads: requests.length ? [{id: threadId, title: 'Fixture PO'}] : []}});
    if (url.pathname === `/ai/threads/${threadId}`) return route.fulfill({json: {thread: {id: threadId}, messages: readyWorkflow ? [{role: 'assistant', content: readyWorkflow.message, facts: [{tool: 'workflow.clarification.v1', data: readyWorkflow}]}] : []}});
    if (url.pathname === '/ai/credits/balance') return route.fulfill({json: {data: {available_credits: 10000, included_granted: 10000, topups_granted: 0, spent_credits: 0}}});
    if (url.pathname === '/ai/chat') {
        const body = request.postDataJSON(); requests.push(body);
        if (conflict) { conflict = false; revision++; return route.fulfill({status: 409, json: {error_code: 'TASK_REVISION_CONFLICT', error_message: 'Draft changed. Review current details.'}}); }
        const state = workflow();
        if (body.message === 'can you tell me what are the suppliers and products available?') {
            state.message = 'Here are the existing suppliers and products.';
            state.directories = [
                {entity_type: 'supplier', items: [{name: 'ABC Test Traders'}], total: 1, has_more: false, can_create: true},
                {entity_type: 'product', items: [{name: 'Cotton Shirt Blue', code: 'CSB-001'}], total: 1, has_more: false, can_create: true},
            ];
            state.choice_set_id = choiceSet;
            state.choices = [
                {choice_id: directorySupplierChoice, label: 'ABC Test Traders', name: 'ABC Test Traders', field: 'supplier_id', entity_type: 'supplier'},
                {choice_id: directoryProductChoice, label: 'Cotton Shirt Blue (SKU: CSB-001)', name: 'Cotton Shirt Blue',
                    code: 'CSB-001', field: 'items[line-fixture].product_id', entity_type: 'product'},
            ];
        } else if (body.message === 'ABC') {
            state.task_revision = ++revision; state.choice_set_id = choiceSet;
            state.choices = ['ABC Chennai', 'ABC Erode'].map((label, index) => ({choice_id: `44444444-4444-4444-8444-44444444444${index}`, label, field: 'supplier_id', entity_type: 'supplier'}));
        } else if (body.choice_id) {
            state.task_revision = ++revision;
            if (body.choice_id === directoryProductChoice) {
                taskSlots.items = [{client_line_id: 'line-fixture', product_id: 77, qty: '', net_unit_cost: ''}];
            }
            state.confirmed_fields = body.choice_id === directoryProductChoice
                ? [{field: 'items[line-fixture].product_id', label: 'Line 1 Product', display: 'Cotton Shirt Blue', source: 'user_selected'}]
                : [{field: 'supplier_id', label: 'Supplier', display: 'ABC Erode', source: 'user_selected'}];
        } else if (body.message === 'Cash') {
            state.task_revision = ++revision; state.confirmed_fields = [{field: 'payment_terms_type', label: 'Commercial terms', display: 'cash', source: 'user'}];
        } else if (body.media_ids) {
            Object.assign(state, {status: 'proposal_created', task_revision: ++revision, proposal_id: '66666666-6666-4666-8666-666666666666', version: 1,
                message: 'ERP validation passed. Review the proposal.', preview: {document_type: 'Purchase Order', currency: 'INR', date: '2026-11-17',
                    supplier: {name: 'ABC Erode'}, warehouse: {name: 'Fixture Main'}, items: [{product_name: 'Cotton Shirt Blue', qty: '100', net_unit_cost: '480.00', line_total: '50400.00'}],
                    totals: {subtotal: '48000.00', tax_total: '2400.00', grand_total: '50400.00'}, payment_terms: {payment_terms_type: 'credit', credit_days: 30, due_date: '2026-12-17'}}});
            readyWorkflow = state;
        }
        return route.fulfill({json: {thread_id: threadId, interaction_type: 'workflow_continuation', workflow: state, message: state.message, sources: [], warnings: [], facts: []}});
    }
    if (url.pathname === `/ai/workflow-tasks/${taskId}/inputs` && request.method() === 'PATCH') {
        const body = request.postDataJSON();
        assert.equal(body.expected_revision, revision);
        patches.push(body);
        body.corrections.forEach(correction => {
            const match = correction.field.match(/^items\[line-fixture\]\.(qty|net_unit_cost)$/);
            if (match) taskSlots.items[0][match[1]] = correction.value;
            else taskSlots[correction.field] = correction.value;
        });
        revision++;
        return route.fulfill({json: {task: {id: taskId, revision}, evaluation: {is_ready: false}, clarification: workflow()}});
    }
    if (url.pathname === `/ai/workflow-tasks/${taskId}`) return route.fulfill({json: {task: {id: taskId, revision, slots: taskSlots, status: readyWorkflow ? 'proposal_created' : 'collecting', action_key: 'purchase_order.create.v1'}, clarification: workflow(), evaluation: {is_ready: Boolean(readyWorkflow)}}});
    if (url.pathname === `/ai/workflow-tasks/${taskId}/prepare-proposal`) return route.fulfill({json: {task_revision: revision, proposal: {id: readyWorkflow.proposal_id, version: 1, preview: readyWorkflow.preview}}});
    if (url.pathname === '/ai/proposals/66666666-6666-4666-8666-666666666666/approve') {
        approvals++;
        assert.deepEqual(request.postDataJSON(), {expected_version: 1});
        return route.fulfill({json: {status: 'completed', execution: {receipt: {resource_type: 'purchase_order', resource_id: 101,
            details: {reference_no: 'D08-PO-101', warehouse_name: 'Fixture Main', payment_terms: {payment_terms_type: 'credit', credit_days: 30}}}}}});
    }
    if (url.pathname === '/ai/media/prepare') return route.fulfill({json: {status: 'voice_reply_ready', task_id: taskId, task_revision: revision, thread_id: threadId,
        media: {id: mediaId, source_kind: 'audio'}, extraction: {revision: 1, extracted: {transcript_text: 'Credit thirty days', warnings: ['Confirm spoken credit days']}}}});
    if (url.pathname === `/ai/media/${mediaId}/confirm`) return route.fulfill({json: {extraction: {revision: 2, confirmation_status: 'confirmed', extracted: {transcript_text: request.postDataJSON().transcript_text}}}});
    const file = path.resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
    if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
        const types = {'.js': 'application/javascript', '.css': 'text/css', '.svg': 'image/svg+xml'};
        return route.fulfill({contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
    }
    return route.fulfill({json: {data: [], today: {}, threads: []}});
});
const page = await context.newPage();
page.setDefaultTimeout(10000);
page.on('pageerror', error => errors.push(error.message));
try {
    await page.goto(origin + '/ai', {waitUntil: 'networkidle'});
    assert.equal(await page.evaluate(() => Boolean(window.zoloAiConversation)), true, 'Conversation UI must be loaded');
    await page.locator('#chat-input').fill('Create a purchase order');
    await page.locator('#btn-send').click();
    await page.locator('#ai-active-task-header').waitFor({state: 'visible'});
    assert.equal(await page.locator('.ai-conversation-task').count(), 1);
    assert.equal(requests[0].thread_id, threadId);
    assert.ok(requests[0].client_turn_id);
    const initialRevision = revision;
    await page.locator('#chat-input').fill('can you tell me what are the suppliers and products available?');
    await page.locator('#btn-send').click();
    const supplierPanel = page.locator('.ai-po-section').filter({has: page.getByText('Supplier *', {exact: true})}).first();
    const productPanel = page.locator('.ai-po-section').filter({has: page.getByText('Products, Quantities & Rates *', {exact: true})}).first();
    await supplierPanel.getByRole('button', {name: 'ABC Test Traders'}).waitFor();
    await productPanel.getByRole('button', {name: /Cotton Shirt Blue/}).waitFor();
    assert.equal(await page.locator('.ai-po-workspace').count(), 1, 'One comprehensive Purchase Order workspace');
    assert.equal(await page.locator('.ai-po-section').count() >= 5, true, 'Supplier, warehouse, items and terms visible together');
    await productPanel.getByRole('button', {name: 'Create new product'}).click();
    await productPanel.getByRole('textbox', {name: 'New product name'}).waitFor();
    await productPanel.getByRole('button', {name: /Cotton Shirt Blue/}).click();
    assert.equal(requests.at(-1).choice_set_id, choiceSet);
    assert.equal(requests.at(-1).choice_id, directoryProductChoice);
    assert.equal(requests.at(-1).active_task_id, taskId);
    await page.locator('.ai-conversation-task').getByText('Cotton Shirt Blue', {exact: false}).waitFor();
    assert.equal(await page.locator('.ai-conversation-task').count(), 1, 'Clicking a product reuses the same draft card');
    assert.equal(revision, initialRevision + 1, 'Browsing does not mutate, selecting a product does');
    assert.equal(requests.at(-1).active_task_id, taskId);
    assert.equal(await page.locator('.ai-conversation-task').count(), 1);
    await page.locator('.ai-po-line-inputs input').first().waitFor();
    await page.locator('.ai-po-line-inputs input').first().fill('10');
    await page.locator('.ai-po-line-inputs input').nth(1).fill('600');
    const saved = page.waitForResponse(response => response.url().endsWith('/inputs') && response.request().method() === 'PATCH');
    await page.getByRole('button', {name: 'Apply details & revalidate'}).click();
    await saved;
    assert.equal(patches.length, 1, 'Changes from the complete UI use one revisioned patch');
    assert.deepEqual(patches[0].corrections.map(x => x.field).sort(),
        ['items[line-fixture].net_unit_cost', 'items[line-fixture].qty']);
    assert.equal(taskSlots.items[0].net_unit_cost, '600');
    assert.equal(taskSlots.items[0].qty, '10');
    await page.locator('#chat-input').fill('ABC');
    await page.locator('#btn-send').click();
    await page.getByRole('button', {name: 'ABC Erode', exact: true}).focus();
    await page.keyboard.press('Enter');
    await page.locator('.ai-conversation-task').getByText('ABC Erode', {exact: false}).waitFor();
    assert.equal(requests.at(-1).choice_set_id, choiceSet);
    assert.equal(requests.at(-1).active_task_id, taskId);
    await page.locator('#chat-input').fill('Cash');
    await page.locator('#btn-send').click();
    await page.locator('.ai-conversation-task').getByText('cash', {exact: false}).waitFor();
    assert.equal(await page.locator('.ai-conversation-task').count(), 1, 'Replies update one task card');
    assert.equal(await page.locator('.ai-conversation-task').getByText('₹0.00', {exact: true}).count(), 0, 'No unvalidated zero totals');
    conflict = true;
    await page.locator('#chat-input').fill('make it 120 instead');
    await page.locator('#btn-send').click();
    await page.locator('#ai-conversation-error').waitFor({state: 'visible'});
    assert.equal(await page.locator('#chat-input').inputValue(), 'make it 120 instead', '409 preserves reply');
    await page.setViewportSize({width: 390, height: 844});
    await page.waitForFunction(() => document.querySelector('#ai-active-task-header').getBoundingClientRect().left < 24, null, {timeout: 2000});
    assert.ok((await page.locator('#ai-active-task-header').boundingBox()).x < 24, 'Mobile workspace uses available width');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Mobile fits viewport');
    fs.mkdirSync('output/playwright', {recursive: true});
    await page.locator('.ai-conversation-task').scrollIntoViewIfNeeded();
    await page.screenshot({path: 'output/playwright/conversation-mobile.png', fullPage: true});
    await page.setViewportSize({width: 1440, height: 900});
    await page.evaluate(() => window.zoloAiMedia.stageFile(new File([new Uint8Array(44)], 'reply.wav', {type: 'audio/wav'})));
    await page.locator('#btn-send').click();
    await page.locator('#ai-voice-reply-transcript').fill('Credit 30 days');
    await page.getByRole('button', {name: 'Confirm transcript and send', exact: true}).click();
    await page.locator('.ai-po-terms').getByText(/credit.*30/).waitFor();
    assert.equal(requests.at(-1).voice_extraction_revision, 2);
    assert.deepEqual(requests.at(-1).media_ids, [mediaId]);
    assert.equal(await page.locator('.ai-conversation-task').count(), 1);
    assert.equal(await page.locator('.ai-unified-card').count(), 1);
    assert.ok((await page.locator('.ai-conversation-task .table-responsive').first().boundingBox()).height < 150, 'One-line preview has no empty table region');
    await page.screenshot({path: 'output/playwright/conversation-credit-review.png', fullPage: true});
    const chatCount = requests.length;
    await page.reload({waitUntil: 'networkidle'});
    await page.locator('.ai-po-terms').getByText(/credit.*30/).waitFor();
    assert.equal(requests.length, chatCount, 'Reload must not submit another chat/model turn');
    await page.locator('.ai-btn-approve-bill').click();
    await page.getByText('ERP Purchase Order Created', {exact: false}).waitFor();
    assert.equal(approvals, 1);
    assert.ok((await page.getByRole('link', {name: 'Download PDF Receipt'}).getAttribute('href')).includes('/purchase-order/101/pdf'));
    await page.getByText('Voucher Booked: D08-PO-101', {exact: false}).waitFor();
    assert.equal(await page.getByText('Step 3/3 · Awaiting explicit approval', {exact: true}).count(), 0, 'Completed order must not remain awaiting approval');
    await page.screenshot({path: 'output/playwright/conversation-order-receipt.png', fullPage: true});
    await page.setViewportSize({width: 390, height: 844});
    await page.evaluate(state => {
        state.preview.items = Array.from({length: 25}, (_, index) => ({...state.preview.items[0], product_name: `Line ${index + 1}: Cotton Shirt Blue with a long product and variant description`}));
        window.zoloAiConversation.render(state);
    }, readyWorkflow);
    assert.equal(await page.locator('.ai-conversation-task tbody tr').count(), 25);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'Long multi-line preview fits mobile');
    assert.ok((await page.locator('.ai-conversation-task .table-responsive').first().boundingBox()).height <= 360, 'Long preview has bounded scroll region');
    assert.deepEqual(errors, []);
    console.log('PASS consolidated PO form with batch revisioned edits, inline supplier/product choices, conversation desktop/mobile, keyboard, 409, voice, reload, final approval and receipt (mocked API)');
} finally { await browser.close(); }
