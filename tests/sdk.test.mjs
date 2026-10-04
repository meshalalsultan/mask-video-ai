import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import RunwayML, { TaskFailedError, TaskTimedOutError } from '@runwayml/sdk';
import { createService } from '../sdk/service.mjs';

const id = 'a'.repeat(32), provider = 'provider-123';
const successful = { id: provider, status: 'SUCCEEDED', output: ['https://example.org/test.mp4'], cost: { credits: 60 } };
const task = () => ({ id, owner: 'test-owner', status: 'SUBMITTING', prompt: 'سيارة', model: 'gen4.5', ratio: '720:1280', duration: 5 });
function handle(created, result = successful, error) {
  const promise = error && !created ? Promise.reject(error) : Promise.resolve(created);
  promise.waitForTaskOutput = async () => { if (error) throw error; return result; };
  return promise;
}
async function fixture(t, factory, downloader = async () => {}) {
  const storageDir = await mkdtemp(resolve(tmpdir(), 'mask-sdk-'));
  await mkdir(resolve(storageDir, 'tasks')); await mkdir(resolve(storageDir, 'videos'));
  const file = resolve(storageDir, 'tasks', `${id}.json`);
  await writeFile(file, JSON.stringify(task()));
  const server = createService({ storageDir, clientFactory: factory, downloader });
  await new Promise(resolvePromise => server.listen(0, '127.0.0.1', resolvePromise));
  const base = `http://127.0.0.1:${server.address().port}`;
  t.after(async () => { server.closeAllConnections(); await new Promise(r => server.close(r)); await rm(storageDir, { recursive: true, force: true }); });
  const post = (path = '/start', body = { id, api_key: 'TEST_ONLY', php_binary: 'php' }, headers = {}) => fetch(base + path, {
    method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(body),
  });
  const read = async () => JSON.parse(await readFile(file, 'utf8'));
  const until = async status => {
    for (let n = 0; n < 100; n++) { const saved = await read(); if (saved.status === status) return saved; await new Promise(r => setTimeout(r, 10)); }
    assert.fail(`Expected ${status}, got ${(await read()).status}`);
  };
  return { post, read, until, base, file, storageDir };
}

test('official SDK sends documented creation fields and waits for output', async () => {
  const calls = [];
  const client = new RunwayML({ apiKey: 'TEST_ONLY', maxRetries: 0, logLevel: 'off', fetch: async (url, init) => {
    calls.push({ url: String(url), init });
    return Response.json(init.method === 'POST' ? { id: provider } : successful);
  } });
  const request = client.textToVideo.create({ model: 'gen4.5', promptText: 'سيارة', duration: 5, ratio: '720:1280' });
  const completion = request.waitForTaskOutput();
  assert.equal((await request).id, provider);
  assert.equal((await completion).status, 'SUCCEEDED');
  const creation = calls.find(call => call.init.method === 'POST');
  assert.equal(creation.url, 'https://api.dev.runwayml.com/v1/text_to_video');
  assert.deepEqual(JSON.parse(creation.init.body), { model: 'gen4.5', promptText: 'سيارة', duration: 5, ratio: '720:1280' });
  assert.equal(new Headers(creation.init.headers).get('X-Runway-Version'), '2024-11-06');
  assert.equal(new Headers(creation.init.headers).get('Authorization'), 'Bearer TEST_ONLY');
  assert.equal(calls.filter(c => c.init.method === 'POST').length, 1);
});

test('official SDK disables POST retries and uses CANCELLED for task failure', async () => {
  let count = 0;
  const client = new RunwayML({ apiKey: 'TEST_ONLY', maxRetries: 0, logLevel: 'off', fetch: async (_url, init) => {
    count++;
    return init.method === 'POST' ? Response.json({ error: 'private' }, { status: 503 })
      : Response.json({ id: provider, status: 'CANCELLED' });
  } });
  await assert.rejects(client.textToVideo.create({ model: 'gen4.5', promptText: 'test', duration: 5, ratio: '720:1280' }));
  assert.equal(count, 1);
  await assert.rejects(client.tasks.retrieve(provider).waitForTaskOutput(), error => error instanceof TaskFailedError && error.taskDetails.status === 'CANCELLED');
});

test('service saves output, preserves ownership and never repeats creation', async t => {
  let creates = 0, saves = 0;
  const f = await fixture(t, () => ({ textToVideo: { create: () => { creates++; return handle({ id: provider }); } } }), async (_url, destination) => {
    saves++; await writeFile(destination, Buffer.from('test fixture'));
  });
  const responses = await Promise.all([f.post(), f.post()]);
  assert.ok(responses.every(r => [202, 409].includes(r.status)));
  const saved = await f.until('READY');
  assert.equal(saved.owner, 'test-owner'); assert.equal(saved.api_cost_credits, 60);
  assert.equal(saved.provider_task_id, provider); assert.equal(creates, 1); assert.equal(saves, 1);
  assert.equal((await f.post()).status, 409);
  assert.ok(!JSON.stringify(saved).includes('TEST_ONLY') && !JSON.stringify(saved).includes('https://'));
});

test('failed submission is classified safely without automatic retries', async t => {
  for (const [error, expected] of [[Object.assign(new Error('secret'), { status: 401 }), 'FAILED'], [new Error('secret'), 'SUBMISSION_UNKNOWN']]) {
    let creates = 0;
    const f = await fixture(t, () => ({ textToVideo: { create: () => { creates++; return handle(null, null, error); } } }));
    assert.equal((await f.post()).status, 202);
    const saved = await f.until(expected);
    assert.ok(!saved.message.includes('secret')); assert.equal(creates, 1);
    assert.equal((await f.post()).status, 409);
  }
});

test('wait timeout resumes the same provider task using retrieve only', async t => {
  let creates = 0, retrieves = 0;
  const f = await fixture(t, () => ({
    textToVideo: { create: () => { creates++; return handle({ id: provider }, null, new TaskTimedOutError({ id: provider, status: 'RUNNING' })); } },
    tasks: { retrieve: value => { assert.equal(value, provider); retrieves++; return handle({ id: provider }); } },
  }));
  await f.post(); await f.until('NEEDS_REVIEW');
  const responses = await Promise.all([f.post('/resume'), f.post('/resume')]);
  assert.ok(responses.every(r => [202, 409].includes(r.status)));
  await f.until('READY'); assert.equal(creates, 1); assert.equal(retrieves, 1);
});

test('save failure can refresh output and download without generating again', async t => {
  let creates = 0, retrieves = 0, saves = 0;
  const f = await fixture(t, () => ({
    textToVideo: { create: () => { creates++; return handle({ id: provider }); } },
    tasks: { retrieve: () => { retrieves++; return handle({ id: provider }); } },
  }), async () => { if (++saves === 1) throw new Error('disk error'); });
  await f.post(); await f.until('SAVE_FAILED');
  await f.post('/resume'); await f.until('READY');
  assert.equal(creates, 1); assert.equal(retrieves, 1); assert.equal(saves, 2);
});

test('SDK task cancellation and sanitized failure code are persisted', async t => {
  for (const status of ['CANCELLED', 'FAILED']) {
    const error = new TaskFailedError({ id: provider, status, failure: 'private raw message', failureCode: 'SAFETY.INPUT' });
    const f = await fixture(t, () => ({ textToVideo: { create: () => handle({ id: provider }, null, error) } }));
    await f.post(); const saved = await f.until(status);
    assert.ok(!saved.message.includes('private raw'));
    assert.equal((await f.post('/resume')).status, 409);
  }
});

test('bridge rejects browser origins and invalid tasks; health makes no SDK calls', async t => {
  const f = await fixture(t, () => { assert.fail('No SDK calls expected'); });
  assert.equal((await fetch(f.base + '/health')).status, 200);
  assert.equal((await f.post('/start', undefined, { Origin: 'https://attacker.example' })).status, 403);
  assert.equal((await f.post('/start', { id: '../outside' })).status, 400);
  assert.equal((await f.post('/start', { id, api_key: 'x'.repeat(20_000), php_binary: 'php' })).status, 413);
  await writeFile(f.file, JSON.stringify({ ...task(), duration: 99 }));
  assert.equal((await f.post()).status, 400);
});

test('durable submission lock prevents POST after a worker restart', async t => {
  const f = await fixture(t, () => { assert.fail('Creation must not run again'); });
  // A crash can leave SUBMITTING without a provider ID. The lock survives the process.
  await writeFile(`${f.file}.sdk.lock`, '');
  assert.equal((await f.post()).status, 409);
  assert.equal((await f.read()).status, 'SUBMITTING');
});
