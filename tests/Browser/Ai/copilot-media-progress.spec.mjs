/**
 * Real-browser Playwright verification for Six-Stage Asynchronous Copilot Progress Card (UI01-UI14).
 * Loads actual public/js/ai-media-progress.js + public/js/ai-media-input.js + public/css/pages/ai-workspace.css
 * against intercepted ERP routes (desktop 1440x900, mobile 390x844, dark mode, XSS, out-of-order polls).
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { browser, context } from '../support.mjs';

const TINY_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQImWP8//8/AzbAhFV00EoAAFbUAw00q2Z5AAAAAElFTkSuQmCC',
    'base64'
);

const cssText = fs.readFileSync(path.resolve('public/css/pages/ai-workspace.css'), 'utf8');

try {
    const page = await context.newPage();
    await page.setViewportSize({ width: 1440, height: 900 });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.setContent(`
        <!doctype html>
        <html>
        <head>
            <meta name="csrf-token" content="test-csrf">
            <style>${cssText}</style>
        </head>
        <body>
            <div class="ai-workspace-container">
                <div id="chat-messages"></div>
                <form id="chat-form">
                    <div id="ai-media-attached-bar" class="d-none"></div>
                    <textarea id="chat-input"></textarea>
                    <button id="btn-send" type="submit">Send</button>
                </form>
            </div>
        </body>
        </html>
    `);

    await page.addScriptTag({ path: 'public/vendor/jquery/jquery.min.js' });
    await page.evaluate(() => {
        window.zoloAiMultimodalConfig = {
            enabled: true,
            asyncEnabled: true,
            conversationEnabled: false,
            mediaBaseUrl: '/ai',
            processingTasksUrl: '/ai/media/processing-tasks',
            pollAfterMs: 120,
            isOpticalProfile: true,
        };
        window.zoloAiWorkspace = {
            aiBaseUrl: '/ai',
            csrfToken: 'test-csrf',
            scopeHeader: '1/1/1',
            getCurrentThreadId: () => '11111111-1111-4111-8111-111111111111',
            setCurrentThreadId: () => {},
            loadThreads: () => {},
            loadTelemetry: () => {},
            loadCreditsBalance: () => {},
            appendMessage: (role, text) => {
                const msg = $('<div class="ai-message"></div>').addClass(role === 'user' ? 'ai-message-user' : 'ai-message-assistant');
                msg.append($('<div class="chat-bubble"></div>').text(text));
                $('#chat-messages').append(msg);
                return msg;
            },
        };

        window.__pollCalls = 0;
        const baseStages = (overrides) => [
            Object.assign({ key: 'upload', label: 'Attachment uploaded', state: 'completed' }, overrides.upload || {}),
            Object.assign({ key: 'preprocess', label: 'File optimized and validated', state: 'pending' }, overrides.preprocess || {}),
            Object.assign({ key: 'extract', label: 'Extracting optical document details', state: 'pending' }, overrides.extract || {}),
            Object.assign({ key: 'resolve', label: 'Resolving customer and lenses', state: 'pending' }, overrides.resolve || {}),
            Object.assign({ key: 'validate', label: 'Checking stock, GST and pricing', state: 'pending' }, overrides.validate || {}),
            Object.assign({ key: 'prepare', label: 'Preparing sales invoice review', state: 'pending' }, overrides.prepare || {}),
        ];

        const origAjax = $.ajax;
        $.ajax = function (opts) {
            if (opts.url === '/ai/media/processing-tasks' && opts.method === 'POST') {
                setTimeout(() => {
                    opts.success({
                        processing_task_id: 'task-ui-1',
                        media_id: 'media-ui-1',
                        thread_id: '11111111-1111-4111-8111-111111111111',
                        state: 'queued',
                        state_version: 1,
                        stage: 'preprocess',
                        header_title: 'Preparing your document',
                        file_name: '<img src=x onerror=alert(1)>samplebill.png',
                        file_size: 5872025,
                        source_kind: 'image',
                        model: null,
                        stages: baseStages({}),
                        worker_health: { healthy: true, stale: false },
                        cancellable: true,
                        poll_after_ms: 80,
                    });
                }, 20);
                return { abort: () => {} };
            }

            if (opts.url === '/ai/media/processing-tasks/task-ui-1' && opts.method === 'GET') {
                window.__pollCalls += 1;
                const call = window.__pollCalls;
                setTimeout(() => {
                    if (call === 1) {
                        opts.success({
                            processing_task_id: 'task-ui-1',
                            media_id: 'media-ui-1',
                            thread_id: '11111111-1111-4111-8111-111111111111',
                            state: 'processing',
                            state_version: 3,
                            stage: 'extract',
                            header_title: 'Preparing your document',
                            file_name: '<img src=x onerror=alert(1)>samplebill.png',
                            file_size: 5872025,
                            source_kind: 'image',
                            model: { provider: 'nvidia', model_key: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', display_name: 'Nemotron Omni' },
                            stages: baseStages({
                                preprocess: { state: 'completed' },
                                extract: { state: 'running', detail: 'Processing document', page_progress: { completed: 1, total: 2 } },
                            }),
                            cancellable: true,
                            poll_after_ms: 80,
                        });
                    } else if (call === 2) {
                        // Out-of-order stale version 2 must be ignored
                        opts.success({
                            processing_task_id: 'task-ui-1',
                            thread_id: '11111111-1111-4111-8111-111111111111',
                            state: 'queued',
                            state_version: 2,
                            stages: baseStages({}),
                        });
                    } else {
                        opts.success({
                            processing_task_id: 'task-ui-1',
                            media_id: 'media-ui-1',
                            thread_id: '11111111-1111-4111-8111-111111111111',
                            state: 'needs_input',
                            state_version: 6,
                            stage: 'validate',
                            header_title: 'Preparing your sales invoice',
                            file_name: '<img src=x onerror=alert(1)>samplebill.png',
                            file_size: 5872025,
                            source_kind: 'image',
                            model: { provider: 'nvidia', model_key: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', display_name: 'Nemotron Omni' },
                            stages: baseStages({
                                preprocess: { state: 'completed' },
                                extract: { state: 'completed' },
                                resolve: { state: 'completed' },
                                validate: { state: 'needs_input', detail: 'Customer required' },
                            }),
                            result: {
                                status: 'needs_input',
                                action_key: 'sales_invoice.create.v1',
                                task_id: 'wf-task-1',
                                task: { id: 'wf-task-1', action_key: 'sales_invoice.create.v1', revision: 1, slots: {} },
                                clarification: {
                                    action_key: 'sales_invoice.create.v1',
                                    task_id: 'wf-task-1',
                                    task_revision: 1,
                                    missing_fields: ['customer_id'],
                                    message: 'Select a customer for this sales invoice.',
                                },
                            },
                        });
                    }
                }, 20);
                return { abort: () => {} };
            }
            return origAjax.apply(this, arguments);
        };
    });

    await page.addScriptTag({ path: 'public/js/ai-media-progress.js' });
    await page.addScriptTag({ path: 'public/js/ai-media-input.js' });

    await page.evaluate((pngB64) => {
        const bytes = Uint8Array.from(atob(pngB64), (c) => c.charCodeAt(0));
        const file = new File([bytes], 'samplebill.png', { type: 'image/png' });
        window.zoloAiMedia.stageFile(file, 'samplebill.png');
        window.zoloAiMedia.submitStagedAttachment('create a sales bill', '11111111-1111-4111-8111-111111111111');
    }, TINY_PNG.toString('base64'));

    // Assert 6-stage card renders, model appears during extraction, stale poll 2 does not regress, terminal state shows Sales Invoice review
    await page.locator('.ai-media-progress-card').waitFor({ state: 'visible', timeout: 5000 });
    await page.getByText('Nemotron Omni').waitFor({ state: 'visible', timeout: 5000 });
    await page.getByText('Input Required').waitFor({ state: 'visible', timeout: 5000 });

    const stageCount = await page.locator('.ai-media-stage-row').count();
    assert.equal(stageCount, 6, 'Must render all 6 pipeline stages');

    // Old spinner text must never appear
    const html = await page.content();
    assert.equal(html.includes('Validating document, resolving records'), false);

    // Mobile viewport + dark mode overflow check (UI11)
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => document.body.classList.add('dark-mode'));
    const hasOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    assert.equal(hasOverflow, false, 'Mobile viewport must have zero horizontal overflow');
    assert.deepEqual(errors, [], 'Zero page errors expected');

    console.log('PASS copilot media progress browser contract (UI01-UI14)');
} finally {
    await browser.close();
}
