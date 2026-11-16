// PDF and image viewers under the new Content-Security-Policy (LOCAL rig).
import { spawn } from 'node:child_process';
const S = (process.env.DIS_TEST_DIR || 'C:/Temp/dis-rig').split(String.fromCharCode(92)).join('/');
const [pdfId, pngId] = process.argv.slice(2);
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--remote-debugging-port=9352', `--user-data-dir=${S}/prof-v-${Date.now()}`, 'about:blank']);
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws; for (let i = 0; i < 60; i++) { try { const t = await (await fetch('http://127.0.0.1:9352/json')).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = p.webSocketDebuggerUrl; break; } } catch {} await sleep(250); }
const sock = new WebSocket(ws); await new Promise(r => sock.onopen = r);
let id = 0; const pend = {}; let logs = [];
sock.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pend[d.id]) { pend[d.id](d); delete pend[d.id]; }
  if (d.method === 'Runtime.exceptionThrown') logs.push('JS ' + (d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text).split('\n')[0]);
  if (d.method === 'Log.entryAdded' && d.params.entry.level === 'error' && !/404/.test(d.params.entry.text)) logs.push('LOG ' + d.params.entry.text.slice(0, 200)); };
const send = (method, params = {}) => new Promise(r => { const i = ++id; pend[i] = r; sock.send(JSON.stringify({ id: i, method, params })); });
const ev = async (e) => { const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true }); return r.result?.result?.value ?? r.result?.exceptionDetails?.exception?.description; };
const go = async (u, w = 2500) => { await send('Page.navigate', { url: u }); await sleep(w); };
await send('Runtime.enable'); await send('Log.enable'); await send('Page.enable');
for (const [base, login] of [['http://127.0.0.1:3401/', `document.getElementById('username').value='processing'`], ['http://127.0.0.1:3402/', `document.getElementById('email').value='maria.santos@example.test'`]]) {
  await go(base + 'login.php', 1500);
  await ev(`${login}; document.getElementById('password').value='${base.includes('3401') ? 'StaffTest#2026' : 'ClientTest#2026'}'; document.querySelector('form').submit(); 1`);
  await sleep(2000);
  logs = []; await go(base + 'view_file.php?file_id=' + pdfId, 4000);
  console.log(base.includes('3401') ? 'staff ' : 'client', 'PDF  pages rendered:', await ev(`document.querySelectorAll('#pdfjsPages canvas').length`), '| indicator:', await ev(`document.getElementById('pdfjsPageIndicator')?.innerText`), logs.length ? '\n   ' + logs.join('\n   ') : '');
  logs = []; await go(base + 'view_file.php?file_id=' + pngId, 2500);
  console.log(base.includes('3401') ? 'staff ' : 'client', 'PNG  loaded:', await ev(`(() => { const i = document.getElementById('previewImage'); return i ? i.complete && i.naturalWidth : 'no img'; })()`), logs.length ? '\n   ' + logs.join('\n   ') : '');
}
sock.close(); chrome.kill(); process.exit(0);
