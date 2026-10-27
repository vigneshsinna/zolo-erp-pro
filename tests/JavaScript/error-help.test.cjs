const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');

const plain = value => JSON.parse(JSON.stringify(value)); // arrays from the vm context have another prototype
const code = readFileSync(require('node:path').join(__dirname, '../../public/js/error-help.js'), 'utf8');
function load() {
    const alerts = [];
    const window = { alert(message) { alerts.push(message); } };
    runInNewContext(code, { window, document: { currentScript: { dataset: { base: '/erp' } }, readyState: 'loading', addEventListener() {} } });
    return { help: window.zoloErrorHelp, alert: window.alert, alerts };
}

test('a specific message names the record and the steps to clear it', () => {
    const { help } = load();
    const found = help.lookup("Reverse the job worker's service bill PUR-00005 first (Purchase Bills).", true);
    assert.deepEqual(plain(help.steps(found).slice(0, 2)), ['Open Buying › Purchase Bills and find PUR-00005.', 'Click Reverse on PUR-00005, give a reason, and post it.']);
    assert.deepEqual(plain(found.entry.go), ['Open Purchase Bills', '/purchases']);
});

test('field and status messages get only specific help, so successes stay quiet', () => {
    const { help } = load();
    assert.equal(help.lookup('This field is required.', true), null);
    assert.equal(help.lookup('Order deleted.', true), null);
    assert.ok(help.lookup('Order deleted.', false), 'an error box always gets at least its category or the fallback');
});

test('every error box message gets help, ending with the general fallback', () => {
    const { help } = load();
    const found = help.lookup('Something nobody planned for went wrong.', false);
    assert.match(help.steps(found)[0], /correct the field it names/);
});

test('alert() boxes carry the numbered steps', () => {
    const { alert, alerts } = load();
    alert('Select a company-owned record.');
    assert.match(alerts[0], /How to fix:\n1\. Check every required list/);
    alert('Saved.');
    assert.equal(alerts[1], 'Saved.');
});
