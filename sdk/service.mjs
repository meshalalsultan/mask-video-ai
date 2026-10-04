import http from 'node:http';
import { readFile, writeFile, rename, open, mkdir } from 'node:fs/promises';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import RunwayML, { TaskFailedError } from '@runwayml/sdk';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const validId = id => typeof id === 'string' && /^[a-f0-9]{32}$/.test(id);
const validProviderId = id => typeof id === 'string' && /^[a-zA-Z0-9-]{1,100}$/.test(id);

// Use the existing PHP downloader: public HTTPS only, pinned DNS, no redirects or API headers.
function saveVideo(url, destination, phpBinary) {
  return new Promise((resolvePromise, reject) => {
    const child = spawn(phpBinary, [resolve(root, 'tools/save-video.php')], { stdio: ['pipe', 'ignore', 'ignore'], windowsHide: true });
    const timer = setTimeout(() => { child.kill(); reject(new Error('Download timed out')); }, 130_000);
    child.on('error', () => { clearTimeout(timer); reject(new Error('PHP downloader unavailable')); });
    child.on('exit', code => { clearTimeout(timer); code === 0 ? resolvePromise() : reject(new Error('Download failed')); });
    child.stdin.on('error', () => {});
    child.stdin.end(JSON.stringify({ url, destination }));
  });
}

export function createService({ storageDir = resolve(root, 'storage'),
  clientFactory = apiKey => new RunwayML({ apiKey, maxRetries: 0, timeout: 45_000, logLevel: 'off' }),
  downloader = saveVideo, waitTimeout = 600_000 } = {}) {
  const jobs = new Map(), reservations = new Set();
  const taskPath = id => resolve(storageDir, 'tasks', `${id}.json`);
  const read = async id => JSON.parse(await readFile(taskPath(id), 'utf8'));
  async function update(id, fields) {
    const task = { ...await read(id), ...fields };
    const path = taskPath(id), temporary = `${path}.${randomBytes(6).toString('hex')}.tmp`;
    await writeFile(temporary, JSON.stringify(task), { mode: 0o600 });
    await rename(temporary, path);
    return task;
  }
  async function run(task, body, resume) {
    let providerId = task.provider_task_id;
    try {
      const client = clientFactory(body.api_key);
      const request = resume ? client.tasks.retrieve(providerId) : client.textToVideo.create({
        model: task.model, promptText: task.prompt, duration: task.duration, ratio: task.ratio,
      });
      // Attach the official waiter before awaiting creation. Only one paid POST, with retries disabled.
      const completion = request.waitForTaskOutput({ timeout: waitTimeout })
        .then(result => ({ result }), error => ({ error }));
      const created = await request;
      if (!validProviderId(created.id)) throw new Error('Missing provider ID');
      providerId = created.id;
      await update(task.id, { provider_task_id: providerId, status: 'PENDING', message: null });
      const settled = await completion;
      if (settled.error) throw settled.error;
      const result = settled.result;
      const cost = Number.isFinite(result.cost?.credits) ? result.cost.credits : null;
      await update(task.id, { status: 'SAVING', api_cost_credits: cost });
      try {
        if (typeof result.output?.[0] !== 'string') throw new Error('Missing video output');
        await downloader(result.output[0], resolve(storageDir, 'videos', `${task.id}.mp4`), body.php_binary);
      } catch {
        await update(task.id, { status: 'SAVE_FAILED', message: 'اكتمل التوليد لكن تعذر حفظ MP4. شغّل الخدمة ثم تابع نفس الطلب؛ لن يُعاد التوليد.' });
        return;
      }
      await update(task.id, { status: 'READY', message: null, completed_at: new Date().toISOString() });
    } catch (error) {
      let status, message;
      if (error instanceof TaskFailedError) {
        status = error.taskDetails.status === 'CANCELLED' ? 'CANCELLED' : 'FAILED';
        const code = error.taskDetails.failureCode;
        message = status === 'CANCELLED' ? 'أُلغي الطلب لدى المزود.' : 'لم يكتمل التوليد. راجع لوحة Runway لمعرفة السبب.';
        if (typeof code === 'string' && /^[A-Z0-9_.-]{1,100}$/.test(code)) message += ` (${code})`;
      } else if (providerId) {
        status = 'NEEDS_REVIEW'; message = 'توقفت متابعة التوليد. تابع نفس الطلب لاسترجاع نتيجته دون توليد جديد.';
      } else if (Number.isInteger(error.status) && error.status >= 400 && error.status < 500 && error.status !== 408) {
        status = 'FAILED'; message = [401, 403].includes(error.status) ? 'تحقق من مفتاح Runway وصلاحيات المشروع.'
          : error.status === 429 ? 'وصل الحساب إلى حد الطلبات. انتظر قليلًا.'
          : error.status === 402 ? 'رصيد Runway غير كافٍ.' : 'رفض المزود إعدادات الطلب أو محتواه. راجع لوحة Runway.';
      } else {
        status = 'SUBMISSION_UNKNOWN'; message = 'تعذر تأكيد إرسال الطلب. راجع لوحة Runway قبل إنشاء طلب آخر.';
      }
      await update(task.id, { status, message, ...(providerId ? { provider_task_id: providerId } : {}) });
    }
  }
  const server = http.createServer(async (req, res) => {
    const reply = (status, body) => { res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(body)); };
    let reservedId;
    try {
      // This bridge accepts only server-side loopback calls, never browser origins.
      if (req.headers.origin || !/^127\.0\.0\.1:\d+$/.test(req.headers.host || '')) return reply(403, { error: 'Local bridge only' });
      const url = new URL(req.url, 'http://127.0.0.1');
      if (req.method === 'GET' && url.pathname === '/health') return reply(200, { ready: true });
      if (req.method === 'GET' && url.pathname === '/active') {
        const id = url.searchParams.get('id');
        return validId(id) ? reply(200, { active: jobs.has(id) }) : reply(400, { error: 'Invalid ID' });
      }
      if (req.method !== 'POST' || !['/start', '/resume'].includes(url.pathname)) return reply(404, { error: 'Not found' });
      if (!req.headers['content-type']?.startsWith('application/json')) return reply(415, { error: 'JSON required' });
      let raw = '';
      for await (const chunk of req) { raw += chunk; if (Buffer.byteLength(raw) > 16_384) return reply(413, { error: 'Request too large' }); }
      let body;
      try { body = JSON.parse(raw); } catch { return reply(400, { error: 'Invalid JSON' }); }
      if (!validId(body?.id) || typeof body.api_key !== 'string' || !body.api_key.trim()
        || typeof body.php_binary !== 'string' || !body.php_binary) return reply(400, { error: 'Invalid request' });
      if (jobs.has(body.id) || reservations.has(body.id)) return reply(202, { accepted: true });
      reservedId = body.id; reservations.add(body.id);
      const task = await read(body.id);
      if (task.id !== body.id || task.model !== 'gen4.5' || typeof task.prompt !== 'string' || !task.prompt.trim()
        || task.prompt.length > 1000 || ![5, 10].includes(task.duration) || !['720:1280', '1280:720'].includes(task.ratio)) return reply(400, { error: 'Invalid task' });
      const resume = url.pathname === '/resume';
      if (resume) {
        if (!validProviderId(task.provider_task_id) || ['READY', 'FAILED', 'CANCELLED', 'CANCELED'].includes(task.status)) return reply(409, { error: 'Task cannot resume' });
      } else {
        if (task.status !== 'SUBMITTING' || task.provider_task_id) return reply(409, { error: 'Task already submitted' });
        try { const lock = await open(`${taskPath(body.id)}.sdk.lock`, 'wx', 0o600); await lock.close(); }
        catch (error) { if (error.code === 'EEXIST') return reply(409, { error: 'Submission already attempted' }); throw error; }
      }
      // Reserve before yielding, preventing concurrent starts or resumes.
      jobs.set(body.id, true);
      reply(202, { accepted: true });
      void run(task, body, resume).catch(() => { /* Persisted task remains recoverable; never expose raw SDK errors. */ })
        .finally(() => jobs.delete(body.id));
    } catch (error) { reply(error.code === 'ENOENT' ? 404 : 500, { error: 'Local service error' }); }
    finally { if (reservedId) reservations.delete(reservedId); }
  });
  server.requestTimeout = 15_000;
  return server;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const port = Number(process.env.MASK_VIDEO_SDK_PORT || 8787);
  if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid MASK_VIDEO_SDK_PORT');
  const storageDir = process.env.MASK_VIDEO_STORAGE_DIR ? resolve(process.env.MASK_VIDEO_STORAGE_DIR) : resolve(root, 'storage');
  await mkdir(resolve(storageDir, 'tasks'), { recursive: true });
  await mkdir(resolve(storageDir, 'videos'), { recursive: true });
  const server = createService({ storageDir });
  server.on('error', () => { console.error('تعذر تشغيل خدمة SDK. تحقق من المنفذ.'); process.exitCode = 1; });
  server.listen(port, '127.0.0.1', () => console.log(`خدمة Runway SDK جاهزة محليًا على 127.0.0.1:${port}. لا تغلق هذه النافذة أثناء التوليد.`));
}
