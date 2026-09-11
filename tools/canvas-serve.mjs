/**
 * Static server for the design canvases in docs/design/.
 *
 * The canvases link to `/build/assets/app-*.css` and `/fonts/thmanyah/*.woff2`,
 * which are the real site's URLs, so the site's document root (`public/`) is the
 * base for assets while `docs/` has to stay reachable too.
 *
 * Only those two directories are reachable, and only through the extensions a
 * canvas actually loads. The repository root is NOT a base: serving it would put
 * `.env` — the Paylink keys, `APP_KEY`, the database password — one request away
 * from anyone who could reach the port.
 *
 * That is also why the socket binds to loopback. Reviewing a phone artboard on a
 * real phone needs the LAN, so `CANVAS_HOST=0.0.0.0 npm run canvas:serve` still
 * opens it up — deliberately, one review at a time, on a network you trust.
 *
 * Review tooling only: this never touches the application, the database, or the
 * build. Run it with `npm run canvas:serve` and stop it with Ctrl+C.
 */
import { readFile, stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { networkInterfaces } from 'node:os';
import { basename, extname, join, normalize, resolve, sep } from 'node:path';

const ROOT = process.cwd();
const PORT = Number(process.env.CANVAS_PORT ?? 5199);
const HOST = process.env.CANVAS_HOST ?? '127.0.0.1';

// The canvases live under `docs/`; everything else they load is a built asset or
// a font, which the site serves out of `public/`. Nothing else is reachable.
const BASES = [join(ROOT, 'docs'), join(ROOT, 'public')];

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

/**
 * Resolves `rel` inside `base`, or null when it escapes. A canvas is requested
 * as `/docs/design/x.html`, so the `docs/` prefix is dropped before joining onto
 * the docs base; every other request is a site-root path such as
 * `/build/assets/app.css` and joins as it stands.
 *
 * The containment test compares against `base + sep` rather than `base` alone,
 * so a sibling directory whose name merely starts with the base name cannot pass.
 */
function resolveWithin(base, rel) {
    const scoped =
        basename(base) === 'docs' ? rel.replace(/^docs[\\/]/, '') : rel;
    const path = resolve(base, scoped);

    return path === base || path.startsWith(base + sep) ? path : null;
}

const server = createServer(async (req, res) => {
    const url = new URL(req.url, `http://${req.headers.host ?? 'localhost'}`);
    const rel = normalize(decodeURIComponent(url.pathname)).replace(
        /^[\\/]+/,
        '',
    );
    const wantsIndex = rel === '' || rel.endsWith('/') || rel.endsWith('\\');

    for (const base of BASES) {
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

            const type = TYPES[extname(path).toLowerCase()];

            // An allowlist rather than a fallback octet-stream: a canvas only
            // ever loads these types, so anything else asked for here is a
            // request this server has no business answering.
            if (type === undefined) {
                break;
            }

            const body = await readFile(path);
            res.writeHead(200, {
                'content-type': type,
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

server.listen(PORT, HOST, () => {
    const addresses =
        HOST === '127.0.0.1'
            ? []
            : Object.values(networkInterfaces())
                  .flat()
                  .filter(
                      (entry) =>
                          entry && entry.family === 'IPv4' && !entry.internal,
                  )
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
    console.log('  states    &dir=ltr    &reduced=1');
    console.log(
        addresses.length > 0
            ? '\n  ⚠ open to your whole network: anyone on it can read docs/ and public/.'
            : '\n  loopback only. CANVAS_HOST=0.0.0.0 to reach it from a phone.',
    );
    console.log('  Ctrl+C to stop.');
});
