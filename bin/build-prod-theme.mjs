#!/usr/bin/env node
/**
 * build-prod-theme.mjs
 * ============================================================================
 *
 * Builds a minified, production-ready copy of the `newpixel` theme into a
 * sibling `pixelmagazine/` folder (or THEME_BUILD_DEST). Run via `npm run build:prod`, which chains
 * a `vite build` first so the `dist/` bundle is fresh before we copy.
 *
 * Behaviour:
 *   1. Resolve source = the theme folder this script lives in (newpixel/)
 *   2. Resolve destination = THEME_BUILD_DEST, else sibling `pixelmagazine/`
 *      inside a themes/ folder, else `build/pixelmagazine/`
 *   3. Sanity-check the destination path before touching anything
 *   4. WIPE the destination (whole folder) so each build is deterministic
 *   5. Walk the source tree, processing files by type:
 *        - .php      -> php_strip_whitespace() via the PHP CLI
 *        - style.css -> preserve the WP theme header, minify the CSS body,
 *                       rewrite "Theme Name:" to "Pixel Magazine"
 *        - .css      -> minify (collapse whitespace / strip comments)
 *        - .json     -> JSON.stringify without indentation
 *        - others    -> copy raw (fonts, images, ico, woff2, etc.)
 *   6. Skip dev artefacts (node_modules, src/, package.json, vite config,
 *      .DS_Store, .idea/, bin/, etc.)
 *   7. Print a summary with file counts + total bytes saved.
 *
 * Why php_strip_whitespace() and not a custom minifier:
 *   It uses PHP's own tokenizer, so it cannot break semantics (it never
 *   touches strings, heredocs or PHP keyword boundaries). It strips PHP
 *   comments and collapses non-essential whitespace between tokens. The
 *   inline HTML between ?> and <?php is left structurally intact, which
 *   is the safe behaviour for WordPress templates.
 *
 * The WP theme header in style.css MUST survive:
 *   WordPress reads the leading /* Theme Name: ... *​/ block to register a
 *   theme. If we strip it, WP won't see the theme in the admin. The script
 *   isolates that block, rewrites the Theme Name, and only minifies what
 *   comes after.
 *
 * Idempotent: running again wipes and rebuilds. Source theme is never
 * modified.
 */

import {
    cpSync, lstatSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync,
} from 'node:fs';
import { basename, dirname, extname, join, relative, resolve, sep } from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { transformSync } from 'esbuild';

const __dirname = dirname(fileURLToPath(import.meta.url));

// ── paths ─────────────────────────────────────────────────────────────────────
const SOURCE = resolve(__dirname, '..');
// Inside a WordPress themes/ folder, build next to the theme; in a standalone
// clone, build into build/. THEME_BUILD_DEST overrides both.
const DEFAULT_DEST = basename(dirname(SOURCE)) === 'themes'
    ? resolve(SOURCE, '../pixelmagazine')
    : resolve(SOURCE, 'build/pixelmagazine');
const DEST = resolve(process.env.THEME_BUILD_DEST || DEFAULT_DEST);

// PHP binary. Override via the PHP_BIN env var if it isn't on the PATH.
const PHP_BIN = process.env.PHP_BIN
    || 'php';

// Path to the PHP-side minifier (lives next to this script in bin/).
const PHP_MIN_SCRIPT = resolve(__dirname, 'php-min.php');

// Production theme metadata (rewritten into style.css header).
const PROD_THEME_NAME    = 'Pixel Magazine';
const PROD_THEME_VERSION = '1.0.0';

// ── what to skip ──────────────────────────────────────────────────────────────
// Entries (file OR directory names) that should never end up in the output.
// Anything outside this set is processed.
const SKIP = new Set([
    'node_modules',
    'src',
    'package.json',
    'package-lock.json',
    'vite.config.js',
    '.DS_Store',
    '.idea',
    'bin',
    'build',
    'docs',
    '.claude',   // local AI assistant settings
    '.vscode',
]);

function isSkipped(name) {
    if (SKIP.has(name)) return true;
    if (name.startsWith('.git')) return true; // .git, .gitignore, .gitattributes, etc.
    // A WordPress theme never reads .md files at runtime and web servers
    // happily serve them, so internal notes must stay out of the build.
    if (/\.md$/i.test(name)) return true;
    return false;
}

// ── safety guard ──────────────────────────────────────────────────────────────
// Refuse to operate on anything but a folder named pixelmagazine that neither
// contains the theme nor sits inside it (build/pixelmagazine being the one
// exception). One typo could otherwise rm -rf the wrong directory.
function assertDestSafe() {
    const containsSource = SOURCE === DEST || SOURCE.startsWith(DEST + sep);
    const insideSource = DEST.startsWith(SOURCE + sep)
        && DEST !== resolve(SOURCE, 'build/pixelmagazine');
    if (basename(DEST) !== 'pixelmagazine' || containsSource || insideSource) {
        throw new Error(
            `refusing to operate on ${DEST} — expected a pixelmagazine folder outside the theme`
        );
    }
}

// ── minifiers ─────────────────────────────────────────────────────────────────

function minifyPHP(srcPath) {
    // Delegated to bin/php-min.php, which:
    //   1) runs php_strip_whitespace() on the PHP code,
    //   2) tokenises the result, normalises T_OPEN_TAG / T_CLOSE_TAG
    //      (drops trailing newlines on "<?php\n" and "?>\n"),
    //   3) minifies T_INLINE_HTML segments (strips HTML comments,
    //      collapses whitespace, preserves <script>/<style>/<pre>/
    //      <textarea> blocks verbatim — those get a separate JS pass
    //      below).
    return execFileSync(
        PHP_BIN,
        [PHP_MIN_SCRIPT, srcPath],
        { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
    );
}

/**
 * Minify the contents of inline <script>...</script> blocks inside a
 * PHP/HTML template using esbuild's transformer (already installed via
 * Vite).
 *
 * Skipped automatically:
 *   - <script src="..."> (external — nothing to minify, just a URL)
 *   - <script type="application/json"> (JSON-LD etc; not JS)
 *   - <script type="module"> with PHP interpolation inside
 *   - Any <script> whose body contains "<?php" or "<?=" — esbuild can't
 *     parse PHP, so we leave those untouched.
 *
 * Failure to minify a particular block is non-fatal: we log a warning
 * and emit the block verbatim so the template still works.
 */
function minifyInlineJS(content, relPathForLogs) {
    return content.replace(
        /(<script\b([^>]*)>)([\s\S]*?)(<\/script>)/gi,
        (match, openTag, attrs, body, closeTag) => {
            if (/\bsrc\s*=/i.test(attrs)) return match;
            // Only minify when the type is JS (default) or explicit JS types.
            const typeMatch = attrs.match(/\btype\s*=\s*['"]?([^'"\s>]+)/i);
            if (typeMatch) {
                const t = typeMatch[1].toLowerCase();
                const isJs = t === 'text/javascript' || t === 'application/javascript' || t === 'module';
                if (!isJs) return match;
            }
            const trimmed = body.trim();
            if (!trimmed) return match;
            // esbuild can't parse PHP interpolation inside JS strings.
            if (/<\?(?:php|=)/.test(body)) return match;
            try {
                const { code } = transformSync(body, {
                    minify: true,
                    loader: 'js',
                    // Keep ASI safe — esbuild defaults are fine.
                });
                return openTag + code.trim() + closeTag;
            } catch (e) {
                console.warn(`  ! js minify skipped in ${relPathForLogs}: ${e.message.split('\n')[0]}`);
                return match;
            }
        }
    );
}

/**
 * Same pattern for inline <style>...</style> blocks. The PHP-side HTML
 * minifier preserves <style> bodies verbatim because whitespace inside
 * CSS rules can occasionally matter (calc() spacing, content: "  "),
 * so CSS comments and indentation slip through. esbuild's CSS minifier
 * cleans them up correctly here. Skipped on PHP interpolation.
 */
function minifyInlineCSS(content, relPathForLogs) {
    return content.replace(
        /(<style\b[^>]*>)([\s\S]*?)(<\/style>)/gi,
        (match, openTag, body, closeTag) => {
            const trimmed = body.trim();
            if (!trimmed) return match;
            if (/<\?(?:php|=)/.test(body)) return match;
            try {
                const { code } = transformSync(body, {
                    minify: true,
                    loader: 'css',
                });
                return openTag + code.trim() + closeTag;
            } catch (e) {
                console.warn(`  ! css minify skipped in ${relPathForLogs}: ${e.message.split('\n')[0]}`);
                return match;
            }
        }
    );
}

function minifyCSS(css) {
    return css
        .replace(/\/\*[\s\S]*?\*\//g, '')      // strip /* ... */ comments
        .replace(/\s+/g, ' ')                  // collapse whitespace
        .replace(/\s*([{}:;,>+~])\s*/g, '$1')  // tighten around CSS tokens
        .replace(/;}/g, '}')                   // drop trailing semicolons
        .trim();
}

function minifyStyleCSS(srcPath) {
    // style.css at theme root is special: WordPress parses the leading
    // /* Theme Name: ... */ block to register the theme. We preserve it
    // (with rewritten Theme Name + Version) and minify everything after.
    const raw = readFileSync(srcPath, 'utf8');
    const headerMatch = raw.match(/^\s*\/\*[\s\S]*?\*\//);

    let header = '';
    let body   = raw;

    if (headerMatch) {
        header = headerMatch[0]
            .replace(/Theme Name:\s*[^\n*]*/i,    `Theme Name: ${PROD_THEME_NAME}`)
            .replace(/Version:\s*[^\n*]*/i,       `Version: ${PROD_THEME_VERSION}`)
            .replace(/Text Domain:\s*[^\n*]*/i,   'Text Domain: pixelmagazine');
        body = raw.slice(headerMatch[0].length);
    }

    const minBody = minifyCSS(body);
    return header + (minBody ? '\n' + minBody : '');
}

function minifyJSON(srcPath) {
    return JSON.stringify(JSON.parse(readFileSync(srcPath, 'utf8')));
}

// ── per-file dispatcher ───────────────────────────────────────────────────────
const stats = {
    php: 0, css: 0, json: 0, copied: 0,
    srcBytes: 0, destBytes: 0,
};

function processFile(srcPath, destPath) {
    const srcBytes = statSync(srcPath).size;
    stats.srcBytes += srcBytes;

    const name = basename(srcPath);
    const ext  = extname(srcPath).toLowerCase();
    const relToSource = relative(SOURCE, srcPath);

    // dist/ is already minified by Vite — copy verbatim.
    const isInDist = relToSource.split('/')[0] === 'dist';

    let out;
    if (name === 'style.css' && relToSource === 'style.css') {
        out = minifyStyleCSS(srcPath);
        stats.css++;
    } else if (ext === '.php') {
        out = minifyPHP(srcPath);
        // Pass 3: minify JS inside any inline <script> blocks the PHP
        // pass left untouched.
        out = minifyInlineJS(out, relToSource);
        // Pass 4: same idea for <style> blocks.
        out = minifyInlineCSS(out, relToSource);
        stats.php++;
    } else if (ext === '.json') {
        out = minifyJSON(srcPath);
        stats.json++;
    } else if (ext === '.css' && !isInDist) {
        out = minifyCSS(readFileSync(srcPath, 'utf8'));
        stats.css++;
    } else {
        cpSync(srcPath, destPath);
        stats.copied++;
        stats.destBytes += statSync(destPath).size;
        return;
    }

    writeFileSync(destPath, out);
    stats.destBytes += Buffer.byteLength(out, 'utf8');
}

// ── walker ────────────────────────────────────────────────────────────────────
function walk(srcDir, destDir) {
    mkdirSync(destDir, { recursive: true });
    for (const entry of readdirSync(srcDir)) {
        if (isSkipped(entry)) continue;
        const srcPath  = join(srcDir, entry);
        const destPath = join(destDir, entry);

        // Symlinks never ship: cpSync copies the link, not its content, and an
        // absolute target from this machine would dangle on the server.
        if (lstatSync(srcPath).isSymbolicLink()) {
            console.log(`  ↷ symlink ignorado: ${relative(SOURCE, srcPath)}`);
            continue;
        }

        const s = statSync(srcPath);
        if (s.isDirectory()) {
            walk(srcPath, destPath);
        } else if (s.isFile()) {
            try {
                processFile(srcPath, destPath);
            } catch (err) {
                console.error(`✗ ${relative(SOURCE, srcPath)}`);
                console.error(`  ${err.message.split('\n')[0]}`);
                throw err;
            }
        }
    }
}

// ── go ────────────────────────────────────────────────────────────────────────
console.log('build-prod-theme');
console.log(`  src:  ${SOURCE}`);
console.log(`  dest: ${DEST}`);

assertDestSafe();

// Wipe destination so each build is deterministic.
try {
    rmSync(DEST, { recursive: true, force: true });
    console.log('  wiped existing destination');
} catch (err) {
    if (err.code !== 'ENOENT') throw err;
}

const t0 = Date.now();
walk(SOURCE, DEST);
const ms = Date.now() - t0;

const saved   = stats.srcBytes - stats.destBytes;
const savedPc = stats.srcBytes ? ((saved / stats.srcBytes) * 100).toFixed(1) : '0.0';
const fmt = b => (b / 1024).toFixed(1) + ' KB';

console.log('');
console.log(`  php minified:   ${stats.php}`);
console.log(`  css minified:   ${stats.css}`);
console.log(`  json minified:  ${stats.json}`);
console.log(`  raw copied:     ${stats.copied}`);
console.log('');
console.log(`  source size:    ${fmt(stats.srcBytes)}`);
console.log(`  output size:    ${fmt(stats.destBytes)} (${savedPc}% smaller)`);
console.log(`  elapsed:        ${ms} ms`);
