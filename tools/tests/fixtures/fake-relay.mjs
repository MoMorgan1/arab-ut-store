import { readFileSync, writeFileSync } from 'node:fs';

const capturePath = process.env.ARABUT_WORKER_CAPTURE;

if (!capturePath) {
    process.stderr.write('ARABUT_WORKER_CAPTURE is required\n');
    process.exit(2);
}

writeFileSync(
    capturePath,
    `${JSON.stringify({
        args: process.argv.slice(2),
        apiKeysPresent: [
            'GEMINI_API_KEY',
            'GOOGLE_API_KEY',
            'GOOGLE_APPLICATION_CREDENTIALS',
            'OPENAI_API_KEY',
        ].some((name) => Boolean(process.env[name])),
    })}\n`,
    'utf8',
);

if (process.env.ARABUT_WORKER_FAKE_ACTION === 'change-ref') {
    const args = process.argv.slice(2);
    const workdir = args[args.indexOf('--cd') + 1];
    const gitDir = `${workdir}/.git`;
    const currentRef = readFileSync(`${gitDir}/HEAD`, 'utf8')
        .trim()
        .replace('ref: ', '');
    const currentCommit = readFileSync(`${gitDir}/${currentRef}`, 'utf8');

    writeFileSync(`${gitDir}/refs/heads/forbidden`, currentCommit, 'utf8');
    writeFileSync(`${gitDir}/HEAD`, 'ref: refs/heads/forbidden\n', 'utf8');
}

if (process.env.ARABUT_WORKER_FAKE_ACTION === 'write-file') {
    const args = process.argv.slice(2);
    const workdir = args[args.indexOf('--cd') + 1];

    writeFileSync(`${workdir}/read-only-violation.txt`, 'forbidden\n', 'utf8');
}

if (process.env.ARABUT_WORKER_FAKE_ACTION === 'modify-existing') {
    const args = process.argv.slice(2);
    const workdir = args[args.indexOf('--cd') + 1];

    writeFileSync(
        `${workdir}/${process.env.ARABUT_WORKER_FAKE_TARGET}`,
        'changed\n',
        'utf8',
    );
}

process.stdout.write('fake relay completed\n');
process.exit(Number.parseInt(process.env.ARABUT_WORKER_FAKE_EXIT ?? '0', 10));
