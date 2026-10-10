# Contribuind la Trade-Free Link Guardian

Mulțumesc că iei în calcul o contribuție. Câteva lucruri utile de știut înainte să începi.

## Structura fișierului unic — deliberată, nu accidentală

Tot codul plugin-ului stă într-un singur fișier PHP, `trade-free-link-guardian.php`. Asta nu e o alegere de moment, e filozofia proiectului: „without borders" — fără pas de build, fără dependințe externe de instalat, funcțional doar copiind un singur fișier. Face parte dintr-un portofoliu mai larg de aplicații single-file construite pe același principiu.

Dacă vii cu experiență de dezvoltare WordPress modernă (namespace-uri, autoloading, fișiere separate per clasă), structura poate părea neobișnuită. E intenționată, nu o datorie tehnică de rezolvat printr-un refactor mare. O contribuție care propune despărțirea fișierului în mai multe nu va fi acceptată doar pe acest motiv — dar o contribuție care îmbunătățește ceva *în interiorul* acestei structuri (o funcție nouă, o corectare, un test suplimentar) e binevenită.

## Orientare rapidă în fișier

La începutul fișierului există un cuprins („TABLE OF CONTENTS") — fiecare clasă are un text exact de căutare (de exemplu `SECTION: TFLG_Checker`) pe care-l poți căuta (Ctrl+F / Cmd+F) în orice editor pentru a ajunge direct acolo. Preferat în locul numerelor de linie, care s-ar învechi la orice modificare.

Cele treisprezece clase, pe scurt:
- `TFLG_Util` — funcții ajutătoare fără stare, folosite peste tot
- `TFLG_DB` — schema și toate interogările directe către baza de date
- `TFLG_Scanner` — extrage linkuri din conținut
- `TFLG_Checker` — face cererea HTTP care decide dacă un link e viu
- `TFLG_Fixer` — cele șase corectări deterministe, aplicare/anulare
- `TFLG_List_Table` — tabelul ecranului Links (doar admin)
- `TFLG_Admin` — HTML-ul fiecărei pagini admin, meniul, CSS/JS
- `TFLG_Ajax` — fiecare punct final `wp_ajax_`
- `TFLG_Network_Admin` — vizualizarea la nivel de rețea, doar multisite
- `TFLG_Editor_Check` — avertismentul la salvarea unei postări
- `TFLG_404_Monitor` — jurnalul pasiv de erori 404
- `TFLG_Cron` — scanarea programată opțională
- `TFLG_Plugin` — activare, dezactivare, dezinstalare, constructorul care leagă totul

## Testare — obligatorie, nu opțională

Fiecare funcție nouă sau corectare trebuie însoțită de teste. Testele existente nu rulează prin PHPUnit clasic instalat ca dependință — sunt scripturi PHP autonome care extrag codul real din fișierul principal (prin numărare de acolade, nu presupuneri de text) și-l rulează izolat, cu stub-uri minime pentru funcțiile WordPress necesare. Pentru logica JavaScript, testele reale rulează cu Node.js — cele care ating manipulare DOM (de exemplu sortarea din Book View) folosesc `jsdom` pentru un mediu DOM real, nu simulat superficial.

Un test care doar confirmă ce te aștepți deja să se întâmple nu ajută mult. Cele mai utile teste din acest proiect sunt cele care verifică exact cazul limită ușor de greșit — de exemplu, că o funcție de previzualizare nu scrie niciodată în baza de date, sau că un tabel de conținut rămâne sincronizat cu conținutul real după o reordonare.

## Securitate — verificare directă, nu presupunere

Orice interogare SQL nouă trebuie să folosească `$wpdb->prepare()` cu parametri reali, nu interpolare de șiruri. Orice acțiune care schimbă starea (AJAX, formular) trebuie să verifice atât capacitatea (`current_user_can`) cât și un nonce. Orice conținut afișat care ar putea veni dintr-o sursă mai puțin de încredere decât un administrator — titlul unei pagini externe, un antet HTTP trimis de un vizitator — trebuie escapat la afișare, nu doar curățat la intrare.

## Fără telemetrie, fără excepții

Nimic din acest plugin nu trimite date către servicii externe fără acțiunea explicită a utilizatorului. O contribuție care adaugă orice formă de raportare automată către un server extern, oricât de bine intenționată, nu va fi acceptată.

## Cum trimiți o contribuție

Proiectul urmează să fie publicat pe WordPress.org, folosind fluxul standard SVN al platformei. Pentru contribuții de cod, cel mai simplu e să descrii clar modificarea propusă și motivul ei, împreună cu testele corespunzătoare, către autor direct (vezi datele de contact din antetul plugin-ului sau din readme.txt).
