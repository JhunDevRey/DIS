// Two-step sign-in in a real browser on the LOCAL rig: set up from My Profile
// (QR from cdnjs under CSP + SRI), save recovery codes, then sign in with a code.
import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
const S = (process.env.DIS_TEST_DIR || 'C:/Temp/dis-rig').split(String.fromCharCode(92)).join('/');
const A = 'http://127.0.0.1:3401/';
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--remote-debugging-port=9353', `--user-data-dir=${S}/prof-2fa-${Date.now()}`, '--window-size=1280,1000', 'about:blank']);
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws; for (let i = 0; i < 60; i++) { try { const t = await (await fetch('http://127.0.0.1:9353/json')).json(); const p = t.find(x => x.type === 'page'); if (p) { ws = p.webSocketDebuggerUrl; break; } } catch {} await sleep(250); }
const sock = new WebSocket(ws); await new Promise(r => sock.onopen = r);
let id = 0; const pend = {}; let logs = [];
sock.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pend[d.id]) { pend[d.id](d); delete pend[d.id]; }
  if (d.method === 'Runtime.exceptionThrown') logs.push('JS ' + (d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text).split('\n')[0]);
  if (d.method === 'Log.entryAdded' && d.params.entry.level === 'error' && !/404/.test(d.params.entry.text)) logs.push('LOG ' + d.params.entry.text.slice(0, 200)); };
const send = (method, params = {}) => new Promise(r => { const i = ++id; pend[i] = r; sock.send(JSON.stringify({ id: i, method, params })); });
const ev = async (e) => { const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true }); return r.result?.result?.value ?? r.result?.exceptionDetails?.exception?.description; };
const go = async (u, w = 1800) => { await send('Page.navigate', { url: u }); await sleep(w); };
const shot = async (name) => { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(`${S}/${name}.png`, Buffer.from(r.result.data, 'base64')); };
function b32decode(s) { const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of s.replace(/\s/g, '').toUpperCase()) bits += a.indexOf(c).toString(2).padStart(5, '0'); const out = []; for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2)); return Buffer.from(out); }
function totp(secret, offset = 0) { const step = Math.floor(Date.now() / 30000) + offset; const buf = Buffer.alloc(8); buf.writeUInt32BE(step, 4); const h = crypto.createHmac('sha1', b32decode(secret)).update(buf).digest(); const o = h[19] & 15; return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0'); }
const ok = (name, cond, extra = '') => console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${cond ? '' : '  ' + extra}`);
await send('Runtime.enable'); await send('Log.enable'); await send('Page.enable');

await go(A + 'login.php');
await ev(`document.getElementById('username').value='processing'; document.getElementById('password').value='StaffTest#2026'; document.querySelector('form').submit(); 1`);
await sleep(2000);
ok('owner signed in', (await ev('location.pathname')) === '/frontdesk.php');
ok('sidebar shows owner links', await ev(`!!document.querySelector('a[href="staff.php"]') && !!document.querySelector('a[href="activity.php"]')`));

logs = [];
await go(A + 'profile.php');
await ev(`document.querySelector('#form2faStart [name=password]').value='StaffTest#2026'; document.querySelector('#form2faStart button').click(); 1`);
await sleep(1500);
const qr = await ev(`(() => { const q = document.getElementById('twoFaQr'); const el = q.querySelector('img, canvas'); return el ? (el.width || el.naturalWidth) : q.textContent; })()`);
ok('QR code drawn (library allowed by CSP + integrity)', typeof qr === 'number' && qr > 100, qr);
const secret = await ev(`document.getElementById('twoFaSecret').textContent`);
ok('manual key shown', /^[A-Z2-7 ]{32,40}$/.test(secret), secret);
await ev(`document.documentElement.setAttribute('data-theme','light'); 1`);
await shot('2fa_setup');
await ev(`document.querySelector('#form2faConfirm [name=code]').value='${totp(secret)}'; document.querySelector('#form2faConfirm button').click(); 1`);
await sleep(1500);
const codes = await ev(`document.getElementById('twoFaCodeList').textContent`);
ok('recovery codes shown once', codes && codes.split('\n').length === 8, codes);
await shot('2fa_codes');
ok('no script or policy errors on My Profile', logs.length === 0, logs.join(' | '));

await go(A + 'logout.php');
await ev(`document.getElementById('username').value='processing'; document.getElementById('password').value='StaffTest#2026'; document.querySelector('form').submit(); 1`);
await sleep(2000);
ok('code step shown after password', await ev(`!!document.getElementById('codeForm')`));
await shot('2fa_login_code');
await ev(`document.getElementById('code').value='${totp(secret, 1)}'; document.querySelector('#codeForm').submit(); 1`);
await sleep(2000);
ok('signed in with the code', (await ev('location.pathname')) === '/frontdesk.php', await ev('location.pathname'));

logs = [];
await go(A + 'staff.php');
ok('staff page: account listed with two-step on', await ev(`document.querySelector('#staffTable tbody tr')?.innerText.includes('On')`));
await ev(`document.getElementById('btnNewAccount').click(); 1`);
await sleep(400);
ok('add-account dialog opens', await ev(`getComputedStyle(document.getElementById('modalAccount')).display !== 'none'`));
await ev(`(() => { const f = document.getElementById('formAccount'); f.admin_name.value='Ben Cruz'; f.username.value='ben'; f.password.value='A long temporary pass'; f.requestSubmit(); return 1; })()`);
await sleep(2500);
ok('account added through the page', await ev(`[...document.querySelectorAll('#staffTable tbody tr')].some(r => r.innerText.includes('Ben Cruz') && r.innerText.includes('Temporary password'))`));
await shot('staff_page');
await go(A + 'activity.php');
ok('activity page lists the new account', await ev(`document.body.innerText.includes('Created staff account')`));
await shot('activity_page');
ok('no script or policy errors on owner pages', logs.length === 0, logs.join(' | '));
sock.close(); chrome.kill(); process.exit(0);
