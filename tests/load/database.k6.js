import http from 'k6/http';
import { check } from 'k6';

// One iteration = one real database-backed HTTP request, without redirects.
if (__ENV.ALLOW_LOAD_TEST !== 'staging') throw new Error('Use ALLOW_LOAD_TEST=staging em homologacao isolada.');
if (!__ENV.BASE_URL || !__ENV.CATALOG_SLUG) throw new Error('Informe BASE_URL e CATALOG_SLUG de uma empresa sintetica.');
const rate = Number(__ENV.RATE || 600);
if (!Number.isInteger(rate) || rate < 1) throw new Error('RATE deve ser inteiro positivo.');
export const options = {
  scenarios: {
    catalog: {
      executor: 'constant-arrival-rate', rate, timeUnit: '1m',
      duration: __ENV.DURATION || '10m', preAllocatedVUs: 30, maxVUs: 100,
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500'],
    checks: ['rate>0.99'],
    dropped_iterations: ['count==0'],
  },
};
export default function () {
  const response = http.get(`${__ENV.BASE_URL.replace(/\/$/, '')}/catalog/${encodeURIComponent(__ENV.CATALOG_SLUG)}`, {
    redirects: 0, timeout: '10s', tags: { name: 'catalog' },
  });
  check(response, { 'catalogo disponivel': (r) => r.status === 200 });
}
