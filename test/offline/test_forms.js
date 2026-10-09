// Every form on an app page works offline (needs jsdom: npm i jsdom@24 in
// this folder or globally). Skips cleanly if jsdom isn't installed.
let JSDOM;
try { ({ JSDOM } = require('jsdom')); } catch (e) {
  try { ({ JSDOM } = require(process.env.JSDOM_PATH || 'jsdom')); } catch (e2) { console.log('SKIP: jsdom not installed'); process.exit(0); }
}
const fs = require('fs');
const { makeIndexedDB } = require('./browser_mocks');
const SRC = fs.readFileSync(require('path').join(__dirname, '../../public/assets/js/offline-orders.js'), 'utf8');
let pass = 0, fail = 0;
const check = (n, ok, d) => { ok ? pass++ : fail++; console.log((ok ? 'PASS ' : 'FAIL ') + n + (ok ? '' : '  ' + (d || ''))); };
const wait = (ms) => new Promise(r => setTimeout(r, ms));

const HTML = `<!doctype html><body><main class="t-main">
  <form method="post" id="cat"><input name="name" value="Drinks"><input type="hidden" name="action" value="create">
    <button type="submit" name="op" value="save" id="catSave">Save</button></form>
  <form method="post" id="del" onsubmit="return confirm('Delete?')"><input type="hidden" name="id" value="7">
    <button name="action" value="delete" id="delBtn">Delete</button></form>
  <form method="post" id="upload" enctype="multipart/form-data"><input type="file" name="logo"><button id="upBtn">Upload</button></form>
  <form method="post" id="danger" data-online-only><button id="dangerBtn">Wipe</button></form>
  <form method="get" id="filter"><input name="q"></form>
  <form method="post" id="auto"><input name="x" value="1"></form>
</main></body>`;

(async () => {
  let confirmAnswer = true, alerts = 0;
  const dom = new JSDOM(HTML, {
    url: 'https://shop.example/super/categories/', runScripts: 'dangerously', pretendToBeVisual: true,
  });
  const w = dom.window;
  w.indexedDB = makeIndexedDB();
  w.confirm = () => confirmAnswer;
  w.alert = () => { alerts++; };
  w.AppOnline = { isOnline: () => false, markOffline() {}, onChange() {} };
  w.fetch = () => { throw new Error('should not fetch while offline'); };
  w.eval(SRC);
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  const Q = w.OfflineOrders;
  const d = w.document;

  check('POST forms in the page are wired for offline', d.getElementById('cat').dataset.offlineWired === '1' && d.getElementById('auto').dataset.offlineWired === '1');
  check('online-only, file-upload and GET forms are left alone',
    !d.getElementById('danger').dataset.offlineWired && !d.getElementById('upload').dataset.offlineWired && !d.getElementById('filter').dataset.offlineWired);

  d.getElementById('catSave').click(); await wait(40);
  let p = await Q.pending();
  check('offline save is kept on the device', p.length === 1 && p[0].url === 'https://shop.example/super/categories/');
  check('…with the pressed button\'s value (op=save) and its fields', p[0].fields.op === 'save' && p[0].fields.name === 'Drinks' && p[0].fields.action === 'create', JSON.stringify(p[0].fields));
  check('…a one-off key so it is never recorded twice', /^[\w-]{8,}$/.test(p[0].fields._idem || ''));
  check('a small notice is shown (no blocking pop-up)', alerts === 0 && /Saved on this device/.test(d.body.textContent));

  confirmAnswer = false; d.getElementById('delBtn').click(); await wait(40);
  check('"Cancel" on a confirm question saves nothing', (await Q.pending()).length === 1);
  confirmAnswer = true; d.getElementById('delBtn').click(); await wait(40);
  p = await Q.pending();
  check('"OK" saves it, with action=delete from the button', p.length === 2 && p[1].fields.action === 'delete' && p[1].fields.id === '7', JSON.stringify(p[1] && p[1].fields));

  d.getElementById('auto').submit(); await wait(40);
  p = await Q.pending();
  check('form.submit() from page code also saves offline (not a "no internet" page)', p.length === 3 && p[2].fields.x === '1');

  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})();
