const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const path = require('node:path');

test('actual POS search guard rejects stale party, warehouse, query and earlier request', () => {
    for (const view of ['edit', 'pos']) {
        const source = readFileSync(path.join(__dirname, '../../resources/views/backend/sale', view + '.blade.php'), 'utf8');
        const guard = source.match(/function currentProductSearch\(sequence, customer, warehouse, term\) \{[\s\S]*?\n        \}/)[0];
        const state = {warehouse_id: 2, productSearchSequence: 1, customer: '7', term: 'Alias'};
        state.$ = () => ({val: () => state.customer});
        state.$input = {val: () => state.term};
        runInNewContext(guard, state);
        const current = () => state.currentProductSearch(1, '7', '2', 'Alias');
        assert.equal(current(), true, view);
        state.customer = '8'; assert.equal(current(), false, view); state.customer = '7';
        state.warehouse_id = 3; assert.equal(current(), false, view); state.warehouse_id = 2;
        state.term = 'Different'; assert.equal(current(), false, view); state.term = 'Alias';
        state.productSearchSequence = 2; assert.equal(current(), false, view);
        const success = source.indexOf('success: function (data) {', source.indexOf('function searchProducts(search)'));
        const guardIndex = source.indexOf('if (!currentProductSearch(', success);
        assert.ok(guardIndex > success && guardIndex < source.indexOf('$results.empty();', success), view);
    }
});
