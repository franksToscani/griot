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
- « Ordina per » (`resources/js/components/provider-sort.tsx`): un `<select>` nativo con etichetta visibile, accanto alla riga `role="status"` del numero dei risultati, 44 px sotto 768 px. Al cambio una visita di Inertia con gli stessi filtri, dalla pagina 1, con `preserveScroll` e `preserveState`: la pagina resta montata e il nuovo numero viene annunciato. L'ordine predefinito non compare nell'indirizzo.
- « Azzera filtri » toglie categoria, città e ricerca ma **mantiene** l'ordinamento (`resetFilters()`); anche la ricerca inviata dal browser lo porta con sé, in un campo nascosto.

## Query

Misurate in `ProviderListCardsTest`, sempre le stesse qualunque sia il numero dei professionisti, delle foto e dei servizi:

- **7** senza ricerca: categorie offerte e città (per i filtri), conteggio della paginazione, professionisti (con prezzo minimo, numero di servizi e id della copertina come sottoquery), servizi attivi, loro categorie, copertine;
- **8** con `q`: la ricerca legge anche la tabella delle categorie;
- categoria, città e ordinamento non aggiungono query.

Prima di questa PR erano 6 e 7: la sola query in più è quella delle copertine. La home resta a 4, o 6 con professionisti in evidenza.

## Test

`tests/Feature/ProviderListCardsTest.php` (regola dei servizi attivi, dati della scheda, i quattro ordinamenti con la parità, query, sorgenti di scheda e « Ordina per », testi), più `ProviderListTest`, `ProviderFiltersTest` e `ProviderSearchTest`.
