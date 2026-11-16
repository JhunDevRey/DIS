// Loads every staff and client page in headless Chrome on the LOCAL rig and
// reports Content-Security-Policy violations, script errors and failed loads.
// Usage: node ui_pages.mjs [staffUser] [staffPass]
import { spawn } from 'node:child_process';
import fs from 'node:fs';
const S = (process.env.DIS_TEST_DIR || 'C:/Temp/dis-rig').split(String.fromCharCode(92)).join('/');
const A = 'http://127.0.0.1:3401/', P = 'http://127.0.0.1:3402/';
const STAFF_USER = process.argv[2] || 'processing', STAFF_PASS = process.argv[3] || 'StaffTest#2026';
const PROF = `${S}/prof-ui-${Date.now()}`;
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--remote-debugging-port=9351', `--user-data-dir=${PROF}`, '--window-size=1440,1000', 'about:blank']);
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws;
for (let i = 0; i < 60; i++) { try { const t = await (await fetch('http://127.0.0.1:9351/json')).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = p.webSocketDebuggerUrl; break; } } catch {} await sleep(250); }
const sock = new WebSocket(ws); await new Promise(r => sock.onopen = r);
let id = 0; const pend = {}; let logs = [];
sock.onmessage = m => {
  const d = JSON.parse(m.data);
  if (d.id && pend[d.id]) { pend[d.id](d); delete pend[d.id]; }
  if (d.method === 'Runtime.exceptionThrown') logs.push('JS  ' + (d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text).split('\n')[0]);
  if (d.method === 'Runtime.consoleAPICalled' && d.params.type === 'error') logs.push('ERR ' + d.params.args.map(a => a.value ?? a.description).join(' '));
  if (d.method === 'Log.entryAdded' && ['error', 'warning'].includes(d.params.entry.level)) logs.push('LOG ' + d.params.entry.text.slice(0, 220));
  if (d.method === 'Network.loadingFailed' && !d.params.canceled) logs.push('NET ' + d.params.errorText + ' ' + (d.params.blockedReason || ''));
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pend[i] = r; sock.send(JSON.stringify({ id: i, method, params })); });
const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }); return r.result?.result?.value ?? r.result?.exceptionDetails?.exception?.description; };
const go = async (url, wait = 1800) => { await send('Page.navigate', { url }); await sleep(wait); };
await send('Runtime.enable'); await send('Log.enable'); await send('Network.enable'); await send('Page.enable');

let bad = 0;
async function visit(url, label) {
  logs = [];
  await go(url);
  const path = await ev('location.pathname + location.search');
  // Font Awesome icons rendered = external stylesheet + fonts allowed by CSP.
  const fa = await ev(`(() => { const i = document.querySelector('.fa-solid, .fa-regular'); return i ? getComputedStyle(i, '::before').fontFamily : 'no-icon'; })()`);
  const issues = logs.filter(l => !/favicon|status of 404/.test(l));
  if (issues.length) bad++;
  console.log(`${issues.length ? 'ISSUES' : 'ok    '} ${label.padEnd(22)} ${path}  icons:${String(fa).slice(0, 24)}`);
  issues.forEach(l => console.log('         ' + l));
}

await go(A + 'login.php');
await ev(`document.getElementById('username').value='${STAFF_USER}'; document.getElementById('password').value='${STAFF_PASS}'; document.querySelector('form').submit(); 1`);
await sleep(2200);
console.log('staff landed on', await ev('location.pathname'));
for (const [p, l] of [['frontdesk.php', 'Front Desk'], ['clients.php', 'Clients'], ['client.php?id=1', 'Client file'], ['inbox.php', 'Inbox'], ['inbox.php?client_id=1', 'Inbox thread'], ['processors.php', 'Processors'], ['classes.php', 'Classes'], ['dashboard.php', 'Dashboard'], ['profile.php', 'My Profile'], ['staff.php', 'Staff accounts'], ['activity.php', 'Activity']]) {
  await visit(A + p, l);
}
const fileId = await ev(`fetch('api/admin_messages.php?list=1').then(r => r.ok ? 'api-ok' : 'api-' + r.status)`);
console.log('staff API from page:', fileId);

await go(P + 'login.php');
await ev(`document.getElementById('email').value='maria.santos@example.test'; document.getElementById('password').value='ClientTest#2026'; document.querySelector('form').submit(); 1`);
await sleep(2200);
console.log('client landed on', await ev('location.pathname'));
for (const [p, l] of [['documents.php', 'Documents'], ['application_review.php', 'Application review'], ['messages.php', 'Messages'], ['dashboard.php', 'Dashboard'], ['profile.php', 'Profile'], ['forgot_password.php', 'Forgot password'], ['reset_password.php?t=bad', 'Reset (bad link)']]) {
  await visit(P + p, l);
}
await visit(P + 'login.php', 'Client login (signed in)');
console.log(bad ? `\n${bad} page(s) with issues` : '\nall pages clean');
sock.close(); chrome.kill();
process.exit(0);
