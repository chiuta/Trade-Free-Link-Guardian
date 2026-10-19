# Contribuire a Trade-Free Link Guardian

Grazie per aver preso in considerazione un contributo. Alcune cose utili da sapere prima di iniziare.

## La struttura a file unico — deliberata, non accidentale

Tutto il codice del plugin sta in un unico file PHP, `trade-free-link-guardian.php`. Non è una scelta del momento, è la filosofia del progetto: «senza frontiere» — nessun passaggio di build, nessuna dipendenza esterna da installare, funziona semplicemente copiando un solo file. Fa parte di un portafoglio più ampio di applicazioni a file unico costruite sullo stesso principio.

Se arrivi con esperienza di sviluppo WordPress moderno (namespace, autoloading, un file separato per classe), la struttura può sembrare insolita. È voluta, non un debito tecnico da risolvere con un grande refactoring. Un contributo che propone di dividere il file in più file non verrà accettato solo per questo motivo — ma un contributo che migliora qualcosa *all'interno* di questa struttura (una nuova funzione, una correzione, un test in più) è benvenuto.

## Orientamento rapido nel file

All'inizio del file c'è un indice («TABLE OF CONTENTS») — ogni classe ha un testo di ricerca esatto (per esempio `SECTION: TFLG_Checker`) che puoi cercare (Ctrl+F / Cmd+F) in qualsiasi editor per arrivarci direttamente. Da preferire ai numeri di riga, che si invecchierebbero a ogni modifica.

Le tredici classi, in breve:
- `TFLG_Util` — funzioni di supporto senza stato, usate ovunque
- `TFLG_DB` — lo schema e tutte le query dirette al database
- `TFLG_Scanner` — estrae i link dal contenuto
- `TFLG_Checker` — esegue la richiesta HTTP che decide se un link è vivo
- `TFLG_Fixer` — le sei correzioni deterministiche, applicazione/annullamento
- `TFLG_List_Table` — la tabella della schermata Links (solo admin)
- `TFLG_Admin` — l'HTML di ogni pagina di amministrazione, il menu, CSS/JS
- `TFLG_Ajax` — ogni endpoint `wp_ajax_`
- `TFLG_Network_Admin` — la vista a livello di rete, solo multisite
- `TFLG_Editor_Check` — l'avviso al salvataggio di un articolo
- `TFLG_404_Monitor` — il registro passivo degli errori 404
- `TFLG_Cron` — la scansione programmata facoltativa
- `TFLG_Plugin` — attivazione, disattivazione, disinstallazione, il costruttore che collega tutto

## Test — obbligatori, non facoltativi

Ogni nuova funzione o correzione deve essere accompagnata da test. I test esistenti non passano da un PHPUnit classico installato come dipendenza — sono script PHP autonomi che estraggono il codice reale dal file principale (contando le parentesi graffe, senza presupporre il testo) e lo eseguono in isolamento, con stub minimi per le funzioni WordPress necessarie. Per la logica JavaScript, i test reali girano con Node.js — quelli che toccano la manipolazione del DOM (per esempio l'ordinamento in Book View) usano `jsdom` per un vero ambiente DOM, non una simulazione superficiale.

Un test che si limita a confermare ciò che già ti aspetti non aiuta molto. I test più utili in questo progetto sono quelli che verificano esattamente il caso limite facile da sbagliare — per esempio, che una funzione di anteprima non scriva mai nel database, o che un indice resti sincronizzato con il contenuto reale dopo un riordino.

## Sicurezza — verifica diretta, non presupposto

Ogni nuova query SQL deve usare `$wpdb->prepare()` con parametri reali, non l'interpolazione di stringhe. Ogni azione che modifica lo stato (AJAX, modulo) deve verificare sia la capability (`current_user_can`) sia un nonce. Qualsiasi contenuto mostrato che possa provenire da una fonte meno affidabile di un amministratore — il titolo di una pagina esterna, un'intestazione HTTP inviata da un visitatore — deve essere sottoposto a escape in uscita, non solo ripulito in ingresso.

## Nessuna telemetria, nessuna eccezione

Nulla in questo plugin invia dati a servizi esterni senza un'azione esplicita dell'utente. Un contributo che aggiunge qualsiasi forma di segnalazione automatica a un server esterno, per quanto ben intenzionato, non verrà accettato.

## Come inviare un contributo

Il progetto verrà pubblicato su WordPress.org, con il flusso SVN standard della piattaforma. Per i contributi di codice, la via più semplice è descrivere con chiarezza la modifica proposta e il suo motivo, insieme ai test corrispondenti, direttamente all'autore (vedi i dati di contatto nell'intestazione del plugin o in readme.txt).
