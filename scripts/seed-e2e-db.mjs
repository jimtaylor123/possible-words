import { copyFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(root, '.snapshots/dev-data.sqlite');
const target = resolve(root, 'database/testing.sqlite');

if (!existsSync(source)) {
    console.error(`Missing fixture ${source}. Restore it from version control and retry.`);
    process.exit(1);
}

mkdirSync(dirname(target), { recursive: true });

// Always start from the same fixture so local runs match CI and stay order-independent.
copyFileSync(source, target);

console.log(`Seeded ${target} from .snapshots/dev-data.sqlite`);