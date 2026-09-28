/*
 * Runs tests/js/browser-tests.html in a Chrome instance that exposes the DevTools protocol
 * (start Chrome with --remote-debugging-port=9222, or set PCT_CDP=http://host:port).
 * No npm dependencies: uses Node's built-in fetch + WebSocket (Node >= 22).
 */
'use strict';
const path = require('path');
const http = require('http');
const fs = require('fs');

const cdp = process.env.PCT_CDP || 'http://127.0.0.1:9222';
const repoRoot = path.resolve(__dirname, '..', '..');
const mime = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.png': 'image/png', '.json': 'application/json' };

/* Serve the checkout over HTTP so browser errors carry real messages (file:// scripts only report "Script error."). */
function serve() {
	return new Promise((resolve) => {
		const server = http.createServer((req, res) => {
			const rel = decodeURIComponent(req.url.split('?')[0]).replace(/^\/+/, '');
			const abs = path.join(repoRoot, rel);
			if (!abs.startsWith(repoRoot) || !fs.existsSync(abs) || fs.statSync(abs).isDirectory()) {
				res.writeHead(404); res.end('not found'); return;
			}
			res.writeHead(200, { 'Content-Type': mime[path.extname(abs)] || 'application/octet-stream' });
			fs.createReadStream(abs).pipe(res);
		});
		server.listen(0, '127.0.0.1', () => resolve(server));
	});
}

async function main() {
	const server = await serve();
	const url = 'http://127.0.0.1:' + server.address().port + '/tests/js/browser-tests.html';
	const target = await (await fetch(cdp + '/json/new?' + encodeURIComponent(url), { method: 'PUT' })).json();
	const ws = new WebSocket(target.webSocketDebuggerUrl);
	await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
	let id = 0;
	const pending = new Map();
	ws.onmessage = (ev) => {
		const msg = JSON.parse(ev.data);
		if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
	};
	const send = (method, params) => new Promise((res) => { pending.set(++id, res); ws.send(JSON.stringify({ id, method, params })); });
	const evaluate = async (expression) => {
		const r = await send('Runtime.evaluate', { expression, returnByValue: true });
		return r.result && r.result.result ? r.result.result.value : undefined;
	};

	const started = Date.now();
	let result;
	while (!result && Date.now() - started < 60000) {
		result = await evaluate('window.PCT_TEST_RESULT ? JSON.stringify(window.PCT_TEST_RESULT) : null');
		if (!result) { await new Promise((r) => setTimeout(r, 300)); }
	}
	if (!result) {
		console.error('timed out waiting for browser tests');
		console.error(await evaluate('document.getElementById("results").textContent'));
		process.exitCode = 1;
	} else {
		const r = JSON.parse(result);
		r.log.forEach((l) => console.log('  ' + l));
		console.log('\nFAILS: ' + r.fails + ' / ' + r.total);
		process.exitCode = r.fails ? 1 : 0;
	}
	if (!process.env.PCT_KEEP) {
		await fetch(cdp + '/json/close/' + target.id);
	}
	ws.close();
	server.close();
}

main().catch((e) => { console.error(e); process.exit(1); });
