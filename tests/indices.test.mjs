// Poziția grupurilor de captură fără steagul d (motoare vechi): fallback-ul din index.html (blocul fff:idx) față de m.indices nativ.
// Se extrag din index.html blocul de funcții și cele patru expresii cu d; se compară pe mii de intrări aleatoare (semânță fixă).
// Rulare din directorul aplicației: node tests/indices.test.mjs
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const html = fs.readFileSync(path.join(HERE, '..', 'index.html'), 'utf8');
let ok = 0, bad = 0;
const check = (n, c, x) => { if (c) { ok++; console.log('  ok   ' + n); } else { bad++; console.log('  FAIL ' + n + (x !== undefined ? '  → ' + JSON.stringify(x) : '')); } };

const block = /\/\* fff:idx:start \*\/([\s\S]*?)\/\* fff:idx:end \*\//.exec(html);
check('blocul fff:idx există în index.html', !!block);
const H = new Function(block[1] + '\nreturn {grp, attrVal, mdDest, refDest, locInner};')();
const lit = (head, tail) => { const a = html.indexOf(head); const b = html.indexOf(tail, a); return new Function('return ' + html.slice(a + head.length, b))(); };
const RX = {
  attr: lit('var ar = ', ', am, hit'),
  md: lit('var mdRe = ', ';\n'),
  ref: lit('var refRe = ', ';\n'),
  loc: lit('var locRe = ', ';\n'),
};
check('expresiile cu d se extrag din sursă', Object.values(RX).every((r) => r instanceof RegExp && r.hasIndices));

// generator pseudo-aleator cu semânță (mulberry32)
let seed = 20261117;
const rnd = () => { seed |= 0; seed = (seed + 0x6D2B79F5) | 0; let t = Math.imul(seed ^ (seed >>> 15), 1 | seed); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
const pick = (a) => a[Math.floor(rnd() * a.length)];
const gen = (vocab, lo, hi) => { let s = ''; const n = lo + Math.floor(rnd() * (hi - lo + 1)); for (let i = 0; i < n; i++) s += pick(vocab); return s; };

const V = {
  attr: ['href', 'src', 'alt', 'data-href', 'HREF', '=', '= ', ' =', ' ', '  ', '\t', '\n', '"', "'", 'a', 'b c', 'x-y', ':', '&amp;', '/', '?q=1', '#f', ' ', 'é', '"u r l"', "'u'", '""', "''"],
  md: ['[', ']', '(', ')', '!', '<', '>', ' ', '  ', '\t', ' ', '"t"', "'t'", '"t w"', "'t w'", 'a', 'b c', 'http://x.y/z_(w)', '\n', '[a]', '[a [b] c]', '](', '](  ', ' "x y")', '<u r l>', '<>', '()', '[]('],
  ref: ['[a]:', '[a b]:', ' ', '  ', '   ', '    ', '\t', ':', '<u>', '<u r>', '<>', 'http://x', 'u', '[', ']', '\n', '[x]: y\n', ' '],
  loc: ['<loc>', '</loc>', ' ', '\n', '\t', ' ', 'http://x/y', 'a b', '<', '>', '&amp;', '<loc></loc>', '<loc> </loc>'],
};
const GROUPS = {   // care grup și ce funcție de deducere
  attr: (m) => [m[2] !== undefined ? 2 : m[3] !== undefined ? 3 : 4, H.attrVal],
  md: () => [2, H.mdDest],
  ref: () => [2, H.refDest],
  loc: () => [1, H.locInner],
};
for (const name of ['attr', 'md', 'ref', 'loc']) {
  const re = RX[name];
  let strings = 0, matches = 0, diffs = [], nonEmpty = 0, empty = 0;
  for (let i = 0; i < 120000; i++) {
    const s = gen(V[name], 1, 14);
    strings++;
    re.lastIndex = 0;
    let m, guard = 0;
    while ((m = re.exec(s)) && guard++ < 50) {
      const [k, how] = GROUPS[name](m);
      const nat = m.indices[k];
      delete m.indices;                       // motor fără d
      const got = H.grp(m, k, how);
      matches++; if (m[k] === '') empty++; else nonEmpty++;
      if (got[0] !== nat[0] || got[1] !== nat[1]) { if (diffs.length < 3) diffs.push({s, k, nat, got, m0: m[0]}); }
      if (m[0] === '') re.lastIndex++;
    }
  }
  check(`${name}: ${matches} potriviri (${nonEmpty} nevide, ${empty} goale) din ${strings} intrări — pozițiile deduse = cele native`, diffs.length === 0 && matches > 5000, diffs);
}

// cazuri fixe
const fix = (re, s, k, how, want) => { re.lastIndex = 0; const m = re.exec(s); const nat = m.indices[k]; delete m.indices; const got = H.grp(m, k, how); return got[0] === want[0] && got[1] === want[1] && nat[0] === want[0] && nat[1] === want[1]; };
check('atribut: ghilimele duble / simple / fără ghilimele', fix(RX.attr, 'href = "u&amp;v"', 2, H.attrVal, [8, 15]) && fix(RX.attr, "alt='a b'", 3, H.attrVal, [5, 8]) && fix(RX.attr, 'src=/p/q.png', 4, H.attrVal, [4, 12]) && fix(RX.attr, 'href=""', 2, H.attrVal, [6, 6]));
check('md: [x]( <u r> "t") și [x](u)', fix(RX.md, 'zz [x](  <u r> "t") q', 2, H.mdDest, [9, 14]) && fix(RX.md, '![i](u)', 2, H.mdDest, [5, 6]));
check('md: destinație goală urmată de titlu', fix(RX.md, '[a]( "t w")', 2, H.mdDest, [4, 4]) && fix(RX.md, '[a]( )', 2, H.mdDest, [5, 5]) && fix(RX.md, '[a]()', 2, H.mdDest, [4, 4]));
check('ref: spații/tab înainte de destinație', fix(RX.ref, '  [e f]:\t <u>', 2, H.refDest, [10, 13]));
check('loc: spațiu alb în jurul adresei', fix(RX.loc, '<loc>\n  http://x/y \n</loc>', 1, H.locInner, [8, 18]) && fix(RX.loc, '<loc>  </loc>', 1, H.locInner, [7, 7]));

console.log(`\nindices: ${ok} reușite, ${bad} eșuate`);
process.exit(bad ? 1 : 0);
