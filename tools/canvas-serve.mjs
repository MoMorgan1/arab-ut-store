/**
 * Static server for the design canvases in docs/design/.
 *
 * The canvases link to `/build/assets/app-*.css` and `/fonts/thmanyah/*.woff2`,
 * which are the real site's URLs, so the site's document root (`public/`) is the
 * base for assets while `docs/` has to stay reachable too. Requests resolve
 * against the repository root first, then `public/`.
 *
 * Review tooling only: this never touches the application, the database, or the
 * build. Run it with `npm run canvas:serve` and stop it with Ctrl+C.
 */
import { readFile, stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { networkInterfaces } from 'node:os';
import { extname, join, normalize } from 'node:path';

const ROOT = process.cwd();
const PUBLIC = join(ROOT, 'public');
const PORT = Number(process.env.CANVAS_PORT ?? 5199);

const TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.woff2': 'font/woff2',
    '.woff': 'font/woff',
    '.ttf': 'font/ttf',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.webp': 'image/webp',
    '.ico': 'image/x-icon',
    '.txt': 'text/plain; charset=utf-8',
};

function resolveWithin(base, rel) {
    const path = join(base, rel);

    return path.startsWith(base) ? path : null;
}

const server = createServer(async (req, res) => {
    const url = new URL(req.url, `http://${req.headers.host ?? 'localhost'}`);
    const rel = normalize(decodeURIComponent(url.pathname)).replace(
        /^[\\/]+/,
        '',
    );
    const wantsIndex = rel === '' || rel.endsWith('/') || rel.endsWith('\\');

    for (const base of [ROOT, PUBLIC]) {
        const path = resolveWithin(
            base,
            wantsIndex ? join(rel, 'index.html') : rel,
        );

        if (path === null) {
            continue;
        }

        try {
            const info = await stat(path);

            if (info.isDirectory()) {
                continue;
            }

            const body = await readFile(path);
            res.writeHead(200, {
                'content-type':
                    TYPES[extname(path).toLowerCase()] ??
                    'application/octet-stream',
                'cache-control': 'no-store',
            });
            res.end(body);

            return;
        } catch {
            /* try the next base */
        }
    }

    res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' }).end(
        `not found: /${rel}\n`,
    );
});

server.listen(PORT, '0.0.0.0', () => {
    const addresses = Object.values(networkInterfaces())
        .flat()
        .filter((entry) => entry && entry.family === 'IPv4' && !entry.internal)
        .map((entry) => entry.address);

    console.log('Arab UT · canvas server\n');
    console.log(
        `  this machine   http://127.0.0.1:${PORT}/docs/design/bottom-nav-motion.canvas.html`,
    );

    for (const address of addresses) {
        console.log(
            `  on your network  http://${address}:${PORT}/docs/design/bottom-nav-motion.canvas.html`,
        );
    }

    console.log('\n  variants  ?variant=pill | capsule | mark | lift');
    console.log('  states    &dir=ltr    &reduced=1\n');
    console.log('  Ctrl+C to stop.');
});
