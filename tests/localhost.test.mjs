// Trade-Free Link Guardian 1.36.4 — gazde locale vs. nume care doar încep ca „localhost”/„10.”. Rulare: node localhost.test.mjs <dir>   (rețea blocată, file://)
import {createRequire} from "module"; const require = createRequire("/opt/node-tools/package.json"); const {chromium} = require("playwright");
let fails = 0; const check = (n, c, x) => { c ? console.log("  ok  " + n) : (fails++, console.log("FAIL  " + n + (x !== undefined ? "  -> " + JSON.stringify(x) : ""))); };
const b = await chromium.launch({executablePath: "/opt/pw-browsers/chromium"});
const ctx = await b.newContext({viewport: {width: 1280, height: 900}}); await ctx.route(/^https?:/, r => r.abort());
const p = await ctx.newPage(); const errs = []; p.on("pageerror", e => errs.push(String(e)));
await p.goto("file://" + process.argv[2] + "/index.html"); await p.waitForTimeout(500);
const urls = ["http://localhostile.com/x", "http://10.shop.com/", "http://localhost:8080/y", "http://192.168.1.5/z", "http://nas.local/w"];
await p.fill("#paste", urls.map(u => `<a href="${u}">a</a>`).join(" ")); await p.click("#addBtn"); await p.waitForTimeout(800);
const blocks = await p.evaluate(urls => { const t = document.querySelector("main").innerText; return urls.map((u, i) => { const a = t.indexOf(u + "\n"); const e = urls.map(v => t.indexOf(v + "\n", a + 1)).filter(x => x > a).sort((x, y) => x - y)[0] || a + 400; return t.slice(a, e); }); }, urls);
const flagged = blocks.map(x => /Uses http, not https|Folose.te http/i.test(x));
check("localhostile.com semnalat ca http", flagged[0], blocks[0]);
check("10.shop.com semnalat ca http", flagged[1], blocks[1]);
check("localhost nu e semnalat", !flagged[2], blocks[2]);
check("192.168.1.5 nu e semnalat", !flagged[3], blocks[3]);
check("nas.local nu e semnalat", !flagged[4], blocks[4]);
check("fără erori de pagină", errs.length === 0, errs);
await b.close(); console.log(fails ? fails + " eșecuri" : "toate trecute"); process.exit(fails ? 1 : 0);
