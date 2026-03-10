import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(fileURLToPath(new URL('..', import.meta.url)));
const outDir = join(tmpdir(), 'worksphere-legacy-sfu-tests');

function run(cmd, args) {
    const result = spawnSync(cmd, args, {
        cwd: rootDir,
        stdio: 'inherit',
        env: {
            ...process.env,
            FORCE_COLOR: process.env.FORCE_COLOR || '1',
        },
    });

    if (result.status !== 0) {
        process.exit(result.status ?? 1);
    }
}

run('./node_modules/.bin/tsc', [
    '-p',
    'tsconfig.legacy-sfu-tests.json',
    '--outDir',
    outDir,
]);

run('node', [
    '--test',
    join(outDir, 'tests/legacy-sfu-publication-state.test.js'),
]);
