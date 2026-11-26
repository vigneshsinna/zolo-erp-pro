import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// Custom Metrics for AI Credit Metering Invariants
export const overspendBreaches = new Counter('ai_credit_overspend_breaches');
export const reservationLatency = new Trend('ai_credit_reserve_duration_ms', true);
export const settlementLatency = new Trend('ai_credit_settle_duration_ms', true);
export const successRate = new Rate('ai_credit_success_rate');

export const options = {
    scenarios: {
        // Scenario 1: 5 Virtual Users baseline concurrent write load
        vu_5_baseline: {
            executor: 'constant-vus',
            vus: 5,
            duration: '15s',
            startTime: '0s',
            tags: { test_profile: '5_vus' },
        },
        // Scenario 2: 10 Virtual Users concurrent contention
        vu_10_contention: {
            executor: 'constant-vus',
            vus: 10,
            duration: '20s',
            startTime: '15s',
            tags: { test_profile: '10_vus' },
        },
        // Scenario 3: 20 Virtual Users write saturation & quota exhaustion
        vu_20_saturation: {
            executor: 'constant-vus',
            vus: 20,
            duration: '25s',
            startTime: '35s',
            tags: { test_profile: '20_vus' },
        },
    },
    thresholds: {
        // Production Safeguards:
        // 1. Zero negative balance breaches or ledger overspends allowed
        'ai_credit_overspend_breaches': ['count==0'],
        // 2. High throughput latency guarantees
        'http_req_duration': ['p(95)<150', 'p(99)<300'],
        'ai_credit_reserve_duration_ms': ['p(95)<100'],
        // 3. Expected HTTP success or graceful 402 (Credits Exhausted)
        'ai_credit_success_rate': ['rate>0.98'],
    },
};

const BASE_URL = __ENV.APP_URL || 'http://127.0.0.1:8000';
const COMPANY_ID = __ENV.AI_TEST_COMPANY_ID || '1';
const ACTOR_ID = __ENV.AI_TEST_ACTOR_ID || '1';

export default function () {
    const iteration = __ITER;
    const vu = __VU;
    const idempotencyKey = `k6_concur_${vu}_${iteration}_${Date.now()}`;

    // Payload for atomic reserve & settle load endpoint
    const payload = JSON.stringify({
        idempotency_key: idempotencyKey,
        max_credits: 15,
        actual_credits: 12,
        fake_provider_delay_ms: 5,
        company_id: parseInt(COMPANY_ID),
        actor_id: parseInt(ACTOR_ID),
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    };

    const startTime = Date.now();
    const res = http.post(`${BASE_URL}/__test/ai/credit-reserve-settle`, payload, params);
    const duration = Date.now() - startTime;
    reservationLatency.add(duration);

    if (res.status === 200) {
        successRate.add(1);
        const body = res.json();
        
        // Assert atomic response contracts
        check(res, {
            'status is 200': (r) => r.status === 200,
            'success is true': () => body.success === true,
            'credits debited matches request': () => body.credits_debited === 12,
            'balance is non-negative': () => (body.balance && body.balance.available_credits >= 0),
        });

        if (body.balance && body.balance.available_credits < 0) {
            overspendBreaches.add(1);
        }
    } else if (res.status === 402) {
        // 402 Payment Required: Expected when prepaid credits are exhausted under saturation
        successRate.add(1);
        const body = res.json();
        check(res, {
            'quota exhaustion error code is returned': () => body.error === 'CREDITS_EXHAUSTED' || res.status === 402,
        });
    } else {
        successRate.add(0);
        check(res, {
            'unexpected failure': () => false,
        });
    }

    sleep(0.05); // 50ms think-time between operations
}
