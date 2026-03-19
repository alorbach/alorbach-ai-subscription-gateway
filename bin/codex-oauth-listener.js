#!/usr/bin/env node
/**
 * Temporary local HTTP server to catch the ChatGPT Codex OAuth callback.
 *
 * Usage:
 *   node bin/codex-oauth-listener.js
 *
 * 1. Run this script FIRST (it listens on http://localhost:1455).
 * 2. In WordPress admin → API Keys → Codex → click "Get Authorization URL".
 * 3. Open the shown URL in your browser and complete the ChatGPT login.
 * 4. The browser is redirected to localhost:1455 — this script captures the
 *    full callback URL and prints it.
 * 5. Copy the printed URL and paste it into the WordPress "Paste callback URL"
 *    field, then click "Exchange Token".
 */

'use strict';

const http = require('http');
const url  = require('url');

const PORT = 1455;
const PATH = '/auth/callback';

const server = http.createServer((req, res) => {
    const parsed = url.parse(req.url, true);

    if (parsed.pathname !== PATH) {
        res.writeHead(404);
        res.end('Not found');
        return;
    }

    const { code, state } = parsed.query;
    const fullUrl = `http://localhost:${PORT}${req.url}`;

    // Respond to the browser so it doesn't hang.
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`<!DOCTYPE html>
<html>
<head><title>Codex OAuth – Callback Captured</title>
<style>body{font-family:sans-serif;padding:2em;max-width:800px;margin:auto}
code{background:#f4f4f4;padding:.2em .5em;border-radius:3px;word-break:break-all;display:block;margin:.5em 0}
.ok{color:green}.warn{color:orange}</style>
</head>
<body>
<h2 class="ok">&#10003; Callback captured!</h2>
<p>Copy the URL below and paste it into the WordPress admin form:</p>
<code id="u">${fullUrl}</code>
<button onclick="navigator.clipboard.writeText(document.getElementById('u').textContent)">Copy to clipboard</button>
${!code  ? '<p class="warn">WARNING: No <code>code</code> parameter found.</p>'  : ''}
${!state ? '<p class="warn">WARNING: No <code>state</code> parameter found.</p>' : ''}
<hr><p style="color:#888">You can close this tab and stop the terminal now.</p>
</body></html>`);

    console.log('\n✅  Callback received!');
    console.log('──────────────────────────────────────────────────');
    console.log('Full callback URL (paste this into WordPress admin):');
    console.log('');
    console.log(fullUrl);
    console.log('');
    console.log('code  :', code  || '(missing)');
    console.log('state :', state || '(missing)');
    console.log('──────────────────────────────────────────────────');
    console.log('Shutting down listener…\n');

    server.close();
});

server.listen(PORT, '127.0.0.1', () => {
    console.log(`\n🔌  Codex OAuth listener ready on http://localhost:${PORT}${PATH}`);
    console.log('    Now get the authorization URL from WordPress admin and open it in your browser.');
    console.log('    Waiting for callback…\n');
});

server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
        console.error(`\n❌  Port ${PORT} is already in use.`);
        console.error('    If a previous listener is still running, close it first.');
        console.error('    Or the Codex CLI itself may already be listening on this port.\n');
    } else {
        console.error('Server error:', err);
    }
    process.exit(1);
});
