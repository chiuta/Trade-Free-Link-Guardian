# Jurnal de modificări

Format SemVer (MAJOR.MINOR.PATCH). Versiunea de aici este a aplicației web / depozitului; pluginul WordPress are versiune proprie (1.33.1) în `readme.txt`.

## 2.1.0 — 2026-10-27

Anterior: v2.0.1. Aplicația web nu s-a schimbat funcțional (doar versiunea); se schimbă pluginul WordPress: 1.33.1 → 1.34.0.

- Plugin 1.34.0: e-mailul de notificare după o scanare programată listează acum până la 10 dintre URL-urile stricate (apoi „... and N more”), înainte de linkul către lista completă. Din fiecare URL se elimină caracterele de control (inclusiv CR/LF) și URL-urile mai lungi de 200 de caractere se scurtează. Fără URL-uri de listat, mesajul rămâne ca înainte. Nicio setare, tabelă sau opțiune nouă.
- `readme.txt` (format wordpress.org): `Stable tag` 1.34.0, intrări noi în Changelog și Upgrade Notice. Test structural nou `tests/readme-txt.test.mjs` (antete, `Stable tag` = `Version` din plugin, descriere scurtă ≤ 150, secțiuni, nota de upgrade ≤ 300). NU înlocuiește validatorul oficial de pe wordpress.org.
- Teste noi: `tests/mail.test.php` (8 verificări ale textului mesajului, rulat cu `php`, fără WordPress) și `tests/readme-txt.test.mjs`. `php -l` fără erori. Existente: `simulate-journal` 44 ok, `localhost` trecut.
- Neimplementat / netestat: `wp_mail()`, WP-Cron și interogarea din baza de date NU au rulat pe un WordPress real; `Tested up to: 7.1` rămâne declarație neconfirmată; Plugin Check, existența utilizatorului wordpress.org `alexio` și publicarea pe WordPress.org rămân ale proprietarului.

## 2.0.1 — 2026-10-27

Anterior: v2.0.0. Doar texte; codul pluginului și al aplicației nu s-au schimbat.

- `readme.txt` (format wordpress.org): FAQ nou — versiuni WordPress/PHP compatibile (declarate, `Tested up to` de verificat), programare WP-Cron, raport pe e-mail, servicii externe.
- README (EN/RO): secțiunea „Compatibilitate, programare și raport pe e-mail”, linii „Stare” și „Offline”.
- Nu s-a publicat nimic pe WordPress.org.
