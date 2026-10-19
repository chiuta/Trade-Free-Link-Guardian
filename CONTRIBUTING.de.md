# Zu Trade-Free Link Guardian beitragen

Danke, dass du einen Beitrag in Betracht ziehst. Ein paar nützliche Dinge, die du vor dem Start wissen solltest.

## Die Ein-Datei-Struktur — bewusst, nicht zufällig

Der gesamte Code des Plugins liegt in einer einzigen PHP-Datei, `trade-free-link-guardian.php`. Das ist keine spontane Entscheidung, sondern die Philosophie des Projekts: „ohne Grenzen“ — kein Build-Schritt, keine externen Abhängigkeiten zu installieren, funktionsfähig allein durch Kopieren einer einzigen Datei. Es ist Teil eines größeren Portfolios von Single-File-Anwendungen, die auf demselben Prinzip beruhen.

Wenn du mit Erfahrung in moderner WordPress-Entwicklung kommst (Namespaces, Autoloading, eine eigene Datei pro Klasse), kann die Struktur ungewöhnlich wirken. Sie ist beabsichtigt, keine technische Schuld, die durch ein großes Refactoring zu beheben wäre. Ein Beitrag, der vorschlägt, die Datei in mehrere aufzuteilen, wird allein aus diesem Grund nicht angenommen — aber ein Beitrag, der etwas *innerhalb* dieser Struktur verbessert (eine neue Funktion, eine Korrektur, ein zusätzlicher Test), ist willkommen.

## Schnelle Orientierung in der Datei

Am Anfang der Datei steht ein Inhaltsverzeichnis („TABLE OF CONTENTS“) — jede Klasse hat einen exakten Suchtext (zum Beispiel `SECTION: TFLG_Checker`), den du in jedem Editor suchen kannst (Strg+F / Cmd+F), um direkt dorthin zu springen. Das ist Zeilennummern vorzuziehen, die bei jeder Änderung veralten würden.

Die dreizehn Klassen in Kürze:
- `TFLG_Util` — zustandslose Hilfsfunktionen, überall verwendet
- `TFLG_DB` — das Schema und alle direkten Datenbankabfragen
- `TFLG_Scanner` — extrahiert Links aus Inhalten
- `TFLG_Checker` — führt die HTTP-Anfrage aus, die entscheidet, ob ein Link lebt
- `TFLG_Fixer` — die sechs deterministischen Korrekturen, Anwenden/Rückgängig
- `TFLG_List_Table` — die Tabelle des Links-Bildschirms (nur Admin)
- `TFLG_Admin` — das HTML jeder Admin-Seite, das Menü, CSS/JS
- `TFLG_Ajax` — jeder `wp_ajax_`-Endpunkt
- `TFLG_Network_Admin` — die netzwerkweite Ansicht, nur Multisite
- `TFLG_Editor_Check` — die Warnung beim Speichern eines Beitrags
- `TFLG_404_Monitor` — das passive 404-Fehlerprotokoll
- `TFLG_Cron` — der optionale geplante Scan
- `TFLG_Plugin` — Aktivierung, Deaktivierung, Deinstallation, der Konstruktor, der alles verbindet

## Tests — Pflicht, nicht optional

Jede neue Funktion und jede Korrektur muss von Tests begleitet werden. Die vorhandenen Tests laufen nicht über ein klassisches, als Abhängigkeit installiertes PHPUnit — es sind eigenständige PHP-Skripte, die den echten Code aus der Hauptdatei extrahieren (durch Zählen der geschweiften Klammern, nicht durch Textannahmen) und isoliert ausführen, mit minimalen Stubs für die benötigten WordPress-Funktionen. Für die JavaScript-Logik laufen die echten Tests mit Node.js — jene, die DOM-Manipulation berühren (zum Beispiel die Sortierung in Book View), nutzen `jsdom` für eine echte DOM-Umgebung, keine oberflächliche Simulation.

Ein Test, der nur bestätigt, was du ohnehin erwartest, hilft wenig. Die nützlichsten Tests in diesem Projekt sind jene, die genau den leicht zu übersehenden Grenzfall prüfen — zum Beispiel, dass eine Vorschaufunktion niemals in die Datenbank schreibt oder dass ein Inhaltsverzeichnis nach einer Umsortierung mit dem tatsächlichen Inhalt synchron bleibt.

## Sicherheit — direkte Prüfung, keine Annahme

Jede neue SQL-Abfrage muss `$wpdb->prepare()` mit echten Parametern verwenden, keine String-Interpolation. Jede Aktion, die den Zustand ändert (AJAX, Formular), muss sowohl die Berechtigung (`current_user_can`) als auch einen Nonce prüfen. Jeder angezeigte Inhalt, der aus einer weniger vertrauenswürdigen Quelle als einem Administrator stammen könnte — der Titel einer externen Seite, ein von einem Besucher gesendeter HTTP-Header — muss bei der Ausgabe escaped werden, nicht nur bei der Eingabe bereinigt.

## Keine Telemetrie, keine Ausnahmen

Nichts in diesem Plugin sendet ohne ausdrückliche Handlung des Nutzers Daten an externe Dienste. Ein Beitrag, der irgendeine Form automatischer Meldung an einen externen Server hinzufügt, wird — so gut gemeint er auch sein mag — nicht angenommen.

## So reichst du einen Beitrag ein

Das Projekt soll auf WordPress.org veröffentlicht werden, über den standardmäßigen SVN-Ablauf der Plattform. Für Code-Beiträge ist es am einfachsten, die vorgeschlagene Änderung und ihren Grund klar zu beschreiben und zusammen mit den zugehörigen Tests direkt an den Autor zu senden (siehe die Kontaktdaten im Plugin-Header oder in readme.txt).
