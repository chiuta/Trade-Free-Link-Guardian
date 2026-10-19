# Közreműködés a Trade-Free Link Guardian fejlesztésében

Köszönöm, hogy megfontolod a közreműködést. Néhány hasznos tudnivaló, mielőtt belekezdenél.

## Az egyfájlos szerkezet — szándékos, nem véletlen

A bővítmény teljes kódja egyetlen PHP-fájlban van, a `trade-free-link-guardian.php`-ban. Ez nem pillanatnyi döntés, hanem a projekt filozófiája: „határok nélkül” — nincs build lépés, nincs telepítendő külső függőség, egyetlen fájl másolásával működik. Egy tágabb, ugyanezen az elven épülő egyfájlos alkalmazás-portfólió része.

Ha modern WordPress-fejlesztési tapasztalattal érkezel (névterek, automatikus betöltés, osztályonként külön fájl), a szerkezet szokatlannak tűnhet. Szándékos, nem műszaki adósság, amelyet nagy refaktorálással kellene rendezni. Az a közreműködés, amely a fájl több részre bontását javasolja, pusztán emiatt nem kerül elfogadásra — de az a közreműködés, amely valamit ennek a szerkezetnek a *belsejében* javít (új függvény, javítás, további teszt), szívesen látott.

## Gyors tájékozódás a fájlban

A fájl elején tartalomjegyzék található („TABLE OF CONTENTS”) — minden osztályhoz tartozik egy pontos keresőszöveg (például `SECTION: TFLG_Checker`), amelyre bármely szerkesztőben rákereshetsz (Ctrl+F / Cmd+F), hogy közvetlenül odaugorj. Ez előnyösebb a sorszámoknál, amelyek bármilyen módosításnál elavulnának.

A tizenhárom osztály dióhéjban:
- `TFLG_Util` — állapot nélküli segédfüggvények, mindenhol használatosak
- `TFLG_DB` — a séma és az összes közvetlen adatbázis-lekérdezés
- `TFLG_Scanner` — linkeket nyer ki a tartalomból
- `TFLG_Checker` — elvégzi a HTTP-kérést, amely eldönti, hogy él-e a link
- `TFLG_Fixer` — a hat determinisztikus javítás, alkalmazás/visszavonás
- `TFLG_List_Table` — a Links képernyő táblázata (csak admin)
- `TFLG_Admin` — minden admin oldal HTML-je, a menü, CSS/JS
- `TFLG_Ajax` — minden `wp_ajax_` végpont
- `TFLG_Network_Admin` — a hálózati szintű nézet, csak multisite
- `TFLG_Editor_Check` — a figyelmeztetés bejegyzés mentésekor
- `TFLG_404_Monitor` — a passzív 404-hibanapló
- `TFLG_Cron` — az opcionális ütemezett vizsgálat
- `TFLG_Plugin` — aktiválás, deaktiválás, eltávolítás, a mindent összekötő konstruktor

## Tesztelés — kötelező, nem opcionális

Minden új függvényhez vagy javításhoz tesztnek kell tartoznia. A meglévő tesztek nem függőségként telepített klasszikus PHPUnitban futnak — önálló PHP-szkriptek, amelyek a főfájlból kinyerik a valódi kódot (a kapcsos zárójelek számolásával, nem szövegfeltételezéssel), és elszigetelten futtatják, a szükséges WordPress-függvények minimális csonkjaival. A JavaScript-logikához a valódi tesztek Node.js-sel futnak — azok, amelyek DOM-manipulációt érintenek (például a Book View rendezése), a `jsdom`-ot használják valódi DOM-környezethez, nem felszínes szimulációhoz.

Az a teszt, amely csak azt igazolja, amit amúgy is vársz, nem sokat segít. Ebben a projektben a leghasznosabbak azok a tesztek, amelyek pontosan a könnyen elrontható határesetet ellenőrzik — például hogy egy előnézeti függvény soha nem ír az adatbázisba, vagy hogy egy tartalomjegyzék átrendezés után szinkronban marad a tényleges tartalommal.

## Biztonság — közvetlen ellenőrzés, nem feltételezés

Minden új SQL-lekérdezésnek a `$wpdb->prepare()`-t kell használnia valódi paraméterekkel, nem sztring-interpolációt. Minden állapotot módosító műveletnek (AJAX, űrlap) a jogosultságot (`current_user_can`) és egy noncet is ellenőriznie kell. Minden megjelenített tartalmat, amely egy adminisztrátornál kevésbé megbízható forrásból származhat — egy külső oldal címe, egy látogató által küldött HTTP-fejléc —, kimenetkor escape-elni kell, nem csak bemenetkor megtisztítani.

## Nincs telemetria, kivétel nélkül

A bővítményben semmi sem küld adatot külső szolgáltatásoknak a felhasználó kifejezett művelete nélkül. Az a közreműködés, amely bármilyen automatikus jelentést ad hozzá egy külső szerver felé, bármilyen jó szándékú is, nem kerül elfogadásra.

## Hogyan küldj be közreműködést

A projekt a WordPress.org-on lesz közzétéve, a platform szokásos SVN-munkafolyamatával. Kódközreműködéshez a legegyszerűbb, ha a javasolt módosítást és annak indokát világosan leírod, a megfelelő tesztekkel együtt, közvetlenül a szerzőnek (lásd a kapcsolati adatokat a bővítmény fejlécében vagy a readme.txt-ben).
