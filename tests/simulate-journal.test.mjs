// Teste v2.0.0 (aplicația HTML): simulare de lot cu diferențe pe document, diferențe după aplicare, anulare pe document,
// export de jurnal, confirmare la „reanalizează”. Rulare din directorul aplicației: node tests/simulate-journal.test.mjs
// [fff-portable] axe-core: FFF_AXE / AXE_PATH, apoi pachetul `axe-core`.
import {createRequire as __fffCR} from 'node:module';
import {readFileSync as __fffRF} from 'node:fs';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {createRequire} from 'node:module';
const __fffAxe = () => process.env.FFF_AXE || process.env.AXE_PATH || (() => { try { return __fffCR(import.meta.url).resolve('axe-core/axe.min.js'); } catch { return undefined; } })();
const APP = JSON.parse(__fffRF(new URL('../app.json', import.meta.url), 'utf8'));
const require = createRequire(process.env.PW_ROOT || import.meta.url);
const {chromium} = require('playwright');
const HERE = path.dirname(fileURLToPath(import.meta.url));
const FILE = 'file://' + path.join(HERE, '..', 'index.html');
const AXE = __fffAxe();
let ok = 0, bad = 0;
const check = (n, c, x) => { if (c) { ok++; console.log('  ok   ' + n); } else { bad++; console.log('  FAIL ' + n + (x !== undefined ? '  → ' + JSON.stringify(x) : '')); } };
const TMP = fs.mkdtempSync(path.join(process.env.FFF_TMP || os.tmpdir(), 'tflg2-'));
const b = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--no-sandbox']});
async function open(o = {}) {
  const ctx = await b.newContext({viewport: {width: o.w || 1280, height: 900}, acceptDownloads: true, locale: 'en-US', ...(o.bypass ? {bypassCSP: true} : {})});
  const net = [];
  await ctx.route(/^https?:/, (r) => { net.push(r.request().url()); r.abort(); });
  const p = await ctx.newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push('pageerror: ' + e.message));
  p.on('console', (m) => { const t = m.text(); if (/Content Security Policy|Refused to/i.test(t)) errs.push('csp: ' + t); });
  await p.goto(FILE); await p.waitForTimeout(400);
  return {ctx, p, errs, net};
}
const txt = (p, sel) => p.evaluate((s) => document.querySelector(s).innerText, sel);
const dl = async (p, sel) => { const [d] = await Promise.all([p.waitForEvent('download'), p.click(sel)]); const f = path.join(TMP, d.suggestedFilename()); await d.saveAs(f); return {name: d.suggestedFilename(), text: fs.readFileSync(f, 'utf8')}; };
// normalizează intrările afișate (linie + înainte + după) dintr-un container
const entries = (p, sel) => p.evaluate((s) => [...document.querySelectorAll(s + ' .chg li')].map((li) => {
  const c = li.querySelectorAll('code'); return li.querySelector('span').innerText.split(' · ')[0] + '|' + c[0].innerText.slice(2) + '|' + c[1].innerText.slice(2);
}), sel);
const logLen = (p) => p.evaluate(() => document.querySelectorAll('#logList li').length);

{
  const {ctx, p, errs, net} = await open();
  console.log('— pornire');
  check('versiune ' + APP.version, await p.evaluate((v) => document.querySelector('#app-version').textContent.includes(v) && document.querySelector('.ver').textContent === 'v' + v, APP.version));
  await p.click('#demoBtn'); await p.waitForTimeout(500);
  const original = await p.inputValue('#outTa');
  check('simularea e ascunsă până o ceri', await p.evaluate(() => document.getElementById('simCard').hidden));
  check('fără erori', errs.length === 0, errs);

  console.log('— simulare: nu aplică nimic');
  await p.click('#simBtn');
  check('cardul de simulare apare', await p.evaluate(() => !document.getElementById('simCard').hidden));
  const info = await txt(p, '#simInfo');
  const m = info.match(/^(\d+) changes in (\d+) documents would be made/);
  check('rezumat „N schimbări în M documente” (M = 2)', m && +m[1] > 5 && +m[2] === 2, info);
  const simEnt = await entries(p, '#simBody');
  check('numărul de intrări afișate = N din rezumat', simEnt.length === +m[1], [simEnt.length, m[1]]);
  check('fiecare intrare are înainte ≠ după', simEnt.every((e) => { const [, a, c] = e.split('|'); return a !== c && a.length && c.length; }), simEnt.slice(0, 3));
  check('jurnalul rămâne gol, nimic acceptat', await logLen(p) === 0 && await p.evaluate(() => document.getElementById('undoAll').disabled));
  check('documentul corectat e neschimbat de simulare', (await p.inputValue('#outTa')) === original);
  check('butonul „Aplică” arată numărul de corecturi', /^Apply this batch \(\d+\)$/.test(await txt(p, '#simApply')), await txt(p, '#simApply'));
  const groups = await p.$$eval('#simBody .dgrp > summary', (l) => l.map((x) => x.innerText));
  check('grupat pe documente (demo-page.html, demo-notes.md)', groups.length === 2 && groups.some((g) => /demo-page\.html: \d+ changes/.test(g)) && groups.some((g) => /demo-notes\.md: \d+ changes/.test(g)), groups);

  console.log('— simularea respectă tipul ales în lot');
  const types = await p.$$eval('#bulkType option', (o) => o.map((x) => x.value).filter(Boolean));
  check('există mai multe tipuri de corecturi', types.length >= 2, types);
  await p.selectOption('#bulkType', types[0]);
  await p.click('#simBtn');
  const one = await txt(p, '#simInfo');
  const labels = await p.$$eval('#simBody .chg li > span', (l) => [...new Set(l.map((x) => x.innerText.split(' · ').slice(1).join(' · ')))]);
  check('un singur tip: mai puține schimbări decât „toate”', +one.match(/^(\d+)/)[1] <= +m[1] && labels.length >= 1, [one, labels]);
  await p.click('#simCancel');
  check('„Închide” ascunde cardul și nu aplică', await p.evaluate(() => document.getElementById('simCard').hidden) && await logLen(p) === 0);
  await p.selectOption('#bulkType', '');

  console.log('— aplicare după simulare');
  await p.click('#simBtn');
  const before = await entries(p, '#simBody');
  await p.click('#simApply');
  await p.waitForTimeout(300);
  check('cardul se închide după aplicare', await p.evaluate(() => document.getElementById('simCard').hidden));
  const nLog = await logLen(p);
  check('jurnalul are intrări (una per corectură)', nLog >= before.length, [nLog, before.length]);
  await p.click('#diffBtn');
  const dEnt = await entries(p, '#diffBox');
  const outSrcName = await p.evaluate(() => document.getElementById('outSel').selectedOptions[0].textContent);
  check('diferențele afișate pentru documentul ales sunt un subset al celor simulate', dEnt.length > 0 && dEnt.every((e) => before.includes(e)), [dEnt.slice(0, 2), before.slice(0, 2)]);
  await p.selectOption('#outSel', {index: 1});
  const dEnt2 = await entries(p, '#diffBox');
  check('cele două documente împreună acoperă exact simularea', [...new Set([...dEnt, ...dEnt2])].sort().join('\n') === [...new Set(before)].sort().join('\n'), [dEnt.length, dEnt2.length, before.length]);
  const after = await p.inputValue('#outTa');
  check('documentul corectat s-a schimbat față de original', after !== original || (await p.evaluate(() => document.getElementById('outSel').selectedIndex)) === 1);
  const all2 = [];
  for (const i of [0, 1]) { await p.selectOption('#outSel', {index: i}); all2.push(await p.inputValue('#outTa')); }
  check('fiecare „după” simulat apare în documentul corectat', before.every((e) => { const a = e.split('|')[2]; return all2.some((t) => t.includes(a) || t.includes(a.replace(/&/g, '&amp;'))); }));

  console.log('— anulare pe document');
  await p.selectOption('#outSel', {index: 0});
  const logBefore = await logLen(p);
  const srcName0 = await p.evaluate(() => document.getElementById('outSel').selectedOptions[0].textContent);
  await p.click('#undoSrcBtn');
  await p.waitForTimeout(200);
  const logAfter = await logLen(p);
  check('se anulează doar intrările documentului ales', logAfter < logBefore && logAfter > 0, [logBefore, logAfter]);
  check('mesaj „anulate în <document>”', new RegExp('entries undone in ' + srcName0.replace(/\./g, '\\.')).test(await txt(p, '#status')), await txt(p, '#status'));
  check('diferențele documentului sunt acum goale', /No accepted fixes in this document yet/.test(await txt(p, '#diffBox')));
  await p.click('#undoSrcBtn');
  check('a doua anulare: „nimic de anulat”', /Nothing to undo in this document/.test(await txt(p, '#status')));
  const other = await p.evaluate(() => document.getElementById('outSel').selectedIndex);
  await p.selectOption('#outSel', {index: 1 - other});
  check('celălalt document păstrează corecturile', (await entries(p, '#diffBox')).length > 0);
  await p.click('#diffBtn');
  check('„Ascunde diferențele” închide panoul', await p.evaluate(() => document.getElementById('diffBox').hidden));

  console.log('— export jurnal');
  const j = await dl(p, '#logExport');
  const jj = JSON.parse(j.text);
  check('nume tflg-journal-*.json', /^tflg-journal-.*\.json$/.test(j.name), j.name);
  check('jurnalul exportat = jurnalul din pagină', jj.entries.length === await logLen(p) && jj.version === APP.version && jj.tool === 'Trade-Free Link Guardian', [jj.entries.length, jj.version]);
  check('intrările au sursă, linie, înainte și după', jj.entries.every((e) => e.source && e.line > 0 && e.before && e.after && ['accept', 'ignore'].includes(e.action)), jj.entries[0]);
  await p.click('#undoAll');
  await p.click('#logExport');
  check('fără intrări: mesaj, nu fișier gol', /No journal entries to export/.test(await txt(p, '#status')));

  console.log('— reanalizare cu confirmare');
  await p.click('#bulkBtn'); await p.waitForTimeout(200);
  const srcBefore = await p.evaluate(() => document.querySelectorAll('#srcList li').length);
  const nL = await logLen(p);
  await p.click('#reanBtn');
  await p.waitForSelector('dialog[open], .fff-dlg', {timeout: 3000});
  check('apare confirmare care numește ștergerea istoricului', /clears the undo history/.test(await p.evaluate(() => (document.querySelector('dialog[open]') || document.querySelector('.fff-dlg')).innerText)));
  await p.getByRole('button', {name: 'Cancel'}).click();
  await p.waitForTimeout(150);
  check('Anulează păstrează jurnalul', await logLen(p) === nL && nL > 0);
  await p.click('#reanBtn');
  await p.getByRole('button', {name: 'Yes'}).click();
  await p.waitForTimeout(300);
  check('Da: sursele devin textul corectat, jurnalul se golește', await logLen(p) === 0 && await p.evaluate(() => document.querySelectorAll('#srcList li').length) === srcBefore);

  console.log('— rețea și limbi');
  check('nicio cerere de rețea', net.length === 0, net);
  check('fără erori', errs.length === 0, errs);
  await p.click('#bulkBtn'); await p.click('#simBtn'); await p.click('#diffBtn');
  for (const l of ['ro', 'fr', 'it', 'es', 'pt', 'de', 'hu', 'en']) {
    await p.selectOption('#langBtn', l); await p.waitForTimeout(80);
    const body = await p.evaluate(() => document.body.innerText);
    const raw = body.match(/\b(sim|diff|undo|log|rean)\.[a-z]+\b/g);
    const hh = await txt(p, '#simH');
    check(l + ': fără chei netraduse, titlu simulare tradus', !raw && hh.length > 8 && (l === 'en' || hh !== 'Simulation: nothing has been applied'), [raw, hh]);
  }
  await ctx.close();
}

console.log('— accesibilitate și mobil');
if (AXE) {
  const {ctx, p} = await open({bypass: true});
  await p.click('#demoBtn'); await p.waitForTimeout(400);
  await p.click('#simBtn'); await p.waitForTimeout(100);
  await p.addScriptTag({path: AXE});
  const v1 = await p.evaluate(async () => (await axe.run(document, {runOnly: ['wcag2a', 'wcag2aa']})).violations.flatMap((x) => x.nodes.map((n) => x.id + ' ' + n.target.join(' '))));
  check('axe fără încălcări cu simularea deschisă', v1.length === 0, v1);
  await p.click('#simApply'); await p.click('#diffBtn'); await p.waitForTimeout(100);
  const v2 = await p.evaluate(async () => (await axe.run(document, {runOnly: ['wcag2a', 'wcag2aa']})).violations.flatMap((x) => x.nodes.map((n) => x.id + ' ' + n.target.join(' '))));
  check('axe fără încălcări cu diferențele deschise', v2.length === 0, v2);
  await ctx.close();
  const m = await open({w: 360});
  await m.p.click('#demoBtn'); await m.p.waitForTimeout(400); await m.p.click('#simBtn');
  check('fără defilare orizontală la 360px', await m.p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), await m.p.evaluate(() => document.documentElement.scrollWidth));
  await m.ctx.close();
} else check('axe lipsă (omis)', true);

await b.close();
console.log('\n' + ok + ' ok, ' + bad + ' eșuate');
process.exit(bad ? 1 : 0);
