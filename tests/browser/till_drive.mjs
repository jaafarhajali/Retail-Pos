// Drives the till in headless Chrome (DevTools protocol) as the cashier sara on the test database. till_test.sh splices a
// scenario file between the SCENARIO markers; see tests/browser/scenarios/*.txt for examples and tests/browser/README.md.
import fs from 'node:fs';

const [, , jarPath, outDir, base = 'http://127.0.0.1:8191', port = '9333'] = process.argv;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const cookies = fs.readFileSync(jarPath, 'utf8').split(/\r?\n/)
  .map((l) => l.replace(/^#HttpOnly_/, '')).filter((l) => l && !l.startsWith('#')).map((l) => l.split('\t'))
  .filter((f) => f.length >= 7).map((f) => ({ name: f[5], value: f[6] }));

let target;
for (let i = 0; i < 40 && !target; i++) {
  try { target = (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find((t) => t.type === 'page'); } catch { await sleep(250); }
}
if (!target) { console.error('Chrome did not start'); process.exit(1); }

const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => { ws.onopen = r; });
let seq = 0; const pending = new Map();
ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
const send = (method, params = {}) => new Promise((res) => { const id = ++seq; pending.set(id, res); ws.send(JSON.stringify({ id, method, params })); });
const ev = async (expression) => {
  const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (r.result.exceptionDetails) { throw new Error(expression + ' -> ' + JSON.stringify(r.result.exceptionDetails.exception?.description ?? r.result.exceptionDetails.text)); }
  return r.result.result.value;
};
const waitFor = async (expression, what, ms = 8000) => {
  for (let t = 0; t < ms; t += 150) { if (await ev(`!!(${expression})`)) return; await sleep(150); }
  throw new Error('timeout waiting for ' + what);
};
const shot = async (name) => {
  await sleep(450);
  const r = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(`${outDir}/${name}.png`, Buffer.from(r.result.data, 'base64'));
};
const type = (id, value) => ev(`(() => { const f = document.getElementById('${id}'); f.focus(); f.value = ${JSON.stringify(value)}; f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true })); })()`);
const click = (sel) => ev(`document.querySelector(${JSON.stringify(sel)}).click()`);
const shown = (id) => `document.getElementById('${id}').classList.contains('show')`;
const log = (k, v) => console.log(k.padEnd(34), v);

await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 850, deviceScaleFactor: 1, mobile: false });
for (const c of cookies) { await send('Network.setCookie', { name: c.name, value: c.value, url: base + '/' }); }
const errors = [];
ws.addEventListener('message', (m) => { const d = JSON.parse(m.data); if (d.method === 'Runtime.exceptionThrown') errors.push(d.params.exceptionDetails.exception?.description ?? d.params.exceptionDetails.text); });

await send('Page.navigate', { url: base + '/index.php?r=pos' });
await waitFor(`document.querySelector('.tile')`, 'product tiles');
log('user can discount without PIN:', await ev('window.POS.can.discount') + ' (max ' + await ev('window.POS.maxDiscount') + ' %)');

// SCENARIO
// (replaced by the scenario file)
// END SCENARIO
log('page errors:', errors.length ? errors.join(' | ') : 'none');
ws.close();
