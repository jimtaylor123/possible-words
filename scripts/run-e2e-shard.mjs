import { spawnSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import net from 'node:net';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const availablePort = () => new Promise((resolvePort, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
        const { port } = server.address();
        server.close(error => error ? reject(error) : resolvePort(String(port)));
    });
});

const suppliedRunId = process.env.E2E_RUN_ID;
const shard = process.env.E2E_SHARD ?? process.argv.find(arg => arg.startsWith('--shard='))?.replace('--shard=', '')?.replace('/', '-');
const runId = suppliedRunId ?? `${shard ?? 'local'}-${Date.now()}-${process.pid}`;
const safeRunId = runId.replaceAll(/[^a-zA-Z0-9_-]/g, '-');
const runtimeDir = resolve(root, 'storage/e2e', safeRunId);
mkdirSync(runtimeDir, { recursive: true });

const port = process.env.PLAYWRIGHT_PORT ?? await availablePort();
const ci = process.env.CI === '1' || process.env.CI === 'true';
const args = process.argv.slice(2);
const env = {
    ...process.env,
    E2E_RUN_ID: safeRunId,
    E2E_RUNTIME_DIR: runtimeDir,
    E2E_DATABASE: resolve(runtimeDir, 'testing.sqlite'),
    E2E_HEADLESS: ci ? '1' : (process.env.E2E_HEADLESS ?? '0'),
    PLAYWRIGHT_PORT: port,
};

const result = spawnSync('npx', ['playwright', 'test', ...args], {
    cwd: root,
    env,
    stdio: 'inherit',
});

if (!process.env.E2E_KEEP_RUNTIME) rmSync(runtimeDir, { recursive: true, force: true });
process.exit(result.status ?? 1);
