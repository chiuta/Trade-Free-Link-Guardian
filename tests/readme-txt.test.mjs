// Verificare structurală a readme.txt (format wordpress.org). NU înlocuiește validatorul oficial de pe wordpress.org.
import fs from "fs"; import path from "path"; import {fileURLToPath} from "url";
const d = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const rd = fs.readFileSync(path.join(d, "readme.txt"), "utf8"), php = fs.readFileSync(path.join(d, "trade-free-link-guardian.php"), "utf8");
let fails = 0; const ok = (n, c, x) => { console.log((c ? "  ok  " : "FAIL  ") + n + (!c && x !== undefined ? "  -> " + JSON.stringify(x) : "")); if (!c) fails++; };
const lines = rd.split("\n");
ok("prima linie este „=== Nume ===”", /^=== .+ ===$/.test(lines[0]), lines[0]);
const hdr = {}; for (const l of lines.slice(1, 20)) { const m = /^([A-Za-z ]+): (.+)$/.exec(l); if (m) hdr[m[1]] = m[2]; }
for (const k of ["Contributors", "Tags", "Requires at least", "Tested up to", "Requires PHP", "Stable tag", "License", "License URI"]) ok("antet " + k, !!hdr[k], Object.keys(hdr));
ok("cel mult 5 etichete (Tags)", (hdr["Tags"] || "").split(",").length <= 5, hdr["Tags"]);
ok("Stable tag = Version din antetul pluginului", new RegExp("^ \\* Version:\\s+" + (hdr["Stable tag"] || "?").replace(/\./g, "\\.") + "$", "m").test(php), [hdr["Stable tag"]]);
ok("TFLG_VERSION = Stable tag", php.includes("define( 'TFLG_VERSION', '" + hdr["Stable tag"] + "' );"));
ok("Requires/Tested/Requires PHP identice cu antetul pluginului", ["Requires at least", "Tested up to", "Requires PHP"].every(k => new RegExp("^ \\* " + k + ":\\s+" + hdr[k].replace(/\./g, "\\.") + "$", "m").test(php)));
const short = lines.slice(1, 25).find(l => l && !/^[A-Za-z ]+: /.test(l) && !l.startsWith("==="));
ok("descrierea scurtă ≤ 150 caractere", !!short && short.length <= 150, short && short.length);
const secs = lines.filter(l => /^== .+ ==$/.test(l));
for (const s of ["Description", "Installation", "Frequently Asked Questions", "Changelog"]) ok("secțiunea „" + s + "”", secs.includes("== " + s + " =="), secs);
const iChg = rd.indexOf("== Changelog =="), first = /= (\d+\.\d+\.\d+) =/.exec(rd.slice(iChg));
ok("primul element din Changelog = Stable tag", !!first && first[1] === hdr["Stable tag"], first && first[1]);
const iUp = rd.indexOf("== Upgrade Notice =="), up = /= (\d+\.\d+\.\d+) =/.exec(rd.slice(iUp));
ok("primul element din Upgrade Notice = Stable tag", !!up && up[1] === hdr["Stable tag"], up && up[1]);
const un = rd.slice(iUp, rd.indexOf("\n== ", iUp + 5)).split(/\n= /)[1] || "";
ok("nota de upgrade ≤ 300 caractere", un.split("\n").slice(1).join(" ").trim().length <= 300, un.length);
ok("nu există tab-uri sau linii cu spații la final în antet", !lines.slice(0, 12).some(l => /\s$/.test(l) || /\t/.test(l)));
ok("licența din readme.txt = licența din antetul pluginului", new RegExp("^ \\* License:\\s+" + hdr["License"] + "$", "m").test(php), hdr["License"]);
console.log(fails ? `\n${fails} eșuate` : "\ntoate reușite"); process.exit(fails ? 1 : 0);
