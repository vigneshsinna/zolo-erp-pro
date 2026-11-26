// performance/ai_credit_load.js
// zoloERP AI Credit Metering & Wallet Concurrency Load Test Suite
// Executes 5, 10, and 20 VU scenarios across Balance, Packs, Estimate, and atomic Reserve/Settle write APIs (P0-03).

import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    five: {
      executor: 'constant-vus',
      vus: 5,
      duration: '2m',
      startTime: '0s',
    },
    ten: {
      executor: 'constant-vus',
      vus: 10,
      duration: '5m',
      startTime: '3m',
    },
    twenty: {
      executor: 'constant-vus',
      vus: 20,
      duration: '5m',
      startTime: '9m',
    },
  },
  thresholds: {
    'http_req_duration{scenario:five}': ['p(95)<250'],
    'http_req_duration{scenario:ten}': ['p(95)<300'],
    'http_req_duration{scenario:twenty}': ['p(95)<500'],
    'http_req_failed': ['rate<0.01'],
  },
};

function generateUuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

export default function () {
  const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000';
  const token = __ENV.TEST_TOKEN || 'testing-bearer-token';
  const headers = {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  // 1. Authorized balance endpoint
  const balanceRes = http.get(`${baseUrl}/api/v1/ai/credits/balance`, { headers });
  check(balanceRes, {
    'balance status is 200': (r) => r.status === 200,
    'balance has available_credits': (r) => r.json('data.available_credits') !== undefined,
  });

  // 2. Pack catalog endpoint
  const packsRes = http.get(`${baseUrl}/api/v1/ai/credits/packs`, { headers });
  check(packsRes, {
    'packs status is 200': (r) => r.status === 200,
    'packs has active array': (r) => Array.isArray(r.json('packs')),
  });

  // 3. Advisory credit estimate calculation
  const estimateRes = http.get(
    `${baseUrl}/api/v1/ai/credits/estimate?provider=nvidia&model=nvidia/nemotron-3.5-lightning-30b-a3b&estimated_input_bytes=2048&max_output_tokens=1536`,
    { headers }
  );
  check(estimateRes, {
    'estimate status is 200': (r) => r.status === 200,
    'estimate returns positive credits': (r) => r.json('estimated_credits') > 0,
  });

  // 4. P0-03: Write-capable atomic reserve and settle test endpoint
  const idempKey = 'load_' + generateUuid();
  const writePayload = JSON.stringify({
    idempotency_key: idempKey,
    max_credits: 10,
    actual_credits: 8,
    fake_provider_delay_ms: 10,
  });

  const writeRes = http.post(`${baseUrl}/__test/ai/credit-reserve-settle`, writePayload, { headers });
  check(writeRes, {
    'reserve-settle accepted or handled': (r) => [200, 201, 402, 409].includes(r.status),
    'settlement status valid if 200': (r) => r.status !== 200 || r.json('success') === true,
  });

  sleep(0.5);
}
