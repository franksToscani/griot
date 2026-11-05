# Griot: galleria delle foto del lavoro

Le foto del lavoro di un provider, in testa alla sua pagina pubblica (issue #143). Questo documento raccoglie le decisioni, il modello dati e il piano delle PR.

## Decisioni

- Al massimo 9 foto per provider (`config('booking.gallery.max_photos')`). La galleria **non** è richiesta per pubblicare il profilo.
- Il mosaico comparirà già con 1 foto.
- Ogni foto è salvata come WebP in 3 dimensioni del lato lungo: 400, 800 e 1600 px (`booking.gallery.sizes`), qualità 82 (`booking.gallery.quality`).
- Una foto non viene mai ingrandita: una dimensione più lunga della foto non si produce. La più piccola c'è sempre, al massimo grande quanto la foto.
- I metadati dell'originale (EXIF con la posizione GPS, commenti, profili colore) vengono tolti: si salvano solo i pixel, ricodificati. Una foto scattata col telefono di lato viene raddrizzata.
- Testo alternativo facoltativo, scritto dal provider nella sua lingua; senza, un testo di riserva tradotto (PR successive).
- Consenso: si salva solo `consent_at`, il momento in cui il provider ha confermato che le persone riconoscibili nella foto hanno accettato. Nessun dato di quelle persone.
- La prima foto per ordine sarà la « copertina » (PR successive).

## Ricodifica comune

`App\Profile\WebpEncoder` legge la foto caricata con GD, la raddrizza secondo l'EXIF, toglie i metadati e produce WebP a una o più dimensioni (`WebpVariant`: byte, larghezza, altezza). La usano la foto del profilo (`StoreProviderAvatar`, una sola dimensione da 512 px, qualità 85, come prima) e la galleria. Un file che GD non sa leggere o scrivere dà `InvalidAvatar`.

## Modello dati

Tabella `provider_photos` (modello `App\Models\ProviderPhoto`, relazione `Provider::photos()` ordinata per `position` e poi `id`):

| Colonna                    | Tipo                    | Note                                                                |
| -------------------------- | ----------------------- | ------------------------------------------------------------------- |
| `id`                       | id                      |                                                                     |
| `provider_id`              | chiave esterna          | `cascadeOnDelete`: le righe spariscono con il provider              |
| `name`                     | string(40), unique      | casuale; i file sono `gallery/{name}-{lato}.webp` sul disco `media` |
| `width`, `height`          | unsigned smallint       | misure della dimensione più grande prodotta                         |
| `alt_text`                 | string(150), nullable   | l'unico campo assegnabile in massa                                  |
| `position`                 | unsigned tinyint        | ordine; indice **non** unico su (`provider_id`, `position`)         |
| `consent_at`               | timestamp, obbligatorio |                                                                     |
| `created_at`, `updated_at` | timestamp               |                                                                     |

- Le dimensioni prodotte non sono salvate: `ProviderPhoto::sizes()` le ricava da `width`, `height` e dalla configurazione, così un indirizzo non punta mai a un file che non c'è.
- `pathFor()`, `paths()` e `urlFor()` danno i percorsi e gli indirizzi tramite `App\Support\MediaDisk`.
- Il massimo di 9 e l'ordine li garantisce il codice (PR B), in una transazione con `lockForUpdate` sul provider, come per le disponibilità.
- Oggi i file non vengono tolti: con la cancellazione dell'account spariscono solo le righe, per la cascata del database. La cancellazione dei file arriva con la PR B.

## Piano delle PR

| PR  | Contenuto                                                                                                                     |
| --- | ----------------------------------------------------------------------------------------------------------------------------- |
| A   | Ricodifica comune, tabella `provider_photos`, modello, configurazione (questa)                                                |
| B   | Caricamento (rotta, regole, consenso, limite di 9, limite di richieste) e cancellazione dei file: di una foto e con l'account |
| C   | Scheda « Foto del tuo lavoro » nel profilo del provider                                                                       |
| D   | Mosaico in testa alla pagina pubblica, con immagini responsive (`srcset`, `sizes`, caricamento lento, misure fisse)           |
| E   | Ingrandimento accessibile (Dialog del kit: focus, Esc, ritorno del focus, foto precedente e successiva)                       |
| F   | Ordinamento (sposta prima/dopo) e testi: testo di riserva, consenso, privacy                                                  |
