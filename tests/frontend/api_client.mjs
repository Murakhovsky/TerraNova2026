import assert from 'node:assert/strict';
import { ApiError, requestJson } from '../../resources/frontend/api/client.js';

const originalFetch = globalThis.fetch;

try {
  globalThis.fetch = async (_url, options) => {
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.headers.Accept, 'application/json');
    return new Response(JSON.stringify({ ok: true, data: { id: 7 } }), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    });
  };
  assert.deepEqual(await requestJson('/api/v1/example'), { ok: true, data: { id: 7 } });

  globalThis.fetch = async () => new Response(JSON.stringify({
    ok: false,
    error: 'validation_failed',
    message: 'Invalid input.',
    errors: { title: ['Required.'] },
  }), { status: 422, headers: { 'content-type': 'application/json' } });

  await assert.rejects(
    requestJson('/api/v1/example'),
    (error) => error instanceof ApiError
      && error.status === 422
      && error.code === 'validation_failed'
      && error.details.title[0] === 'Required.',
  );

  console.log('Frontend API client normalization passed.');
} finally {
  globalThis.fetch = originalFetch;
}
