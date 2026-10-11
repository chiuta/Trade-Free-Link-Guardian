# Trade-Free Link Guardian

> **Plugin WordPress** — nu are interfață autonomă, deci nu există un demo live. Captura de ecran a panoului de administrare se adaugă după testul într-un WordPress real.

![Trade-Free Link Guardian — banner](.wordpress-org/banner-1544x500.png)


Plugin WordPress care găsește linkurile moarte din articole, pagini, meniuri și widgeturi și repară ce se poate repara determinist — rulează pe serverul tău, fără cont SaaS și fără telemetrie.

**Versiune:** 1.33.1 · **Licență:** GPL-2.0-sau-ulterioară · **Cerințe:** WordPress 6.5+, PHP 7.4+

## Ce este

Un verificator local de linkuri. Linkurile interne se verifică structural (baza de date și sistemul de fișiere), cele externe printr-o cerere HTTP trimisă direct de serverul tău către destinația linkului. Plugin-ul este un singur fișier PHP (`trade-free-link-guardian.php`), fără pas de build și fără dependențe împachetate — filozofia „fără frontiere" / trade-free.

## Funcții (verificate în cod)

- Scanare: articole, pagini și tipuri de conținut publice alese, meniuri clasice și de bloc, widgeturi, opțional imagini; extragere atât din HTML, cât și din atributele blocurilor.
- Verificare: HEAD cu revenire la GET `Range: bytes=0-0`; redirecturi urmate pas cu pas (max. 5), distingând permanent (301/308) de temporar; verificări concurente (implicit 20); un URL unic se verifică o singură dată pe scanare.
- Corecturi automate (fiecare comutabilă în Setări): `http`→`https`, comutare `www.`, redirect permanent urmat până la destinația finală, slug redenumit (`_wp_old_slug`), alte tipare deterministe.
- Corecturi manuale: înlocuire URL, scoaterea linkului (se păstrează textul/imaginea), restaurare din coș, notă, ignorare.
- Jurnal de corecturi cu anulare (excepție: scoaterea linkului nu se anulează automat); „raport de propuneri" care nu modifică nimic.
- Monitor 404 și reguli locale de redirecționare 301/302/410 (potrivire exactă a căii).
- Programare WP-Cron (oprit/zilnic/săptămânal) cu e-mail de notificare; avertisment în editor pentru linkuri deja cunoscute ca moarte.
- Export CSV (cu protecție împotriva formulelor), raport de conformitate imprimabil, multisite.
- Opțional, implicit dezactivate: detectarea „drift"-ului de conținut, căutare în Wayback Machine, linkuri către archive.today și Memento.

## Manual de utilizare

1. Copiază folderul în `wp-content/plugins/` (sau instalează arhiva din **Plugin-uri → Adaugă → Încarcă**) și activează-l.
2. **Link Guardian → Setări**: alege tipurile de conținut și ce corecturi automate sunt permise.
3. **Link Guardian → Tablou de bord → Scanează acum**.
4. În **Linkuri**, filtrează după „Stricate"; pe fiecare rând: Re-verifică, Corectează automat, Editează URL, Scoate linkul, Ignoră.
5. Înainte de corecturi în masă, folosește raportul de propuneri; după aceea poți anula din **Jurnal corecturi**.
6. Fă o copie de siguranță a bazei de date înainte de primele corecturi în masă.

## Confidențialitate și rețea

- Singurele cereri HTTP ieșite: (1) verificarea linkurilor din propriul tău conținut (inclusiv drift-ul opțional, care descarcă cel mult 1 MiB din pagină); (2) opțional, `archive.org/wayback/available?url=...` la clic pe „Găsește versiune arhivată", doar dacă setarea este activă. Linkurile archive.today / Memento / Buy Me a Coffee sunt simple linkuri deschise de browserul tău la clic.
- Nu există actualizări proprii, analitice, licențe sau apeluri „phone-home".
- **User-Agent:** implicit plugin-ul trimite un User-Agent de browser Chrome (setare editabilă), nu unul care îl identifică; destinațiile nu află numele site-ului tău din antet. Poți seta unul onest, de forma `TradeFreeLinkGuardian/x.y (+site)`, în Setări.
- **Monitorul 404** rulează pe cererile vizitatorilor care ajung la 404 și, implicit, stochează calea cerută, antetul Referer (dacă există) și un indicator bot/om; nu stochează IP. Se șterge după 60 de zile și se poate opri din Setări. Dacă ai vizitatori în UE, menționează-l în politica de confidențialitate.
- Dezinstalarea (ștergerea din Plugin-uri) elimină tabelele, opțiunile și tranzientele create.

## Limite și avertismente

- Plugin-ul **modifică conținut real** (`post_content`, widgeturi, meniuri). Fă copii de siguranță. Scoaterea linkului nu se anulează automat; pentru un element de meniu clasic, scoaterea **șterge definitiv** elementul.
- Corecția „redirect permanent" rescrie linkul către unde duce redirectul unui site terț; dacă acel site a fost preluat de altcineva, linkul tău urmează destinația nouă. Dezactivează opțiunea dacă nu vrei acest risc.
- Un 403 înseamnă adesea blocarea botilor, nu un link mort.
- Versiunile anterioare lui 1.33.1 puteau șterge backslash-urile din conținutul articolelor modificate prin corecturi; pentru articole afectate folosește Revizuirile WordPress.
- Testat doar prin analiză de cod și `php -l`; nu a fost rulat într-o instalare WordPress în cadrul acestui audit.
- Textele interfeței sunt în engleză (fără fișiere de traducere incluse).

## Rulare locală

Nu există build. Pentru dezvoltare: `wp-content/plugins/trade-free-link-guardian/` + o instanță WordPress locală. Vezi `CONTRIBUTING.md`. Folderul `.wordpress-org/` conține iconițele și bannerele pentru directorul WordPress.org.

## Licență

GPL v2 sau ulterioară (vezi `LICENSE`).

## Autor

Alexio — Alexandru-Ionuț Chiuță · contact: alexio@trom.tf · https://trom.tf/

## English summary

A self-hosted WordPress broken-link checker and deterministic link fixer (single PHP file, WordPress 6.5+, PHP 7.4+). Every outbound request goes to a link in your own content; the only optional third-party call is a Wayback Machine lookup you trigger by hand. No telemetry, no SaaS. Version 1.33.1 fixes an SSRF gap in the concurrent checker and a backslash-stripping bug in content rewriting. It edits real post content: keep backups.
