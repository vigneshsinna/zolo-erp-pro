import http from 'k6/http';
import { check, sleep } from 'k6';

/**
 * Read-only opt-in load test. Run ONLY against an authorised dedicated test company:
 * k6 run -e API_BASE=https://staging.example -e SESSION_COOKIE=<test_cookie>
 * -e X_AI_SCOPE=<company/branch/fy> -e VUS=5 tests/k6/ai_business_workflow_concurrency.js
 * Repeat VUS=10 and VUS=20. Do not use a production tenant or live invoices.
 */
const base = __ENV.API_BASE;
const cookie = __ENV.SESSION_COOKIE;
const scope = __ENV.X_AI_SCOPE;
if (!base || !cookie || !scope) {
    throw new Error('Missing API_BASE, SESSION_COOKIE or X_AI_SCOPE. Never guess a tenant scope.');
}
export const options = {
    vus: Number(__ENV.VUS || 5),
    duration: __ENV.DURATION || '30s',
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1500'],
    },
};
export default function () {
    const response = http.get(base.replace(/\/$/, '') + '/api/v1/ai/business-workflows', {
        headers: {Cookie: cookie, 'X-AI-Scope': scope, Accept: 'application/json'},
        redirects: 0,
        tags: {workflow: 'read_only_plan_list'},
    });
    check(response, {'scoped read succeeds': res => res.status === 200,
        'response contains no invoices': res => !/gstin|bank_account|invoice_image/i.test(res.body || '')});
    sleep(0.5);
}
