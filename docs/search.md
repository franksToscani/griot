# Griot: ricerca nella lista dei professionisti

La ricerca testuale di `/providers`, insieme ai filtri per categoria e città. Senza account, come il resto della lista.

## Come funziona

- Parametro `q` nell'indirizzo (`/providers?q=trecce&city=Bologna`), quindi il link si può condividere. Si combina con `category` e `city`, e i link della paginazione lo mantengono.
- Validazione in `ProviderIndexRequest`: stringa facoltativa, senza spazi all'inizio e alla fine, al massimo 100 caratteri (`SEARCH_MAX`). Vuota, troppo lunga o non valida (per esempio un array) viene ignorata senza errore, come gli altri filtri: la pagina mostra la lista senza ricerca e `filters.q` è `null`.
- La ricerca sta nello scope `Provider::search()`:
    - il testo si divide in parole, separate da spazi; contano le prime 5 (`Provider::SEARCH_WORDS`), le altre si ignorano;
    - **ogni** parola deve comparire (AND tra le parole) in almeno uno di questi campi:
        - il nome del professionista;
        - il nome di uno dei suoi servizi **attivi**;
        - la categoria di uno dei suoi servizi attivi;
    - maiuscole e minuscole non contano; la parola può stare dentro un'altra (« trecc » trova « Trecce Sahel »);
    - solo i professionisti pubblicati, come sempre nella lista.
- L'ordine resta per nome, 12 per pagina.

- La stessa ricerca parte anche dalla fascia della home (`docs/home.md`): un form HTML `GET` verso `/providers` con `q` e `city`, che funziona senza JavaScript.

## Categorie e lingua

Le categorie hanno nel database lo slug e il nome italiano; il nome mostrato viene dalle chiavi `categories.{slug}` di `lang/*.json` (`Category::displayName()`). Per questo il confronto con le categorie si fa in PHP, non con LIKE: si leggono tutte le categorie (sono poche) e si tengono quelle in cui la parola compare nello slug, nel nome salvato o nel nome **nella lingua attiva** (`Category::mentions()`). Poi la query cerca i servizi attivi di quelle categorie.

Quindi « coiffeur » non trova « barbiere » se la lingua attiva è l'italiano: si cerca nella lingua in cui si legge la pagina, più lo slug e il nome italiano.

## LIKE uguale su SQLite, MySQL e PostgreSQL

`App\Support\LikePattern`:

- `%` e `_` scritti nella ricerca sono caratteri normali: vengono protetti con il carattere di escape `!` (scritto nella query come `escape '!'`). Niente barra rovesciata: in MySQL andrebbe scritta `'\\'`, in SQLite e PostgreSQL `'\'`, e SQLite non ha un carattere di escape predefinito.
- `lower()` sulla colonna e sul testo: anche PostgreSQL, dove LIKE distingue le maiuscole, trova lo stesso. SQLite abbassa solo le lettere A-Z: lì « É » ed « é » restano diverse.
- Nessuna funzione propria di un solo database, nessun indice full-text: con i numeri dell'MVP basta.

## Interfaccia

`resources/js/components/provider-filters.tsx` e `resources/js/pages/providers/index.tsx`:

- un `<form role="search" method="get">` con etichetta visibile (« Cerca per nome o servizio »), un campo `type="search"` e il pulsante « Cerca »; Invio dal campo invia. Senza JavaScript il browser manda `q` con categoria e città dai campi nascosti; con JavaScript la stessa ricerca è una visita di Inertia;
- dopo la ricerca il campo mostra il testo cercato (quello del server, senza spazi ai lati);
- un paragrafo `role="status"` sempre presente sopra la lista: il numero dei risultati, oppure lo stato vuoto adatto (ricerca senza risultati, filtri senza risultati, nessun professionista). Le visite dei filtri e della ricerca usano `preserveState`, così la pagina resta montata, il testo cambia e un lettore di schermo lo annuncia;
- 44 px sotto 768 px e focus visibile vengono dai componenti `Input` e `Button` del kit (`docs/accessibility.md`).

## Test

`tests/Feature/ProviderSearchTest.php`.
