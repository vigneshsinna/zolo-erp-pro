/**
 * Real-browser Playwright verification for Phase 2.3 Multimodal AI Workspace (/ai).
 * Expects BASE, ADMIN_USER, ADMIN_PASSWORD, COMPANY_ID, and SEEDED_MEDIA_JSON
 * against a disposable test database server. Never sends unverified AI writes without approval.
 */
import assert from 'node:assert/strict';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const core = process.env.PLAYWRIGHT_CORE
    ? await import(pathToFileURL(path.resolve(process.env.PLAYWRIGHT_CORE)).href)
    : await import('playwright-core');
const { chromium } = core.default && core.default.chromium ? core.default : core;

const BASE = process.env.BASE || 'http://127.0.0.1:8099';
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'Browser!2026';
const MODE = process.env.MULTIMODAL_BROWSER_MODE || 'enabled'; // 'disabled' | 'enabled'
const SCREENSHOT_PATH = process.env.SCREENSHOT_PATH || '';
const SEEDED = process.env.SEEDED_MEDIA_JSON ? JSON.parse(process.env.SEEDED_MEDIA_JSON) : null;

const channel = process.env.BROWSER_CHANNEL || 'msedge';
const browser = await chromium.launch({ channel, headless: process.env.HEADED !== '1' });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

const errors = [];
page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
page.on('dialog', (d) => d.dismiss().catch(() => {}));

// Minimal valid 8x8 PNG binary
const TINY_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQImWP8//8/AzbAhFV00EoAAFbUAw00q2Z5AAAAAElFTkSuQmCC',
    'base64'
);

async function loginAndSelectCompany() {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#login-username', ADMIN_USER);
    await page.fill('#login-password', ADMIN_PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('#login-form [type=submit], #login-form button'),
    ]);

    if (page.url().includes('/select-company')) {
        await Promise.all([
            page.waitForURL((u) => !u.pathname.includes('/select-company')),
            page.locator('form.cp-card .cp-open').first().click(),
        ]);
    }
}

try {
    await loginAndSelectCompany();

    const aiRes = await page.goto(BASE + '/ai', { waitUntil: 'networkidle' });
    assert.equal(aiRes.status(), 200, '/ai workspace must render 200');

    if (MODE === 'disabled') {
        assert.equal(await page.locator('#btn-ai-attach-media').count(), 0, 'Attach button must be hidden when disabled');
        assert.equal(await page.locator('#btn-ai-record-audio').count(), 0, 'Mic button must be hidden when disabled');
        const cfgEnabled = await page.evaluate(() => Boolean(window.zoloAiMultimodalConfig && window.zoloAiMultimodalConfig.enabled));
        assert.equal(cfgEnabled, false, 'Multimodal client config must be disabled');
        assert.deepEqual(errors, [], 'Zero page errors expected in disabled mode');
        if (SCREENSHOT_PATH) {
            await page.screenshot({ path: SCREENSHOT_PATH, fullPage: true });
        }
        console.log('PASS multimodal disabled workspace check');
    } else {
        // 1. Enabled UI controls + voice recorder graceful unavailable handling
        await page.locator('#btn-ai-attach-media').waitFor({ state: 'visible' });
        await page.locator('#btn-ai-record-audio').waitFor({ state: 'visible' });

        await page.locator('#btn-ai-record-audio').click();
        await page.locator('#ai-voice-recorder-tray').waitFor({ state: 'visible' });
        await page.waitForFunction(
            () => (document.getElementById('ai-voice-recorder-status')?.textContent || '').trim().length > 5
        );
        const voiceStatus = await page.locator('#ai-voice-recorder-status').textContent();
        assert.ok((voiceStatus || '').trim().length > 5, 'Voice recorder tray shows status text');
        await page.locator('#btn-ai-voice-discard').click();
        await page.locator('#ai-voice-recorder-tray').waitFor({ state: 'hidden' });

        // 2. Real binary upload (extract_now=0) + private preview security headers + discard
        const uploadCheck = await page.evaluate(async (pngB64) => {
            const bytes = Uint8Array.from(atob(pngB64), (c) => c.charCodeAt(0));
            const blob = new Blob([bytes], { type: 'image/png' });
            const fd = new FormData();
            fd.append('file', blob, 'browser_check.png');
            fd.append('extract_now', '0');

            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const up = await fetch(window.zoloAiWorkspace.aiBaseUrl + '/media', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-AI-Scope': window.zoloAiWorkspace.scopeHeader,
                },
                body: fd,
            });
            const upText = await up.text();
            let upJson = null;
            try { upJson = JSON.parse(upText); } catch {}
            if (!up.ok || !upJson || !upJson.media) {
                return { uploadStatus: up.status, uploadBody: upJson || upText.slice(0, 400) };
            }
            const prev = await fetch(upJson.media.preview_url, { credentials: 'same-origin' });
            const cacheHeader = prev.headers.get('cache-control') || '';
            const nosniffHeader = prev.headers.get('x-content-type-options') || '';

            const del = await fetch(window.zoloAiWorkspace.aiBaseUrl + '/media/' + encodeURIComponent(upJson.media.id), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-AI-Scope': window.zoloAiWorkspace.scopeHeader,
                },
            });

            return {
                uploadStatus: up.status,
                previewStatus: prev.status,
                previewType: prev.headers.get('content-type') || '',
                cacheHeader,
                nosniffHeader,
                deleteStatus: del.status,
                previewUrl: upJson.media.preview_url,
            };
        }, TINY_PNG.toString('base64'));

        assert.equal(uploadCheck.uploadStatus, 201, 'Real PNG upload must succeed with 201: ' + JSON.stringify(uploadCheck));
        assert.equal(uploadCheck.previewStatus, 200, 'Private preview must return 200 for same-company owner');
        assert.match(uploadCheck.previewType, /image\/png/i);
        assert.match(uploadCheck.cacheHeader, /no-store/i);
        assert.match(uploadCheck.nosniffHeader, /nosniff/i);
        assert.equal(uploadCheck.deleteStatus, 200, 'Discard DELETE must succeed with 200');
        assert.ok(!uploadCheck.previewUrl.includes('/storage/'), 'Preview URL must never leak direct storage path');

        // 3. Review card: Edit -> 409 Conflict -> Reload -> Confirm -> Handoff -> Proposal Card
        assert.ok(SEEDED && SEEDED.media && SEEDED.extraction, 'SEEDED_MEDIA_JSON required for UI review card verification');
        await page.evaluate(({ media, extraction }) => {
            window.zoloAiMedia.renderExtractionCard(media, extraction);
        }, SEEDED);

        const card = page.locator(`.ai-media-review-card[data-media-id="${SEEDED.media.id}"]`);
        await card.waitFor({ state: 'visible' });
        assert.equal(await card.locator('.ai-btn-media-handoff').isDisabled(), true, 'Handoff must stay disabled before confirmation');

        // Edit supplier & save (Rev 1 -> Rev 2)
        const supplierInput = card.locator('.ai-ext-scalar[data-field="supplier_name"]');
        await supplierInput.fill('Browser Opticals Co');
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/extraction') && r.request().method() === 'PATCH' && r.status() === 200),
            card.locator('.ai-btn-media-save').click(),
        ]);
        await card.locator('.ai-rev-badge', { hasText: 'Rev 2' }).waitFor({ state: 'visible' });

        // Simulate concurrent update on server (Rev 2 -> Rev 3) outside this card, then click Save -> triggers 409 conflict & reloads Rev 3
        await page.evaluate(async (mediaId) => {
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            await fetch(window.zoloAiWorkspace.aiBaseUrl + '/media/' + encodeURIComponent(mediaId) + '/extraction', {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-AI-Scope': window.zoloAiWorkspace.scopeHeader,
                },
                body: JSON.stringify({
                    expected_revision: 2,
                    invoice_number: 'INV-CONCURRENT-3',
                }),
            });
        }, SEEDED.media.id);

        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/extraction') && r.request().method() === 'PATCH' && r.status() === 409),
            page.waitForResponse((r) => r.url().includes('/extraction') && r.request().method() === 'GET' && r.status() === 200),
            card.locator('.ai-btn-media-save').click(),
        ]);
        await card.locator('.ai-rev-badge', { hasText: 'Rev 3' }).waitFor({ state: 'visible' });
        await card.locator('.ai-media-action-feedback', { hasText: /conflict|Reloading/i }).waitFor({ state: 'visible' });
        assert.equal(await card.locator('.ai-ext-scalar[data-field="invoice_number"]').inputValue(), 'INV-CONCURRENT-3');

        // Confirm extraction -> enables Stage ERP Proposal + attaches media chip
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/confirm') && r.request().method() === 'POST' && r.status() === 200),
            card.locator('.ai-btn-media-confirm').click(),
        ]);
        await card.locator('.ai-confirm-badge', { hasText: /User Confirmed/i }).waitFor({ state: 'visible' });
        assert.equal(await card.locator('.ai-btn-media-handoff').isDisabled(), false, 'Handoff must enable after confirmation');
        await page.locator('#ai-media-attached-bar .ai-media-chip').first().waitFor({ state: 'visible' });

        // Handoff -> real Phase 2.1/2.2 creates Purchase Order Proposal card (awaiting human approval)
        await card.locator('.ai-media-companion-input').fill('Create purchase order for Main Warehouse');
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/handoff') && r.request().method() === 'POST' && r.status() === 200),
            card.locator('.ai-btn-media-handoff').click(),
        ]);
        const proposalCard = page.locator('.erp-fact-card', { hasText: 'Purchase Order Proposal' }).first();
        await proposalCard.waitFor({ state: 'visible' });
        assert.match(await proposalCard.textContent(), /Requires Approval/);

        assert.deepEqual(errors, [], 'Zero uncaught browser pageerror events expected');
        if (SCREENSHOT_PATH) {
            await page.screenshot({ path: SCREENSHOT_PATH, fullPage: true });
        }
        console.log('PASS multimodal enabled workspace check (voice tray, private upload/preview/delete, edit, 409 reload, confirm, proposal handoff)');
    }
} catch (err) {
    try {
        const diag = await page.evaluate(() => ({
            url: window.location.href,
            multimodalCfg: window.zoloAiMultimodalConfig || null,
            workspaceCfg: window.zoloAiWorkspace ? { enabled: window.zoloAiWorkspace.isAiEnabled } : null,
            attachCount: document.querySelectorAll('#btn-ai-attach-media').length,
        }));
        console.error('DIAG:', JSON.stringify(diag));
        if (SCREENSHOT_PATH) {
            await page.screenshot({ path: SCREENSHOT_PATH.replace(/\.png$/, '_fail.png'), fullPage: true });
        }
    } catch {}
    throw err;
} finally {
    await browser.close();
}

