/* Shared helpers for the real-browser specs (Playwright + the NORMAL /sales and /purchases pages). See README.md. */
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const core = process.env.PLAYWRIGHT_CORE
    ? await import(pathToFileURL(path.resolve(process.env.PLAYWRIGHT_CORE)).href)
    : await import('playwright-core');
const { chromium } = core.default && core.default.chromium ? core.default : core;

export const BASE = process.env.BASE || 'http://127.0.0.1:8091';
const USER = process.env.ADMIN_USER || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'Browser!2026';
export const SLOW = Number(process.env.SLOW_TIMEOUT || 60000);

let passed = 0, failed = 0;
export const check = (name, ok, detail = '') => {
    if (ok) { passed++; console.log('PASS ' + name); } else { failed++; console.log('FAIL ' + name + (detail ? ' :: ' + detail : '')); }
};

export const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'chrome', headless: !process.env.HEADED });
export const context = await browser.newContext({ viewport: { width: 1600, height: 900 } });
export const errors = [];
export const watch = page => {
    page.on('pageerror', error => errors.push('pageerror: ' + error.message));
    page.on('dialog', dialog => dialog.dismiss().catch(() => {}));
};

export async function login(page) {
    await page.goto(BASE + '/login');
    await page.fill('#login-username', USER);
    await page.fill('#login-password', PASSWORD);
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#login-form [type=submit], #login-form button')]);
}
export const ready = page => page.waitForFunction(() => window.zoloDocumentWorkspace && window.zoloDocumentWorkspace.ready, null, { timeout: SLOW });
export async function openKind(page, kind, query = '') {
    await page.goto(BASE + (kind === 'sale' ? '/sales' : '/purchases') + query, { waitUntil: 'domcontentloaded' });
    await ready(page);
}
export const api = (page, kind, method, urlPath, body) => page.evaluate(async ({ kind, method, urlPath, body }) => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const response = await fetch('/commercial/' + kind + urlPath, { method, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: body === undefined ? undefined : JSON.stringify(body) });
    let json = null; try { json = await response.json(); } catch (e) { /* empty */ }
    return { status: response.status, json };
}, { kind, method, urlPath, body });
export async function resetDrafts(page, kind) {
    const list = await api(page, kind, 'GET', '/drafts');
    for (const draft of (list.json && list.json.data) || []) await api(page, kind, 'DELETE', '/drafts/' + draft.id);
}
export const partyId = kind => kind === 'sale' ? '#customer_id' : '#supplier_id';
export const pickParty = (page, kind, value) => page.evaluate(({ selector, value }) => {
    window.jQuery(selector).val(value).trigger('change'); window.jQuery('.selectpicker').selectpicker('refresh');
}, { selector: partyId(kind), value });
export async function addRow(page, name, qty, rate) {
    await page.click('#btn-add-item-row');
    const row = page.locator('#order-table-body tr.order-item-row').last();
    await row.locator('.row-item-name').fill(name);
    await row.locator('.row-qty').fill(String(qty));
    await row.locator('.row-rate').fill(String(rate));
    await row.locator('.row-rate').dispatchEvent('input');
    return row;
}
export async function setTracking(page, batch, serials) {
    await page.locator('#order-table-body tr.order-item-row').last().locator('.btn-edit-row').click();
    await page.waitForSelector('#row-detail-modal.show', { timeout: SLOW });
    await page.fill('#modal-row-batch', batch);
    await page.fill('#modal-row-imei', serials);
    await page.click('#btn-save-row-detail');
    await page.waitForSelector('#row-detail-modal', { state: 'hidden', timeout: SLOW });
}
export const setPayment = (page, amount) => page.evaluate(amount => {
    const $ = window.jQuery;
    $('#drawer-paid-amount').val(amount); $('#drawer-paying-method').val('Cash'); $('#btn-save-drawer-details').trigger('click');
    $('#drawer-paying-method').selectpicker && $('#drawer-paying-method').selectpicker('refresh');
}, amount);
export const draftCount = async (page, kind) => ((await api(page, kind, 'GET', '/drafts')).json || {}).open;
export const waitDrafts = (page, kind, count) => page.waitForFunction(async ({ kind, count }) => {
    const response = await fetch('/commercial/' + kind + '/drafts', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return (await response.json()).open === count;
}, { kind, count }, { timeout: SLOW });
export const snap = page => page.evaluate(() => window.zoloDocumentWorkspace.snapshot());
export const settled = page => page.waitForFunction(() => !window.zoloDocumentWorkspace.isDirty() && window.zoloDocumentWorkspace.tabs.every(tab => !tab.conflict), null, { timeout: SLOW });
export const tabsOf = page => page.evaluate(() => window.zoloDocumentWorkspace.tabs.map(tab => ({ id: tab.id, draftId: tab.draftId, title: tab.title, party: tab.partyName })));
export const dialogButtons = page => page.$$eval('dialog[open] button', buttons => buttons.map(button => button.textContent.trim()));


export const counts = () => ({ passed, failed });
export const fail = () => { failed++; };
export async function finish() {
    await browser.close();
    console.log(`
${passed} passed, ${failed} failed`);
    process.exitCode = failed ? 1 : 0;
}
