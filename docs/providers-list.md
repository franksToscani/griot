# Griot: lista dei professionisti

La pagina `/providers` (« Trova un professionista »): chi c'è, cosa mostra la scheda, come si ordina. La ricerca testuale e i filtri sono in `docs/search.md`.

## Chi c'è nella lista

- Solo i professionisti **pubblicati** con **almeno un servizio attivo** (`whereHas` sui servizi attivi in `ProviderController@index`), come nella home: senza un servizio attivo non c'è niente da prenotare, e nessun prezzo da mostrare. Un servizio non attivo non basta, nemmeno con un filtro o una ricerca.
- 12 per pagina; i link della paginazione mantengono filtri, ricerca e ordinamento.

## La scheda

`resources/js/components/provider-card.tsx`, dati da `App\Http\Resources\ProviderResource`:

| Campo                                                    | Da dove                                                                                                                                                                                        |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`, `slug`, `name`, `city`, `avatar_url`, `categories` | Come prima: le categorie dai servizi attivi, senza doppioni.                                                                                                                                   |
| `cover`                                                  | La prima foto del lavoro per `position`, poi `id`; `null` senza foto. Stessa forma della home (`ProviderCoverResource`: `width`, `height`, `variants`), senza testo alternativo: è decorativa. |
| `min_price`                                              | Il prezzo del servizio attivo meno caro, `withMin`, sempre come `"30.00"` (euro, senza le opzioni; il cast `decimal:2` uniforma SQLite, che restituisce un numero).                            |
| `services_count`                                         | Quanti servizi attivi, `withCount`.                                                                                                                                                            |

- `published_at` non esce mai nella risposta: serve solo a ordinare. Niente recensioni, valutazioni, prossimo slot libero, badge « Nuovo ».
- La copertina è la stessa della home: lo scope `Provider::withCoverPhotoId()` aggiunge l'id della prima foto con una sottoquery correlata con `LIMIT 1` (scritta da Laravel per ogni database), e `App\Support\ProviderCovers::attach()` carica in una query solo quelle foto, come relazione `cover`. La query parte anche quando nessuno ha foto, così il numero delle query non dipende dai dati.
- Aspetto: un solo link per tutta la scheda; sopra la copertina 4:3 (`alt=""`, `loading="lazy"`, `decoding="async"`, `width` e `height`, `srcset` e `sizes`), oppure, senza foto, l'avatar in un riquadro della stessa forma; sotto il nome (h2, la pagina ha l'h1), la città, le categorie e « 3 servizi · da 30,00 € » (`providers.card.*`, il prezzo con `formatPrice()`, EUR). Si solleva sotto il mouse o il focus come le schede della home (`CARD_MOTION`, solo senza « riduci movimento »); nessuna comparsa allo scorrimento: i risultati si vedono subito. Griglia: una colonna, due da 640 px, tre da 1024 px.

## Ordinamento

- Parametro `sort` in `ProviderIndexRequest`, lista chiusa `SORTS`:
    - `name` (predefinito): nome A→Z;
    - `recent`: i pubblicati più di recente prima (`published_at` decrescente);
    - `price_asc` e `price_desc`: per `min_price`.
- Sempre l'`id` dopo il criterio scelto (crescente; decrescente per `recent`, come nella home): con nomi o prezzi uguali l'ordine resta lo stesso da una pagina all'altra.
- Un valore che non è nella lista (o un array) si ignora come gli altri filtri: ordine per nome e `filters.sort` a `null`. Il valore valido va in `filters` e nei link della paginazione.
- Nessuna funzione di un solo database: `orderBy` sulla colonna calcolata da `withMin`. Nessun professionista è senza prezzo, grazie alla regola dei servizi attivi.
- « Ordina per » (`resources/js/components/provider-sort.tsx`): un `<select>` nativo con etichetta visibile, nella riga dei risultati, 44 px sotto 768 px. Al cambio una visita di Inertia con gli stessi filtri, dalla pagina 1, con `preserveScroll` e `preserveState`: la pagina resta montata e il nuovo numero viene annunciato. L'ordine predefinito non compare nell'indirizzo.
- « Azzera filtri » toglie tutti i filtri (categoria, città, ricerca, prezzo massimo, « solo con foto ») ma **mantiene** l'ordinamento (`resetFilters()`); anche la ricerca e il pannello inviati dal browser lo portano con sé, in un campo nascosto.

## Filtri

- **Una sola lista** delle chiavi: `ProviderIndexRequest::FILTERS` (`category`, `city`, `q`, `max_price`, `with_photo`). La leggono:
    - `filters()`, che restituisce quelle chiavi e poi `sort`;
    - i link della paginazione (`appends(array_filter($filters))`);
    - la pagina, che la riceve nella prop `filterKeys` (`all` e `panel`) e la usa per « Azzera filtri » (`hasActiveFilters()`), per i campi nascosti della barra di ricerca e per il numero di « Filtri (n) » (`activePanelFilters()`).
- `PANEL_FILTERS` (`category`, `max_price`, `with_photo`): i filtri del pannello, contati da « Filtri (n) ». Città, ricerca e ordinamento non contano: stanno nella barra o nella riga dei risultati.
- **Prezzo massimo** (`max_price`): euro, positivo, al massimo 9999.99; la virgola vale come punto (`DecimalPrice::commaToDot()`, come il prezzo di un servizio) e il valore torna come `"39.90"`. Tiene chi ha **almeno un servizio attivo** a quel prezzo o meno, limite compreso (scope `Provider::withServiceUpTo()`): coerente con « da X € » della scheda. Le opzioni possono aggiungere al prezzo.
- **Solo con foto** (`with_photo`): solo il valore `1` lo attiva; tiene chi ha almeno una foto del lavoro (scope `Provider::withPhotos()`).
- Un valore non valido (zero, negativo, troppo grande, tre decimali, « 1.000,50 », un array, `with_photo=true`) si ignora senza errore, come gli altri filtri: il filtro vale `null`.
- Tutti sono `whereHas`, sottoquery della query principale: nessuna query in più e nessuna funzione di un solo database.

## Interfaccia

`resources/js/pages/providers/index.tsx`, dall'alto in basso:

1. Titolo (h1).
2. **Barra di ricerca** (`ProviderSearchBar` in `components/provider-filters.tsx`): gli stessi campi della home (`SearchFields`, `docs/search.md`) in un form GET con `onSubmit`, che fa una visita di Inertia con `preserveScroll` e `preserveState`. La città è un `<select>` nativo e si applica con « Cerca », non più subito. Gli altri filtri e l'ordinamento viaggiano come campi nascosti. Il form riparte (nuova `key`) quando ricerca o città cambiano da fuori.
3. **Riga dei risultati**: « Filtri » (« Filtri (2) » con due filtri del pannello attivi), « Ordina per », poi il paragrafo `role="status"` con il numero dei risultati o lo stato vuoto. Il paragrafo è sempre presente, fuori da ogni condizione, e cambia dopo ogni visita: un lettore di schermo lo annuncia. Lo stato vuoto senza alcun filtro né ricerca (nessun professionista ancora pubblicato) ha un testo caldo, « I primi professionisti stanno arrivando. Torna a trovarci presto. », e, solo se un contatto del sito è configurato, l'invito « Sei un professionista? Scrivici » con i link email e WhatsApp (`components/professionals-invite.tsx`). L'invito sta dentro lo stesso paragrafo `role="status"`, così viene annunciato con il testo.
4. « Azzera filtri », quando un filtro è attivo.
5. Le schede in `ResultsLayout`, poi la paginazione.

**Pannello dei filtri** (`components/provider-filters-panel.tsx`), a tutte le larghezze:

- Il `Sheet` del kit (Radix Dialog), dal lato destro, con titolo e descrizione da `t()`. Radix tiene il focus dentro il pannello mentre è aperto, lo chiude con Esc e riporta il focus su « Filtri » alla chiusura; il componente non gestisce focus o tasti a mano.
- Campi con etichetta visibile: categoria (`<select>` nativo con « Tutte le categorie »), prezzo massimo (`inputMode="decimal"`, con un esempio collegato da `aria-describedby`), « Solo con foto del lavoro » (la `Checkbox` del kit).
- I campi sono una bozza: aprendo il pannello ripartono dai filtri della pagina. « Applica » visita la lista con i tre valori, dalla pagina 1, e chiude; « Azzera » svuota solo i campi.
- Anche il pannello è un form GET, con ricerca, città e ordinamento in campi nascosti.
- 44 px sotto 768 px: `Button` e `Input` del kit, la `<select>` con `max-md:min-h-11`, la checkbox con la sua area invisibile.

## Layout

- La lista ha tutta la larghezza (`FULL_WIDTH_PAGES` in `public-layout.tsx`, come la home) e mette il contenuto nel contenitore del sito (`max-w-6xl`, `px-4`).
- `ResultsLayout` (`components/results-layout.tsx`) ha uno slot `aside` facoltativo, pensato per la mappa:
    - senza `aside`, come oggi, una colonna: la lista;
    - con `aside`, da 1024 px `lg:grid-cols-[minmax(0,1fr)_minmax(0,40%)]`, con la lista a sinistra e **prima nel DOM**; sotto 1024 px l'aside va sotto la lista.
- Nessuna mappa e nessun `<aside>` vuoto per ora. Niente `sticky`: un elemento fermo sullo schermo potrebbe coprire il focus.
- Copertine: `sizes="(min-width: 1152px) 23rem, (min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"`, un terzo del contenitore al massimo. Con la mappa la griglia andrà rivista.
- A 320 px tutto in una colonna, senza larghezze minime fisse: `md:min-w-56` e `md:w-44` valgono solo da 768 px.

## Query

Misurate in `ProviderListCardsTest`, sempre le stesse qualunque sia il numero dei professionisti, delle foto e dei servizi:

- **7** senza ricerca: categorie offerte e città (per i filtri), conteggio della paginazione, professionisti (con prezzo minimo, numero di servizi e id della copertina come sottoquery), servizi attivi, loro categorie, copertine;
- **8** con `q`: la ricerca legge anche la tabella delle categorie;
- categoria, città, prezzo massimo, « solo con foto » e ordinamento non aggiungono query (misurate anche in `ProviderListFiltersTest`).

Prima della scheda ricca erano 6 e 7: la sola query in più è quella delle copertine. La home resta a 4, o 6 con professionisti in evidenza.

## Test

`tests/Feature/ProviderListCardsTest.php` (regola dei servizi attivi, dati della scheda, i quattro ordinamenti con la parità, query, sorgenti di scheda e « Ordina per », testi) e `tests/Feature/ProviderListFiltersTest.php` (prezzo massimo, « solo con foto », lista unica dei filtri, barra condivisa, pannello, riga dei risultati, `ResultsLayout`, testi), più `ProviderListTest`, `ProviderFiltersTest` e `ProviderSearchTest`.
