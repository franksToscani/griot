# Griot: galleria delle foto del lavoro

Le foto del lavoro di un provider, in testa alla sua pagina pubblica (issue #143). Questo documento raccoglie le decisioni, il modello dati e il piano delle PR.

## Decisioni

- Al massimo 15 foto per provider (`config('booking.gallery.max_photos')`; erano 9 fino a #53). La galleria **non** è richiesta per pubblicare il profilo.
- Il mosaico comparirà già con 1 foto.
- Ogni foto è salvata come WebP in 3 dimensioni del lato lungo: 400, 800 e 1600 px (`booking.gallery.sizes`), qualità 82 (`booking.gallery.quality`). Le dimensioni non si tolgono né si cambiano: gli indirizzi delle foto già salvate si ricavano da lì (`ProviderPhoto::sizes()`). Aggiungerne una più grande è sicuro, perché le foto vecchie non ce l'hanno e non la elencano.
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
- Il massimo di foto e l'ordine li garantisce il codice, in una transazione con `lockForUpdate` sul provider, come per le disponibilità.

## Caricamento

`POST /provider/photos` (`provider.photos.store`), solo per il provider loggato, sempre nella propria galleria: un id nel corpo della richiesta è ignorato.

- Regole (`StoreProviderPhotoRequest`): JPG, PNG o WebP, al massimo 4 MB (`booking.gallery.max_kilobytes`) e 4000 px per lato, almeno 800 px sul lato più lungo; `consent` obbligatorio (`accepted`); `alt_text` facoltativo, al massimo 150 caratteri, spazi tolti, vuoto diventa `null`.
- Il limite di PHP (`upload_max_filesize=5M`, `post_max_size=8M` in `docker/php.ini`) è volutamente **più alto** di quello della validazione (4 MB): un file tra 4 e 5 MB arriva a Laravel e riceve il messaggio chiaro della regola `max` (« al massimo 4 MB », dal config); solo un file oltre 5 MB viene rifiutato da PHP prima (messaggio « uploaded »). `post_max_size` resta più alto di `upload_max_filesize`: c'è posto anche per descrizione e consenso.
- `App\Profile\StoreProviderPhoto` ricodifica la foto **prima** della transazione (il lavoro lento non tiene il lock), poi blocca il provider con `lockForUpdate`, controlla il massimo (`max_photos`), scrive le dimensioni sul disco e crea la riga: nome casuale di 40 caratteri, `width`/`height` della dimensione più grande, `position` = ultima + 1, `consent_at` = adesso.
- Se qualcosa fallisce a metà (galleria piena, scrittura di una dimensione, database) i file già scritti vengono tolti e la riga non resta: la transazione la annulla. La pulizia usa `MediaDisk::deleteQuietly()`: un file che non si riesce a togliere lascia solo un avviso nel log e non copre l'errore di partenza.
- Errori per il provider, sul campo `photo` e di ritorno a `provider.edit`: galleria piena (`GalleryFull`), foto illeggibile (`InvalidAvatar`), un problema del disco (`PhotoNotStored`, segnalata anche con `report()`): una dimensione non scritta, un errore del bucket o il disco non configurato (le variabili `AWS_*` vuote: il disco si crea dentro un `try`). Il messaggio è lo stesso della foto del profilo: « Non è stato possibile salvare la foto. Riprova più tardi. » ([media-storage.md](media-storage.md)).
- Limite di richieste `provider-photos`: 40 al minuto per utente (erano 20 fino a #53) (`booking.rate_limit_photos_per_minute`), comune a caricamento e cancellazione. Oltre il limite si torna al profilo con un errore sul campo `photo`, come per la foto del profilo.

## Riduzione nel browser

`resources/js/lib/resize-image.ts`, usato dalla scheda del profilo al momento dell'invio (mai durante il disegno della pagina): `targetSize()` porta il lato più lungo a 2400 px al massimo senza mai ingrandire, `resizeImage()` ridisegna la foto su un canvas come JPEG di qualità 0,85, raddrizzata. Se il browser non ci riesce invia l'originale: decide sempre il server.

La foto del profilo (`AvatarForm` in `provider/edit.tsx`, #53) viene ridotta **solo se il file supera il limite del server** (1,5 MB, `StoreProviderAvatarRequest::MAX_KILOBYTES`, che arriva alla pagina come `avatar_max_kilobytes` di `ProviderEditResource`; il confronto è `needsResize()`, con i KB contati come la regola `max` di Laravel). Un file più leggero parte com'è, senza ricodifica: un PNG con trasparenza resta un PNG, un panorama tiene i suoi pixel. Quando serve, la stessa funzione con `AVATAR_MAX_SIDE` = 1024 px: il server la salva a 512 px (`StoreProviderAvatar`), quindi ha il doppio dei pixel che servono, e un JPEG di 1024 px a 0,85 resta molto sotto 1,5 MB. Come nella galleria: solo all'invio, e l'originale se il browser non riesce a leggerla; se anche ridotta supera il limite, risponde il server con il suo errore.

**Ingrandimento della foto del profilo** (#53): nell'`AvatarForm`, se la foto c'è, l'anteprima è un `<button type="button">` nativo (nome: il testo alternativo della foto, « Foto di :name », e il testo visibile « Vedi la foto più grande ») che apre il Dialog del kit con la foto salvata (512 px), il titolo « Foto di :name » e la riga « Così la vedono i clienti sulla tua pagina. ». Esc e la X chiudono; il `DialogTrigger` riporta il focus al pulsante. Non usa l'ingrandimento della galleria (`photo-lightbox.tsx`): è fatto per un elenco di foto del lavoro, con « Foto N di M », le varianti e un pulsante esterno di apertura. Senza foto restano le iniziali, non cliccabili. La pagina pubblica non cambia.

## Cancellazione

- Di una foto: `DELETE /provider/photos/{photo}` (`provider.photos.destroy`). La foto si cerca tra quelle del provider loggato, quindi la foto di un altro provider dà **404**, come per servizi e opzioni; `ProviderPhotoPolicy::delete` controlla di nuovo il proprietario. `App\Profile\DeleteProviderPhoto` cancella la riga e ricompatta le posizioni (1, 2, 3…) nella stessa transazione con `lockForUpdate`, poi toglie i file. Un file che non si riesce a togliere lascia un avviso nel log con la sola classe dell'errore, senza nomi né percorsi; la foto risulta comunque cancellata.
- Con l'account: `ProfileController::destroy` legge i percorsi di tutte le foto **prima** di cancellare, e toglie i file **solo dopo** che la cancellazione è riuscita, come per la foto del profilo. Se il database rifiuta la cancellazione (un provider con prenotazioni, #133) le foto restano tutte. Un file che non si riesce a togliere lascia lo stesso avviso, e l'account resta cancellato.

## Scheda nel profilo del provider

`resources/js/components/gallery-form.tsx`, nella pagina `provider/edit` subito sotto « La tua foto ». I dati arrivano da `ProviderEditResource`: `photos` (`ProviderPhotoEditResource`: `id`, `variants` (tutte le dimensioni che esistono, come larghezza e indirizzo, come nella pagina pubblica; #53), `width`, `height`, `alt_text`, `position`, in ordine; mai `name`, percorsi o `consent_at`) e `gallery` (`max_photos` dal config, `min_side` da `StoreProviderPhotoRequest`). Le Resource pubbliche non cambiano.

Cosa vede il provider:

- Titolo « Foto del tuo lavoro » (h2, prende il focus dopo una rimozione), una riga su cosa caricare e il contatore « N di 15 foto ».
- Nessuna foto: un invito, nessun elenco vuoto. Altrimenti un `<ul>` (una colonna sotto 768 px, tre sopra, due da 1280 px nella colonna di destra: `THUMBNAIL_GRID` in `resources/js/lib/gallery.ts`) con la miniatura quadrata (`srcset` con tutte le dimensioni e `sizes` = `THUMBNAIL_SIZES`, accanto alla griglia: `calc(100vw - 5rem)` sotto 768 px, `13rem` da 768 px, `10rem` da 1280 px; `width`/`height`, `loading="lazy"`, `decoding="async"`), la descrizione se c'è e il pulsante « Togli ». Il testo alternativo è la descrizione del provider, o « Foto 2 di 6 ».
- « Togli » si chiama « Togli la foto 2 di 6 » per i lettori di schermo (testo `sr-only` da `t()`). La conferma è nel Dialog del kit, con « Annulla » e « Togli la foto »; dopo la rimozione il focus va al titolo della scheda e il toast « Foto eliminata. » viene letto.
- Il modulo (#53, caricamento multiplo): un campo file con `multiple` (`accept="image/jpeg,image/png,image/webp"`: un iPhone converte HEIC in JPEG) e il suggerimento collegato. Sotto, un `<ul>` dei file scelti: per ognuno il nome (etichetta nativa del suo `<progress>`), una descrizione facoltativa (al massimo 150 caratteri, etichetta « Descrizione di :name (facoltativa) », collegata all'aiuto comune), lo stato scritto (« In coda », « In caricamento… », « Errore: <motivo del server> »), un `<progress>` nativo e « Togli dall'elenco »; per un errore anche « Riprova ». Il campo si svuota dopo ogni scelta: l'elenco tiene i file, e una nuova scelta si aggiunge in fondo.
- **Una sola casella di consenso per il gruppo** (« Le persone riconoscibili in queste foto hanno accettato che vengano pubblicate. », la chiave `provider.photos.consent` portata al plurale), obbligatoria prima di inviare: senza, compare l'errore accanto e il focus va sulla casella. Il consenso parte con **ogni** foto, quindi `consent_at` resta scritto su ogni riga, come prima.
- **Posti liberi**: `freeSlots()` = `max_photos` meno le foto già salvate meno i file ancora nell'elenco (anche quelli in errore). I file oltre i posti liberi sono rifiutati **prima** dell'invio, con un messaggio unico (« 2 foto non aggiunte all'elenco: la galleria ne tiene al massimo 15. »).
- **La coda** (`resources/js/lib/upload-queue.ts`, provata con Node in `GalleryUploadQueueTest`): un file alla volta, nell'ordine scelto, **mai due insieme** (il server ha 0,5 CPU e 512 MB). Ogni file viene ridotto nel browser (`resizeImage`, come prima) e inviato con `router.post` alla rotta di sempre (`provider.photos.store`): il server non cambia, né le regole né il limite di 15 con `lockForUpdate` né il limite di richieste. `preserveState`: la coda resta sulla pagina mentre la galleria riceve ogni foto nuova. Nessuno stato salvato: la coda vive nella pagina. Nessun URL di anteprima: il modulo mostra il nome, non la foto, quindi non c'è niente da rilasciare con `URL.revokeObjectURL`; la riduzione chiude da sé la sua immagine (`bitmap.close()`).
- **Errori parziali**: una foto salvata esce dall'elenco e compare nella galleria; una rifiutata resta con il motivo del server (troppo pesante, troppo piccola, formato, galleria piena, troppe richieste: `uploadError()` prende l'errore di `photo`, poi di `alt_text`, poi di `consent`; senza, « Non è stato possibile salvare la foto. Riprova più tardi. ») e « Riprova », che la rimette in coda.
- **Fine della coda**: il focus va al titolo della scheda (`TITLE_ID`, senza anello: `CARD_TITLE_FOCUS`), e un solo riepilogo compare come toast e sulla riga `role="status"` (« 7 foto aggiunte, 2 foto non caricate. », plurale con le chiavi `_one`). Mentre la coda gira il toast di ogni foto (« Foto aggiunta. ») è silenziato (`silenceFlashToast()` in `hooks/use-flash-toast.ts`): solo quel messaggio, gli altri toast restano. `useFlashFocus` non è toccato: il caricamento di una foto non manda il flash `focus`.
- Con 15 foto e nessun file nell'elenco il modulo sparisce e resta il messaggio « Hai raggiunto il massimo di 15 foto. Togline una per aggiungerne un'altra. ». Il modulo resta montato mentre una coda finisce di riempire la galleria, così riepilogo e focus arrivano comunque.
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
