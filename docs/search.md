# Griot: ricerca nella lista dei professionisti

La ricerca testuale di `/providers`, insieme ai filtri per categoria e città. Senza account, come il resto della lista. Chi c'è nella lista, la scheda e l'ordinamento sono in `docs/providers-list.md`.

## Come funziona

- Parametro `q` nell'indirizzo (`/providers?q=trecce&city=Bologna`), quindi il link si può condividere. Si combina con `category`, `city` e `sort`, e i link della paginazione lo mantengono.
- Validazione in `ProviderIndexRequest`: stringa facoltativa, senza spazi all'inizio e alla fine, al massimo 100 caratteri (`SEARCH_MAX`). Vuota, troppo lunga o non valida (per esempio un array) viene ignorata senza errore, come gli altri filtri: la pagina mostra la lista senza ricerca e `filters.q` è `null`.
- La ricerca sta nello scope `Provider::search()`:
    - il testo si divide in parole, separate da spazi; contano le prime 5 (`Provider::SEARCH_WORDS`), le altre si ignorano;
    - **ogni** parola deve comparire (AND tra le parole) in almeno uno di questi campi:
        - il nome del professionista;
        - il nome di uno dei suoi servizi **attivi**;
        - la categoria di uno dei suoi servizi attivi;
    - maiuscole e minuscole non contano; la parola può stare dentro un'altra (« trecc » trova « Trecce Sahel »);
    - solo i professionisti pubblicati con almeno un servizio attivo, come sempre nella lista.
- L'ordine è quello scelto in « Ordina per » (per nome se non c'è), 12 per pagina (`docs/providers-list.md`).

- La stessa ricerca parte anche dalla fascia della home (`docs/home.md`): un form HTML `GET` verso `/providers` con `q` e `city`, che funziona senza JavaScript.
- Prezzo massimo e « solo con foto » sono nel pannello dei filtri della lista (`docs/providers-list.md`).
- La lista e la ricerca hanno un limite largo: 60 richieste al minuto per IP (`providers-list`, `docs/production.md`); oltre, la pagina 429 del sito.

## Categorie e lingua

Le categorie hanno nel database lo slug e il nome italiano; il nome mostrato viene dalle chiavi `categories.{slug}` di `lang/*.json` (`Category::displayName()`). Per questo il confronto con le categorie si fa in PHP, non con LIKE: si leggono tutte le categorie (sono poche) e si tengono quelle in cui la parola compare nello slug, nel nome salvato o nel nome **nella lingua attiva** (`Category::mentions()`). Poi la query cerca i servizi attivi di quelle categorie.

Quindi « coiffeur » non trova « barbiere » se la lingua attiva è l'italiano: si cerca nella lingua in cui si legge la pagina, più lo slug e il nome italiano.

## LIKE uguale su SQLite, MySQL e PostgreSQL

`App\Support\LikePattern`:

- `%` e `_` scritti nella ricerca sono caratteri normali: vengono protetti con il carattere di escape `!` (scritto nella query come `escape '!'`). Niente barra rovesciata: in MySQL andrebbe scritta `'\\'`, in SQLite e PostgreSQL `'\'`, e SQLite non ha un carattere di escape predefinito.
- `lower()` sulla colonna e sul testo: anche PostgreSQL, dove LIKE distingue le maiuscole, trova lo stesso. SQLite abbassa solo le lettere A-Z: lì « É » ed « é » restano diverse.
- Nessuna funzione propria di un solo database, nessun indice full-text: con i numeri dell'MVP basta.

## Interfaccia

I campi sono uno solo componente per la home e per la lista: `resources/js/components/search-fields.tsx` (`SearchFields`), senza form e con l'aspetto passato da fuori (`look`):

- « Servizio o professionista »: campo `q`, `type="search"`, con la lente decorativa e un esempio come placeholder (« Treccine, barbiere, unghie… »); l'etichetta resta visibile;
- « Città »: un `<select>` nativo con « Tutte le città » (valore vuoto);
- « Cerca ».

Le chiavi sono le stesse nei due posti: `search.q`, `search.placeholder`, `search.city`, `search.all_cities`, `search.submit`. Hanno sostituito `home.search.*` e `providers.filters.search`, `search_submit`, `city`, `all_cities`.

- **Home** (`components/home/home-search.tsx`): `SearchFields` in un form HTML `GET`, senza `onSubmit` né Inertia, nei colori della fascia.
- **Lista** (`ProviderSearchBar` in `components/provider-filters.tsx`): `SearchFields` in un `<form role="search" method="get">` con `onSubmit`. Invio dal campo invia. Con JavaScript la ricerca è una visita di Inertia con gli altri filtri e l'ordinamento; senza, il browser li manda dai campi nascosti. La città si applica con « Cerca »;
- dopo la ricerca il campo mostra il testo cercato (quello del server, senza spazi ai lati): il form riparte quando ricerca o città cambiano;
- un paragrafo `role="status"` sempre presente sopra la lista: il numero dei risultati, oppure lo stato vuoto adatto (ricerca senza risultati, filtri senza risultati, nessun professionista). Le visite dei filtri e della ricerca usano `preserveState`, così la pagina resta montata, il testo cambia e un lettore di schermo lo annuncia;
- 44 px sotto 768 px e focus visibile vengono dai componenti `Input` e `Button` del kit (`docs/accessibility.md`).

## Test

`tests/Feature/ProviderSearchTest.php`; la lista e l'ordinamento in `tests/Feature/ProviderListCardsTest.php`; i filtri, la barra condivisa e il pannello in `tests/Feature/ProviderListFiltersTest.php`.
