// Exercise the production card handlers with mocked ERP responses.
import assert from 'node:assert/strict';
import { browser, context } from '../support.mjs';

try {
    for (const { actionKey, editButton, revision } of [
        { actionKey: 'purchase_bill.create.v1', editButton: 'Edit Bill' },
        { actionKey: 'purchase_bill.create.v1', editButton: 'Edit Bill', revision: 3 },
        { actionKey: 'purchase_order.create.v1', editButton: 'Edit PO', revision: 3 },
    ]) {
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<div id="chat-messages"></div>');
        await page.addScriptTag({ path: 'public/vendor/jquery/jquery.min.js' });
        await page.evaluate(() => {
            window.zoloAiMultimodalConfig = { enabled: true };
            window.requests = [];
            $.ajax = ({ url, data, success }) => {
                requests.push({ url, body: JSON.parse(data) });
                if (url.endsWith('/inputs')) {
                    success({ task: { id: 'fixture-task', revision: 4 }, evaluation: { is_ready: true } });
                } else if (url.endsWith('/prepare-proposal')) {
                    success({
                        task: { id: 'fixture-task', revision: 4 },
                        proposal: { id: 'revised-proposal', version: 2, preview: {} },
                    });
                } else {
                    success({ status: 'completed', execution: { receipt: { resource_id: 123 } } });
                }
            };
        });
        await page.addScriptTag({ path: 'public/js/ai-media-input.js' });
        await page.evaluate(({ actionKey, revision }) => {
            window.zoloAiMedia.renderUnifiedWorkflowCard({
                status: 'proposal_created',
                action_key: actionKey,
                task: { id: 'fixture-task', revision },
                proposal: {
                    id: 'original-proposal',
                    version: 1,
                    preview: {
                        supplier: { name: 'Original Supplier' },
                        warehouse: { id: 10, name: 'Original Warehouse' },
                        reference_no: 'Fixture Reference',
                        items: [{ product_id: 11, product_name: 'Original Product', qty: 2, net_unit_cost: 30 }],
                    },
                },
            });
        }, { actionKey, revision });

        await page.getByRole('button', { name: editButton }).click();
        await page.locator('.ai-edit-warehouse').fill('Replacement Warehouse');
        await page.locator('.ai-edit-item-qty').fill('3');
        await page.getByRole('button', { name: 'Update & Revalidate' }).click();

        const requests = await page.evaluate(() => window.requests);
        assert.equal(requests.length, 2);
        assert.match(requests[0].url, /\/inputs$/);
        assert.deepEqual(requests[0].body, {
            expected_revision: revision ?? 1,
            corrections: [
                { field: 'warehouse_id', value: 'Replacement Warehouse', confirmation: 'user_edited' },
                { field: 'items[0].qty', value: '3', confirmation: 'user_edited' },
            ],
        });
        assert.match(requests[1].url, /\/prepare-proposal$/);

        await page.locator('.ai-btn-approve-bill').click();
        await page.getByText('Voucher Booked: 123', { exact: true }).waitFor({ timeout: 3000 });
        await page.getByRole('button', { name: 'View PDF in Chat' }).waitFor({ timeout: 3000 });
        assert.deepEqual(errors, []);
        await page.close();
    }
    console.log('PASS media workflow edit, revalidation, and booking confirmation');
} finally {
    await browser.close();
}
