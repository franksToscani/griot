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
- Il massimo di 9 e l'ordine li garantisce il codice, in una transazione con `lockForUpdate` sul provider, come per le disponibilità.

## Caricamento

`POST /provider/photos` (`provider.photos.store`), solo per il provider loggato, sempre nella propria galleria: un id nel corpo della richiesta è ignorato.

- Regole (`StoreProviderPhotoRequest`): JPG, PNG o WebP, al massimo 2 MB e 4000 px per lato, almeno 800 px sul lato più lungo; `consent` obbligatorio (`accepted`); `alt_text` facoltativo, al massimo 150 caratteri, spazi tolti, vuoto diventa `null`.
- Il limite di PHP (`upload_max_filesize=3M`, `post_max_size=8M` in `docker/php.ini`) è volutamente **più alto** di quello della validazione (2 MB): un file tra 2 e 3 MB arriva a Laravel e riceve il messaggio chiaro della regola `max:2048`; solo un file oltre 3 MB viene rifiutato da PHP prima (messaggio « uploaded »).
- `App\Profile\StoreProviderPhoto` ricodifica la foto **prima** della transazione (il lavoro lento non tiene il lock), poi blocca il provider con `lockForUpdate`, controlla il massimo di 9, scrive le dimensioni sul disco e crea la riga: nome casuale di 40 caratteri, `width`/`height` della dimensione più grande, `position` = ultima + 1, `consent_at` = adesso.
- Se qualcosa fallisce a metà (galleria piena, scrittura di una dimensione, database) i file già scritti vengono tolti e la riga non resta: la transazione la annulla. La pulizia usa `MediaDisk::deleteQuietly()`: un file che non si riesce a togliere lascia solo un avviso nel log e non copre l'errore di partenza.
- Errori per il provider, sul campo `photo` e di ritorno a `provider.edit`: galleria piena (`GalleryFull`), foto illeggibile (`InvalidAvatar`), un problema del disco (`PhotoNotStored`, segnalata anche con `report()`): una dimensione non scritta, un errore del bucket o il disco non configurato (le variabili `AWS_*` vuote: il disco si crea dentro un `try`). Il messaggio è lo stesso della foto del profilo: « Non è stato possibile salvare la foto. Riprova più tardi. » ([media-storage.md](media-storage.md)).
- Limite di richieste `provider-photos`: 20 al minuto per utente (`booking.rate_limit_photos_per_minute`), comune a caricamento e cancellazione. Oltre il limite si torna al profilo con un errore sul campo `photo`, come per la foto del profilo.

## Riduzione nel browser

`resources/js/lib/resize-image.ts`, usato dalla scheda del profilo al momento dell'invio (mai durante il disegno della pagina): `targetSize()` porta il lato più lungo a 2400 px al massimo senza mai ingrandire, `resizeImage()` ridisegna la foto su un canvas come JPEG di qualità 0,85, raddrizzata. Se il browser non ci riesce invia l'originale: decide sempre il server.

## Cancellazione

- Di una foto: `DELETE /provider/photos/{photo}` (`provider.photos.destroy`). La foto si cerca tra quelle del provider loggato, quindi la foto di un altro provider dà **404**, come per servizi e opzioni; `ProviderPhotoPolicy::delete` controlla di nuovo il proprietario. `App\Profile\DeleteProviderPhoto` cancella la riga e ricompatta le posizioni (1, 2, 3…) nella stessa transazione con `lockForUpdate`, poi toglie i file. Un file che non si riesce a togliere lascia un avviso nel log con la sola classe dell'errore, senza nomi né percorsi; la foto risulta comunque cancellata.
- Con l'account: `ProfileController::destroy` legge i percorsi di tutte le foto **prima** di cancellare, e toglie i file **solo dopo** che la cancellazione è riuscita, come per la foto del profilo. Se il database rifiuta la cancellazione (un provider con prenotazioni, #133) le foto restano tutte. Un file che non si riesce a togliere lascia lo stesso avviso, e l'account resta cancellato.

## Scheda nel profilo del provider

`resources/js/components/gallery-form.tsx`, nella pagina `provider/edit` subito sotto « La tua foto ». I dati arrivano da `ProviderEditResource`: `photos` (`ProviderPhotoEditResource`: `id`, indirizzo della dimensione da 400 px, `width`, `height`, `alt_text`, `position`, in ordine; mai `name`, percorsi o `consent_at`) e `gallery` (`max_photos` dal config, `min_side` da `StoreProviderPhotoRequest`). Le Resource pubbliche non cambiano.

Cosa vede il provider:

- Titolo « Foto del tuo lavoro » (h2, prende il focus dopo una rimozione), una riga su cosa caricare e il contatore « N di 9 foto ».
- Nessuna foto: un invito, nessun elenco vuoto. Altrimenti un `<ul>` (una colonna sotto 768 px, tre sopra) con la miniatura quadrata (`width`/`height`, `loading="lazy"`, `decoding="async"`), la descrizione se c'è e il pulsante « Togli ». Il testo alternativo è la descrizione del provider, o « Foto 2 di 6 ».
- « Togli » si chiama « Togli la foto 2 di 6 » per i lettori di schermo (testo `sr-only` da `t()`). La conferma è nel Dialog del kit, con « Annulla » e « Togli la foto »; dopo la rimozione il focus va al titolo della scheda e il toast « Foto eliminata. » viene letto.
- Il modulo: file (`accept="image/jpeg,image/png,image/webp"`: un iPhone converte HEIC in JPEG), descrizione facoltativa con il suggerimento collegato, casella del consenso e « Aggiungi la foto ». Il pulsante si attiva con un file scelto; la casella non lo blocca: se manca, l'errore del server compare accanto. Dopo un errore il focus va al primo campo sbagliato.
- All'invio la foto viene ridotta nel browser, poi inviata; durante entrambe le fasi il pulsante è disattivato e una regione `role="status"` dice « Caricamento in corso… ». Dopo un caricamento riuscito il modulo si svuota e la foto compare in fondo all'elenco.
- Con 9 foto il modulo sparisce e resta il messaggio « Hai raggiunto il massimo di 9 foto. Togline una per aggiungerne un'altra. ».
- Nessun ordinamento: l'ordine è quello di caricamento. Nessun carousel, nessuna animazione.

Le piccole regole (stato vuoto, aperto o pieno, testo di riserva) stanno in `resources/js/lib/gallery.ts`, provate con Node in `GalleryProfileCardTest`.

## Mosaico sulla pagina pubblica

`resources/js/components/provider-mosaic.tsx`, in `providers/show.tsx` tra il link per tornare indietro e la testata (anche nell'anteprima del provider). Con 0 foto non viene montato e non lascia spazio.

- Dati: `ProviderProfileResource` aggiunge `photos` (`ProviderPhotoPublicResource`: `id`, `width`, `height`, `alt_text`, `variants`). `variants` sono solo le dimensioni che esistono (`ProviderPhoto::sizes()`), dalla più piccola, come larghezza vera (`widthFor()`: per una foto verticale la 400 è larga 300) e indirizzo. Mai `name` come campo, `position`, percorsi o `consent_at`. Le foto arrivano con `Provider::withPublicProfile()`: una query in tutto. Un provider non pubblicato resta un 404.
- Disposizione (`mosaicLayout()` e `mosaicTiles()` in `resources/js/lib/gallery.ts`, provate con Node):
    - da 768 px in su, in un riquadro 2:1 di quattro colonne e due righe: la prima foto a sinistra sulla metà; le piccole riempiono la metà destra senza buchi (una la prende tutta, due una riga ciascuna, tre una riga e due quarti, quattro un quarto ciascuna). Con una sola foto, la grande occupa tutto il riquadro. Al massimo 5 foto.
    - sotto 768 px: la grande a tutta larghezza in 4:3, sotto al massimo due miniature quadrate a metà larghezza. Con 2 foto la miniatura resta a metà larghezza (stessa misura del caso con 3), così la pagina non scende troppo. Al massimo 3 foto; la quarta e la quinta sono nascoste con `max-md:hidden` e, caricate in modo lento, il browser non le scarica.
- Immagini: `srcset` con tutte le varianti (`srcSet()`, spazi e virgole degli indirizzi scritti come `%20` e `%2C`), `sizes` secondo il posto (la grande `(min-width: 768px) 50vw, 100vw`, sola `100vw`, le piccole `(min-width: 768px) 25vw, 50vw`, `50vw` quando occupano metà riquadro), `src` di riserva la variante più grande fino a 800 px (`fallbackSrc()`). `width` e `height` veri; le celle hanno il loro rapporto e `object-cover`, quindi la pagina non salta. La prima foto `loading="eager"` e `fetchpriority="high"`, le altre `loading="lazy"` e `decoding="async"`.
- Testo alternativo: quello del provider, nella sua lingua; senza, « Lavoro di :provider, foto :number di :total » (`photoAlt()` con la chiave `providers.show.photo_alt`; nella scheda del profilo resta « Foto 2 di 6 »).
- Accessibilità: nessun titolo (il mosaico viene prima dell'h1), un gruppo `role="group"` con il nome sr-only « Foto del lavoro ». Ogni foto mostrata è un `<button>` che apre l'ingrandimento (sezione seguente); le foto nascoste sotto 768 px sono `display: none` e quindi fuori dal Tab. Nessuna animazione, nessuno zoom al passaggio del mouse, niente sticky; con i colori forzati un bordo sottile su pulsanti e foto.
- Le foto che non entrano nel mosaico (oltre la quinta, oltre la terza su telefono) si vedono nell'ingrandimento, da « Vedi tutte le foto ».

## Ingrandimento e « Vedi tutte le foto »

`resources/js/components/photo-lightbox.tsx`, aperto dal mosaico (`provider-mosaic.tsx`).

- **Apertura**: ogni foto del mosaico è un `<button>` (`aria-haspopup="dialog"`) che apre l'ingrandimento su quella foto. Il suo nome è il testo alternativo della foto più un testo sr-only da `t()`: « Box braids lunghe (apri la foto 2 di 6 ingrandita) ». « Vedi tutte le foto (N) », pulsante del kit sotto il mosaico, apre sulla prima foto; compare solo quando qualche foto non è nel mosaico a quella larghezza (`seeAllPhotos()`: con 4 o 5 foto solo sotto 768 px, con 6 o più sempre).
- **Dialog del kit**: focus intrappolato, Esc e la X chiudono. Il Dialog è uno solo per tutti i pulsanti, quindi non ha un `DialogTrigger`: chi apre si salva in un ref e in `onCloseAutoFocus` il focus torna lì (Radix da solo lo darebbe al trigger, che non c'è). All'apertura il focus va su « Foto successiva » (con una foto sola, sulla X).
- **Contenuto**: titolo « Foto N di M » (`photoPosition()`), descrizione sr-only con l'aiuto delle frecce (con una foto sola, il testo alternativo), l'immagine con `srcset` di tutte le varianti e `sizes="100vw"`, `width`/`height` veri, `object-contain`, alta al massimo il 60% dello schermo; il Dialog non supera l'altezza dello schermo e scorre dentro (zoom al 200%).
- **Navigazione**: « Foto precedente » e « Foto successiva », circolari (`nextIndex()`, `previousIndex()`): dall'ultima si va alla prima e viceversa, così nessun pulsante si disattiva sotto il focus. Con una foto sola non ci sono. Le frecce sinistra e destra fanno lo stesso, solo con il focus dentro il Dialog e senza tasti modificatori (Alt+sinistra resta « indietro » del browser).
- **Annunci**: a ogni cambio una regione `role="status"` dice solo « Foto N di M »; si svuota alla chiusura, così riaprendo si legge il titolo.
- **Caricamento**: solo la foto aperta, subito (`loading="eager"`). Le altre si scaricano quando vengono aperte: nessun precaricamento della precedente e della successiva, per non spendere banda su foto che forse nessuno guarda.
- Nessun carousel, timer o movimento: la foto cambia solo quando la persona lo chiede, senza effetti di scambio.
- **Tab**: tra « Tutti i professionisti » e « Scegli un servizio » ora ci sono le foto del mosaico (fino a 5, 3 su telefono) e, se c'è, « Vedi tutte le foto »: al massimo 6 fermate in più. Il link « Salta al contenuto » porta al `<main>`, che comincia comunque con il mosaico.

## Piano delle PR

| PR  | Contenuto                                                                                                                     |
| --- | ----------------------------------------------------------------------------------------------------------------------------- |
| A   | Ricodifica comune, tabella `provider_photos`, modello, configurazione                                                         |
| B   | Caricamento (rotta, regole, consenso, limite di 9, limite di richieste) e cancellazione dei file: di una foto e con l'account |
| C   | Scheda « Foto del tuo lavoro » nel profilo del provider                                                                       |
| D   | Mosaico in testa alla pagina pubblica, con immagini responsive (`srcset`, `sizes`, caricamento lento, misure fisse)           |
| E   | Ingrandimento accessibile (Dialog del kit: focus, Esc, ritorno del focus, foto precedente e successiva) (questa)              |
| F   | Ordinamento (sposta prima/dopo) e testi: testo di riserva, consenso, privacy                                                  |
