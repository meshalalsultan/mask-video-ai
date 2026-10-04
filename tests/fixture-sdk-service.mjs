// Test-only service. No production flag enables fake generation.
import { createService } from '../sdk/service.mjs';
import { writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
const [, , storageDir, port] = process.argv;
const result = { id: 'test-provider-id', status: 'SUCCEEDED', output: ['https://example.org/fixture.mp4'] };
const handle = () => {
  const promise = Promise.resolve({ id: result.id });
  promise.waitForTaskOutput = async () => result;
  return promise;
};
const server = createService({ storageDir: resolve(storageDir),
  clientFactory: () => ({ textToVideo: { create: handle }, tasks: { retrieve: handle } }),
  downloader: async (_url, destination) => writeFile(destination, Buffer.concat([Buffer.from([0, 0, 0, 24]), Buffer.from('ftypisom'), Buffer.alloc(100, 120)])),
});
server.listen(Number(port), '127.0.0.1');
