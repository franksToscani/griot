# Griot: prenotazioni

Come una prenotazione tiene il suo prezzo (issue #97), la pagina di una prenotazione nell'area del professionista, con il link dell'email « nuova prenotazione », e l'email « richiesta inviata » al cliente. Griot non gestisce pagamenti: il prezzo è un'informazione per il cliente e per il professionista, in euro.

## Quanto tempo occupa una prenotazione (#53)

**La regola, confermata dopo le prove con i professionisti:** una prenotazione occupa la **durata massima** del servizio più i **minuti delle opzioni** scelte. La durata minima non entra in nessun calcolo: la vede solo il cliente, per farsi un'idea. Così due clienti non si trovano mai nello stesso momento, anche quando il lavoro dura quanto il massimo; in cambio qualche orario in meno.

Nel modulo del servizio la regola si legge così:

- **« Tempo da tenere libero »**, il primo campo, obbligatorio (colonna `duration_max`): il tempo che Griot riserva per ogni prenotazione. Sotto, un esempio che segue quello che si scrive: « Una prenotazione alle 09:00 ti occupa fino alle 12:00 (3 h). » L'esempio parte sempre dalle 09:00: con il massimo consentito, 12 ore, finisce ancora lo stesso giorno, alle 21:00. È testo normale, letto con il campo (`aria-describedby`), non annunciato a ogni tasto.
- **« Può finire prima? Durata minima (facoltativa) »**, il secondo campo (`duration_min`): se resta vuoto si salva uguale al tempo da tenere libero, quindi la colonna non cambia e non serve nessuna migrazione. Una minima più lunga della massima resta rifiutata, con il messaggio di prima. Nella pagina di modifica, una minima uguale alla massima si mostra vuota: cambiare solo la massima non chiede altro.
- **Minuti extra di un'opzione:** « si aggiungono al tempo che ti viene riservato ».
- Nessun testo parla di « calendario »: l'area del professionista non ne ha uno.

Il cliente vede « di solito da 2 h 30 a 3 h 30 » quando minima e massima sono diverse, una durata sola quando sono uguali, e, una volta scelto l'orario, « Dalle 14:00 fino alle 17:30 al massimo ». Quella fine arriva dal server con l'orario (`end_time` di `SlotResource`): è la fine calcolata da `FreeSlots` con `BookingDuration`, la stessa che `CreateBooking` salva in `ends_at` e che la conferma e le email mostrano. Le durate si scrivono in ore e minuti (« 45 min », « 3 h », « 3 h 30 », con spazi non separabili) in `lib/format.ts` (`formatMinutes`), uguali in italiano, francese e inglese.

Tre esempi, con una giornata di lavoro dalle 9 alle 19 e un orario d'inizio ogni 30 minuti:

- **Barba, 30 minuti.** Una prenotazione alle 10:00 occupa 10:00–10:30. Il cliente vede « 30 min » e gli orari 9:00, 9:30 … 18:30. Due barbe una dopo l'altra, alle 10:00 e alle 10:30, vanno bene.
- **Manicure, 45 minuti, con « Nail art » (+30) e « Rimozione » (+15).** Senza opzioni occupa 45 minuti; con tutte e due 1 h 30: alle 9:00 occupa 9:00–10:30. Gli orari cambiano quando il cliente sceglie un'opzione. Dopo una manicure semplice 9:00–9:45, il primo orario libero è 10:00: i 15 minuti in mezzo si perdono per la griglia da 30 minuti, non per la durata.
- **Treccine, di solito da 2 a 3 ore, con « Perline » (+30).** Il professionista scrive 180 come tempo da tenere libero e 120 come minima. Il cliente legge « di solito da 2 h 30 a 3 h 30 »; scelte le 14:00, legge « Dalle 14:00 fino alle 17:30 al massimo », e la prenotazione occupa 14:00–17:30 anche se il lavoro finisce alle 16:30. Con quella prenotazione, un altro cliente trova la mattina 9:00, 9:30, 10:00 e 10:30 (che finisce alle 14:00 esatte); dalle 11:00 non c'è più posto, e nel pomeriggio nemmeno.

`BookingDuration`, `FreeSlots` e `CreateBooking` non sono cambiati: lo controlla `ServiceDurationTest` (le treccine: 180 + 30 dalle 14:00 finiscono alle 17:30).

## Che cosa si salva

| Colonna                                    | Che cosa contiene                                                         |
| ------------------------------------------ | ------------------------------------------------------------------------- |
| `bookings.service_price_cents`             | il prezzo del servizio al momento della prenotazione, in centesimi interi |
| `booking_service_option.extra_price_cents` | il prezzo extra di ogni opzione scelta, in centesimi interi               |

- Interi senza segno, non nulli, senza valore predefinito: chi crea una prenotazione deve scriverli.
- **Il totale non si salva**: `Booking::totalCents()` somma il servizio e le opzioni salvate. Una sola fonte, nessun totale che possa contraddire le sue parti.
- Solo euro: nessuna colonna della valuta.
- Il catalogo (`services.price`, `service_options.extra_price`) resta in euro con due decimali (`decimal(8,2)`).

## Quando si salva

`App\Booking\CreateBooking`, per le richieste dei clienti e per le prenotazioni che il professionista scrive nella propria agenda, dentro la stessa transazione:

1. blocca il provider (`lockForUpdate`) e verifica che l'orario sia libero;
2. **rilegge** il servizio e le opzioni scelte (`BookingDuration::chosenOptions()`, le stesse che decidono i minuti);
3. salva i loro prezzi di quel momento.

Se il professionista cambia un prezzo mentre il cliente compila il modulo, si salva il prezzo del momento del salvataggio. Il modulo mostra un « Prezzo indicativo », calcolato dal catalogo.

Dopo, niente cambia i prezzi salvati:

- un prezzo cambiato nel catalogo vale solo per le prenotazioni nuove;
- un servizio o un'opzione nascosti restano sulla prenotazione con il loro prezzo;
- un servizio o un'opzione con prenotazioni non si possono cancellare (`restrictOnDelete`), come prima;
- l'anonimizzazione dei dati dei clienti (`bookings:anonymize-old`) non tocca i prezzi: non sono dati personali e restano nello storico del professionista.

## Centesimi, mai virgola mobile

`App\Support\Money`:

- `cents("25.50")` legge il prezzo dal suo testo, cifra per cifra, e dà `2550`. Mai `prezzo * 100`: con la virgola mobile `0.29 * 100` non dà 29;
- `centsFromDatabase()` accetta anche i numeri che SQLite restituisce con il query builder (`25.5`, `90`): li scrive prima con due decimali (`sprintf('%.2f')`). Un test lo verifica su tutti i prezzi da 0 a 9999,99.

Il prezzo più alto possibile, un servizio da 9999,99 € con opzioni da 999,99 €, sta comodo in un intero.

## Dove si vede

| Dove                                                                              | Che cosa                                                                                                                    |
| --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Pagina privata del cliente (`BookingConfirmationResource`)                        | `service.price_cents`, `options[].extra_price_cents`, `total_cents`; l'etichetta è « Prezzo al momento della prenotazione » |
| Lista e pagina di una prenotazione del professionista (`ProviderBookingResource`) | `price_cents` (il totale) e `options[].extra_price_cents`; « + X € » solo per un'opzione che costa qualcosa                 |
| Modulo di prenotazione, pubblico e del professionista                             | il catalogo di oggi, « Prezzo indicativo »: la prenotazione non esiste ancora                                               |
| Email « richiesta inviata » al cliente (`BookingReceivedNotification`)            | il totale salvato (`Booking::totalCents()`), scritto da `Money::format()`; « Prezzo al momento della prenotazione »         |

Il frontend non somma più niente: formatta i centesimi con `formatCents(locale, cents)` (`resources/js/lib/format.ts`).

**Le altre email, i promemoria e il messaggio WhatsApp restano senza prezzo**, come prima:

- danno al professionista e al cliente il servizio, le opzioni e l'orario; il prezzo si legge sulla pagina;
- non c'è un pagamento da confermare;
- un importo in un'email non si può più correggere, la pagina sì.

L'eccezione è l'email « richiesta inviata » al cliente: è la sua ricevuta, e il prezzo salvato non cambia più. `Money::format()` scrive i centesimi come `formatCents()` del frontend, a mano, perché l'immagine di produzione non ha l'estensione `intl`.

`BookingSavedPriceTest` controlla che le altre email non contengano un prezzo e che la lista delle notifiche in `app/Notifications` sia quella attesa: una notifica nuova deve dichiarare se può mostrare un prezzo.

## Le prenotazioni fatte prima del #97

La migrazione `2026_11_09_100000_add_saved_prices_to_bookings` le ha riempite con i prezzi **attuali** del servizio e delle opzioni, quelli del giorno della migrazione. Quelli del giorno della prenotazione non erano salvati da nessuna parte: **per le prenotazioni vecchie il prezzo è un'approssimazione**. Dal giorno della migrazione in poi, ogni prenotazione nuova ha il prezzo esatto.

Come funziona la migrazione:

1. aggiunge le due colonne come nullable;
2. le riempie a blocchi di 200 prenotazioni con il query builder; la tabella `booking_service_option` non ha `id`, quindi ogni riga si aggiorna con la coppia `booking_id` e `service_option_id`;
3. le rende obbligatorie (`nullable(false)->change()`).

Nessun SQL grezzo: gira uguale su SQLite, MySQL e PostgreSQL. Il `down()` toglie solo le due colonne, i prezzi del catalogo non vengono mai toccati; un nuovo `up()` riempie di nuovo con i prezzi di quel momento.

## Pagina di una prenotazione

`GET /provider/bookings/{id}` (`provider.bookings.show`, `ProviderBookingShowController`), nel gruppo `auth` + `verified`, pagina `resources/js/pages/provider/bookings/show.tsx`.

- **Solo le proprie prenotazioni**: la prenotazione si cerca tra quelle del professionista loggato. Un id di un altro professionista o inesistente dà **404**, mai 403, così la pagina non dice se quella prenotazione esiste. Solo cifre nell'indirizzo (`whereNumber`). Un utente senza professionista va alla configurazione.
- **Stessa scheda della lista**: `resources/js/components/provider-booking-card.tsx` (cliente, contatti, note, servizio e opzioni, data e ora di Roma, stato, prezzo salvato, « Avvisa su WhatsApp », pulsanti Conferma, Rifiuta e Annulla secondo `can_confirm`, `can_reject` e `can_cancel` del server). Nella lista la data di ogni scheda è un link alla sua pagina; sulla pagina è testo.
- In alto « Torna alle prenotazioni », il titolo (h1, dove va il focus se serve) e gli errori delle risposte rifiutate, annunciati come nella lista. Pagina privata: `X-Robots-Tag: noindex, nofollow` (`NoIndexPrivatePages`, come ogni pagina non pubblica) e, dal polling, `Cache-Control: no-store` (`PrivateProviderPage`, vedi « Aggiornamento automatico dello stato »).

**Dove si torna dopo una risposta.** I moduli della pagina (conferma, e la finestra di rifiuto o annullamento) mandano il campo nascosto `return=detail`. `App\Http\ProviderBookingReturn::url()` sceglie l'indirizzo: la pagina della prenotazione solo con esattamente quel valore, la lista in ogni altro caso (nessun valore, un'altra parola, un indirizzo). Mai il Referer, che non è affidabile. Lo usano allo stesso modo:

- il successo, con il suo toast (i tre controller);
- una risposta che lo stato non permette (`TransitionNotAllowed`);
- un messaggio troppo lungo (`RejectBookingRequest::getRedirectUrl()`);
- il limite di richieste delle risposte (`AppServiceProvider`, limiter `provider-services-manage`).

**L'email « nuova prenotazione »** (`NewBookingNotification`) ha un solo link: « Apri la richiesta per confermarla o rifiutarla », in HTML e nella versione testo, all'indirizzo assoluto della pagina (`APP_URL` nel worker della coda). Contiene solo l'id: mai il `public_token` né la pagina privata del cliente. Il link mostra la richiesta e non risponde mai da solo: conferma e rifiuto restano pulsanti `POST` sulla pagina, perché i filtri della posta aprono i link. Chi non è loggato passa dal login e poi torna alla pagina (`redirect()->intended()` di Fortify).

## Email « richiesta inviata » al cliente

`BookingReceivedNotification`, in coda come le altre email (tre tentativi, dopo 1 e 5 minuti; il job sparisce se la prenotazione non esiste più). La chiama `ProviderBookingController` subito dopo l'email al professionista.

- **Quando parte:** solo per il modulo pubblico e solo se il cliente ha lasciato un'email. Non parte con l'honeypot né per le prenotazioni che il professionista scrive nella propria agenda (`ProviderBookingStoreController`).
- **Lingua:** quella della prenotazione (`booking.locale`, con `Locales::forDelivery()`), italiano se quella lingua non ha testi.
- **Contenuto:** saluto con il nome, « Richiesta inviata a {professionista}: ti scriveremo quando risponde », servizio e opzioni, data e ora di Roma, il prezzo salvato, le note del cliente e il link privato (`bookings.show` con il `public_token`), con sotto l'avviso « Chiunque abbia questo link può vedere e annullare la prenotazione. Non inoltrare questa email. ». **Mai il telefono.**
- **Oggetto:** « Richiesta inviata: {servizio} ». Mai il link né il nome del cliente.
- **Testo libero:** viste Blade proprie (`mail.booking-received`, `mail.booking-received-text`), mai `MailMessage::line()`: nome e note sono sempre testo, anche con `<script>` o Markdown.
- **Il codice nella coda:** il job porta la prenotazione come id (`SerializesModels`); il `public_token` si rilegge dal database quando l'email viene scritta, quindi non finisce né in `jobs` né in `failed_jobs`. L'indirizzo email del cliente invece è nel job, come per le email di esito.
- **Se l'invio fallisce:** l'errore è segnalato con `report()` e la prenotazione resta valida, con il redirect alla pagina privata.

### Limite per indirizzo

Al massimo `config('booking.received_email_per_hour')` email di questo tipo (oggi 3) verso lo stesso indirizzo in un'ora, da qualunque IP. Il limite del modulo (`booking-store`) è per IP: senza questo, chi cambia IP potrebbe usare Griot per riempire la casella di un'altra persona.

- La chiave del `RateLimiter` è un HMAC SHA-256, con `APP_KEY`, dell'indirizzo in minuscolo e senza spazi ai lati (`BookingReceivedNotification::limiterKey()`): l'indirizzo non è mai in chiaro nella cache, e un semplice hash non si può risalire provando una lista di indirizzi noti. `Awa@Example.test` e `awa@example.test` contano insieme.
- Oltre il limite l'email non parte, la prenotazione resta valida e il log riceve solo « Booking received email skipped: hourly limit for one address reached. », senza l'indirizzo né altri dati.
- Il conteggio parte prima della coda: anche un invio poi fallito conta.

Ogni prenotazione dal modulo con un'email ora manda **due email** (al professionista e al cliente), più l'esito. Con Gmail nella fase privata il tetto è di 500 email al giorno ([deploy.md](deploy.md), sezione 4).

## Numeri di telefono leggibili (#177)

Il telefono del cliente si salva compatto (`PhoneNumber::normalize()`, per esempio `+393331234567`) e così resta: serve ai link `tel:` e `wa.me`. Per gli occhi c'è `PhoneNumber::display()`, nello stesso file:

| Paese         | Regola                                          | Esempio (numeri finti) |
| ------------- | ----------------------------------------------- | ---------------------- |
| Italia mobile | 10 cifre che iniziano con 3                     | +39 333 123 4567       |
| Camerun       | 9 cifre che iniziano con 6 (mobile) o 2 (fisso) | +237 6 71 23 45 67     |
| Francia       | 9 cifre, a coppie dopo la prima                 | +33 6 12 34 56 78      |

- I gruppi sono separati da U+00A0: il numero non va mai a capo a metà.
- **Tutto il resto torna com'era**: i fissi italiani (prefissi di lunghezza diversa), gli altri paesi (dove finisce il prefisso del paese non si sa senza una tabella), i numeri non internazionali. Il formato segue il paese del numero, mai la lingua della pagina. Nessuna libreria.
- **Camerun:** il piano nazionale a 9 cifre dal 21 novembre 2014, con « S » = 6 per i mobili e 2 per i fissi e la forma `+237 6 7X XX XX XX`, dalla comunicazione dell'ART pubblicata dall'ITU (« Cameroon (country code +237) Communication of 6.X.2014 », https://www.itu.int/dms_pub/itu-t/oth/02/02/T02020000240001PDFE.pdf, letta il 16 novembre 2026).
- **Dove:** la scheda di una prenotazione nell'area del professionista (elenco e dettaglio: la prop `customer_phone_display`, mentre `customer_phone` resta compatto), le tre email al professionista (nuova richiesta, promemoria, annullata dal cliente; HTML e testo), il WhatsApp della pagina pubblica. Nessuna migrazione: i numeri già salvati si scrivono al momento di mostrarli.
- **Il numero cliccabile (#207):** nella scheda il numero diventa un link `tel:` solo se il server manda `customer_phone_url`, cioè `tel:` più il numero di `PhoneNumber::normalize()`. È `null` per un numero che non si normalizza (le prenotazioni scritte dal professionista possono tenere il numero com'era scritto) e per una prenotazione anonima: allora resta testo. Il nome del link è il numero stesso; « Telefono: » resta fuori dal link. Le email al professionista restano testo.

## Prenotazioni scritte dal professionista: rifiniture (#112)

- **Il messaggio WhatsApp** di una prenotazione con `created_by_provider` dice « :provider ha registrato la tua prenotazione… » (`provider.bookings.whatsapp_text_created`): nasce confermata, ma nessuno l'ha chiesta sul sito. Le altre confermate tengono « ha confermato ».
- **Oltre il limite di richieste** (`provider-bookings`) l'invio torna alla pagina « Aggiungi prenotazione », non più all'elenco: il `<Form>` di Inertia, con un invio POST, tiene lo stato della pagina (`preserveState`), quindi i campi restano pieni; il messaggio è annunciato sopra il pulsante.
- **Profilo ritirato in un'altra scheda:** l'invio dice « Pubblica il profilo per aggiungere prenotazioni… », non più « orario non disponibile », e la pagina mostra la spiegazione al posto del modulo.
- **Un servizio che non è suo** (o non esiste): « Il servizio scelto non è tra i tuoi servizi attivi. », in seconda persona. La pagina pubblica e gli orari liberi tengono il testo per il cliente.
- **Il nome del cliente** si salva su una riga, in tutti e due i moduli: ogni gruppo di spazi, a capo compresi, diventa uno spazio (`BookingRules::customer()`). Così un nome non può scrivere righe che sembrano campi nelle email di testo.

## Indietro e Avanti: lo stato di nuovo dal server

Con Indietro e Avanti Inertia mostra di nuovo una pagina con le props salvate nella cronologia del browser quando la pagina è stata lasciata, senza chiedere niente al server. Lo stesso succede quando si esce verso un altro sito e si torna con Indietro: il browser scarica la pagina, ma Inertia usa comunque la cronologia. Una prenotazione confermata o annullata nel frattempo sembrerebbe ancora com'era, fino a un F5.

`resources/js/lib/live-pages.ts` (`refreshLivePagesOnHistory()`) chiede di nuovo al server **solo le props della prenotazione** di tre pagine, con `router.reload({ only })`:

| Pagina                                        | Componente                | Props ricaricate        |
| --------------------------------------------- | ------------------------- | ----------------------- |
| Lista del professionista                      | `provider/bookings/index` | `bookings`              |
| Pagina di una prenotazione del professionista | `provider/bookings/show`  | `booking`               |
| Pagina privata del cliente                    | `bookings/show`           | `booking`, `can_cancel` |

- **Quando:** all'evento `navigate` di Inertia con `type === 'history'`, e all'evento `pageshow` con `persisted`, cioè una pagina intera ripristinata dalla cache avanti/indietro del browser, che non lancia `navigate`.
- **Perché globale:** è registrato una volta in `app.tsx`, prima di `createInertiaApp`, come `syncDocumentLanguage()`. Con Indietro la pagina viene montata di nuovo, e un listener dentro la pagina potrebbe non esserci ancora quando arriva l'evento.
- **Che cosa non fa:** non ricarica la pagina intera, non cambia lo scroll né lo stato dei componenti (il ricarico di Inertia li conserva) e non interroga il server a intervalli (lo fa il polling, sezione seguente). Per un attimo si vede il dato della cronologia, poi quello nuovo.
- **Errori:** un 404 (la prenotazione non c'è più) o un errore di rete non mostrano il toast di `lib/http-errors.ts`: `onHttpException` e `onNetworkError` restituiscono `false`, e la pagina resta com'era.
- **I filtri della lista** restano: il ricarico usa l'indirizzo della pagina, query compresa.
- **La pagina pubblica del professionista** non è nella lista: gli orari liberi si chiedono solo dopo aver scelto un giorno, e con Indietro la scheda di prenotazione riparte chiusa.

## Aggiornamento automatico dello stato

Le tre pagine del ricarico dopo Indietro chiedono di nuovo al server, da sole, le stesse props: una prenotazione confermata in un'altra scheda, o annullata dal cliente dal telefono, cambia senza F5. Lo fa `useLiveProps()` (`resources/js/hooks/use-live-props.ts`), con il polling di Inertia (`usePoll`): un ricarico parziale che conserva lo scroll e lo stato dei componenti.

| Pagina                                        | Props                   | Ogni | Si ferma quando                                                                 |
| --------------------------------------------- | ----------------------- | ---- | ------------------------------------------------------------------------------- |
| Lista del professionista                      | `bookings`              | 20 s | mai per lo stato: può arrivare una richiesta nuova                              |
| Pagina di una prenotazione del professionista | `booking`               | 20 s | `can_confirm`, `can_reject` e `can_cancel` sono tutti falsi                     |
| Pagina privata del cliente                    | `booking`, `can_cancel` | 30 s | la prenotazione è annullata o è già iniziata (ora di Roma, `lib/local-time.ts`) |

Il polling si ferma o si sospende anche:

- **in una scheda nascosta** (`background: 'pause'`). Quando la scheda torna visibile, se l'intervallo è passato, Inertia ricarica subito;
- **al primo errore** (una pagina che non esiste più, un errore del server, la rete): si ferma per sempre, senza il toast di `lib/http-errors.ts`. Per queste richieste `onHttpException` e `onNetworkError` restituiscono `false`, e così fermano solo per loro gli eventi globali di Inertia;
- **con una finestra di rifiuto o di annullamento aperta, o durante l'invio di un modulo** (`PauseLiveProps`): un nuovo stato potrebbe togliere la finestra e il testo scritto. Per il cliente, `can_cancel` dipende anche dall'ora e non cambia mentre la finestra è aperta;
- **dopo 30 minuti senza interazione** (clic, tasto, puntatore, tocco), così una scheda dimenticata non tiene viva la sessione. Alla prima interazione riparte con un ricarico immediato.

**La sessione scaduta.** Una richiesta senza login riceve il redirect al login, che è una pagina Inertia valida. Inertia la mostra e il polling finisce, perché la pagina non c'è più. Dopo il login si torna alla pagina (`intended`). Il client non può fermare una risposta già arrivata senza agganciarsi a parti interne di Inertia. Con il polling attivo, d'altra parte, la sessione non scade: succede solo dopo i 30 minuti di pausa, al ritorno dell'utente, oppure dopo un logout in un'altra scheda, e in quel caso nascondere i dati dei clienti è giusto.

**Nella lista filtrata** una prenotazione che non appartiene più al filtro sparisce. Se il focus era nella sua scheda, va al titolo della pagina (`tabIndex={-1}`), come dopo una risposta che toglie la scheda.

**Lo stato cambiato** si annuncia in una regione `role="status"` (`components/live-status.tsx`): sempre nella pagina, vuota all'inizio, scritta solo quando il polling porta un cambiamento. Nella lista c'è un solo annuncio riassuntivo.

**Perché il polling e non il broadcasting:** niente server WebSocket (Reverb o Pusher) da far girare e pagare sul piano di Render, niente canali privati da autorizzare per un cliente senza account, niente observer sul modello. Le prenotazioni cambiano poche volte al giorno, e un ritardo di 20-30 secondi va bene. Le quattro GET (`dashboard`, la lista, la pagina di una prenotazione e `bookings.show`) non hanno limiti di richieste, e un test lo controlla.

**Niente cache:** la lista e la pagina di una prenotazione del professionista rispondono con `Cache-Control: no-store` (middleware `PrivateProviderPage`, lo stesso dell'anteprima), perché contengono nome, telefono e note dei clienti. La dashboard non mostra dati dei clienti e resta com'era.

## Barra dell'area del professionista

Le sezioni dell'area stanno nella barra a sinistra (`components/app-sidebar.tsx`, `components/nav-main.tsx`); ognuna resta una pagina con il suo indirizzo, quindi Indietro e l'aggiornamento automatico funzionano come prima. La dashboard tiene solo il saluto, la riga delle richieste in attesa e la pubblicazione.

| Gruppo         | Voci                                                                          |
| -------------- | ----------------------------------------------------------------------------- |
| (senza nome)   | Panoramica (`dashboard`)                                                      |
| Agenda         | Prenotazioni, con il numero delle richieste in attesa                         |
| La mia offerta | Servizi, Disponibilità                                                        |
| Vetrina        | Profilo, Anteprima (sempre), Pagina pubblica (solo con il profilo pubblicato) |

- **Voce attiva** per segmenti interi dell'indirizzo: « Prenotazioni » resta attiva sulla pagina di una prenotazione e su quella nuova, « Servizi » sulla modifica di un servizio e sulle sue opzioni.
- **Il numero** conta le richieste che aspettano una risposta e si possono ancora confermare: `Booking::awaitingAnswer()`, cioè in attesa, non anonime e con l'inizio nel futuro. È la condizione di `BookingTransitions::canConfirm()` scritta per il database (un test confronta le due), e `awaitingReminder()` dei promemoria parte dallo stesso scope.
- **Può essere minore dell'elenco filtrato per « in attesa »**: il filtro mostra anche le richieste in attesa già iniziate o passate, che non si possono più confermare ma solo rifiutare. La riga della dashboard porta a quell'elenco (`?status=pending`).
- **La prop condivisa** `provider_nav` (`pending_requests`, `public_slug`) nasce da `ShareProviderNavigation`, un middleware messo solo sul gruppo dell'area del professionista e sulle impostazioni: mai nelle pagine pubbliche, anche per un professionista collegato; `null` per chi non ha un provider. È una closure: un solo COUNT quando la pagina viene disegnata, e nei ricarichi parziali solo se è nell'elenco `only`. Inertia tiene le prop condivise per tutto il processo, quindi il middleware rimette dopo la risposta quelle che c'erano prima.
- **Query:** dashboard da 3 a 4, elenco delle prenotazioni da 5 a 6, impostazioni del profilo 2 e 3 (il provider della relazione, letto una volta, serve anche a `has_bookings`).
- **Aggiornamento:** l'elenco e la pagina di una prenotazione chiedono `provider_nav` insieme alle loro props, nel polling e dopo Indietro (`LIVE_PAGES` in `lib/live-pages.ts`). Sulle altre pagine il numero cambia alla navigazione: nessun polling nuovo.

## Cambio lingua dopo la pagina privata

La pagina privata del cliente aggiunge `<meta name="referrer" content="no-referrer">`, per non mandare il codice segreto ad altri siti. Il browser tiene quella politica per tutta la scheda, anche dopo Indietro, quindi le richieste successive partono senza `Referer`. Il cambio lingua rispondeva con `back()`, che senza `Referer` usa l'ultimo URL salvato in sessione. Quell'URL poteva essere la richiesta degli orari liberi: Inertia riceveva un JSON e mostrava un errore.

- Il selettore (`locale-switcher.tsx`) manda anche l'indirizzo della propria pagina (`return_to`, da `usePage().url`). `LocaleController` lo usa solo se `UpdateLocaleRequest::returnTo()` lo accetta:
    - inizia con una sola `/`;
    - non contiene `\` né caratteri di controllo;
    - è lungo al massimo 2048 caratteri.

    Altrimenti viene ignorato e si usa `back()` come prima; la lingua cambia comunque.

- La richiesta degli orari (`use-free-slots.ts`) manda `X-Requested-With: XMLHttpRequest`, come le visite Inertia, così Laravel non la salva come « URL precedente ».
- Sulla pagina privata, `return_to` contiene il codice segreto. Viaggia nel corpo di un `POST` verso lo stesso sito, mai in un indirizzo, quindi non finisce nei log di accesso; Griot non lo scrive nei log. Il codice è già nella sessione come « URL precedente » quando la pagina viene aperta dal link.

## Aggiungi a Google Calendar

Nella scheda di una prenotazione del professionista (`components/provider-booking-card.tsx`, nella lista e nella pagina della prenotazione), accanto a « Avvisa su WhatsApp »: un link che apre Google Calendar con l'evento già scritto, e il professionista lo salva. Nessuna API, nessuna chiave, nessun account collegato: un indirizzo costruito sul server (`App\Booking\GoogleCalendarLink`, campo `google_calendar_url` di `ProviderBookingResource`).

- **Quando c'è:** solo per una prenotazione **confermata**, non resa anonima e che non è ancora cominciata (confronto con l'ora del server). Anche per quelle scritte dal professionista, che nascono confermate. Mai per una richiesta in attesa, rifiutata o annullata, né per una passata.
- **Che cosa c'è nell'evento:**
    - titolo « servizio – nome del cliente » (il nome come è salvato; senza nome, solo il servizio);
    - inizio e fine come istanti UTC con la Z (`20261119T130000Z`), quindi il cambio dell'ora legale non richiede niente; `ctz` è il fuso delle prenotazioni (`booking.timezone`, `Europe/Rome`);
    - nei dettagli « Prenotazione su Griot: » con l'indirizzo della prenotazione nell'area del professionista (solo l'id, dietro accesso) e i nomi delle opzioni scelte, nella lingua della pagina del professionista.
- **Che cosa non c'è mai:** il prezzo, le note, il telefono, l'email, il motivo di un annullamento, il luogo e il link privato del cliente. `GoogleCalendarLink` non legge mai il codice segreto (un test lo controlla, anche sul testo decodificato).
- **Il link:** si apre in una nuova scheda (`target="_blank" rel="noopener noreferrer"`, con il testo nascosto « si apre in una nuova scheda »), quindi Google non riceve nemmeno l'indirizzo della pagina da cui arriva. Il testo è codificato (RFC 3986): accenti, `&`, `#` e apostrofi arrivano interi. Pulsante del kit con l'icona `CalendarPlus` decorativa (`aria-hidden`), 44 px sui telefoni.
- **Avviso sotto il pulsante:** « Se la prenotazione viene annullata, l'evento resta nel tuo calendario. » Il sito non ha nessun collegamento con Google: un annullamento o un cambio dopo il salvataggio non arrivano all'evento.
- **Che cosa riceve Google:** solo quando il professionista clicca, e solo quello che è nel link, più quello che il browser manda sempre (indirizzo IP, e i cookie di Google se il professionista ha l'accesso a Google). L'informativa lo dice (`privacy.recipients.google_calendar`, [legal.md](legal.md)).
- **Rischio:** il formato del link (`calendar.google.com/calendar/render?action=TEMPLATE&text=…&dates=…&ctz=…&details=…`) **non è documentato ufficialmente da Google**: è quello che i pulsanti « aggiungi al calendario » usano da anni, descritto solo da fonti di terzi. Se Google lo cambiasse, il pulsante aprirebbe un evento vuoto o la pagina del calendario; il sito non ha errori.

### Il file .ics (Apple Calendar, Outlook)

Accanto a « Aggiungi a Google Calendar », il pulsante « Aggiungi al calendario (Apple, Outlook, altri) » scarica lo stesso evento come file, per i calendari che non sono Google (Apple Calendar, Outlook e ogni calendario che apre un `.ics`).

- **Stessa regola, scritta una volta:** quando c'è l'evento e che cosa contiene li decide `App\Booking\CalendarEvent`, usato sia da `GoogleCalendarLink` sia da `App\Booking\IcsFile`. Il pulsante compare con quello di Google; per una prenotazione senza evento il file risponde 404.
- **Rotta:** `GET provider/bookings/{booking}/calendar.ics` (`provider.bookings.calendar`, `ProviderBookingCalendarController`), nello stesso gruppo delle altre pagine delle prenotazioni: accesso obbligatorio, la prenotazione cercata solo fra quelle del professionista (un id di un altro dà lo stesso 404) e la policy `manage`. Intestazioni delle pagine private (`PrivateProviderPage`: `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`), `Content-Type: text/calendar; charset=utf-8` e `Content-Disposition: attachment; filename="prenotazione-{id}.ics"`: nel nome del file solo l'id.
- **Formato (RFC 5545):** `VCALENDAR` con `VERSION:2.0`, `PRODID:-//Griot//Prenotazioni//IT`, `CALSCALE:GREGORIAN`, `METHOD:PUBLISH` e un `VEVENT` con `UID`, `DTSTAMP`, `DTSTART` e `DTEND` in UTC con la Z, `SUMMARY` e `DESCRIPTION`.
    - **UID stabile:** `booking-{id}@{host di APP_URL}`. Scaricare e aprire di nuovo il file della stessa prenotazione aggiorna l'evento invece di raddoppiarlo (nei calendari che seguono lo standard).
    - Ogni riga finisce con CRLF; virgole, punti e virgola, backslash e a capo sono preceduti da `\`; una riga oltre 75 byte va a capo con uno spazio all'inizio della riga dopo, senza mai spezzare un carattere (accenti, emoji: `mb_strcut`).
- **Privacy:** gli stessi dati dell'evento di Google, mai telefono, email, note, prezzo, motivo dell'annullamento né il link privato del cliente. Il file si scarica sul dispositivo del professionista: **nessun terzo riceve niente** dal sito. Come per Google, un evento salvato non segue un annullamento: la riga d'avviso sotto i due pulsanti vale per entrambi.
- Link normale con `download` (mai il `Link` di Inertia, perché è un file), senza `NewTabHint` perché non apre una scheda; icona `Download` decorativa (`aria-hidden`); 44 px sui telefoni, come i pulsanti del kit.

## Test

- `tests/Feature/ServiceDurationTest.php` (#53):
    - la minima vuota salvata uguale alla massima, all'aggiunta e alla modifica; una minima scritta salvata com'è; minima più lunga della massima rifiutata; massima obbligatoria;
    - la regola invariata (treccine 180 + 30 alle 14:00: orari, `end_time` e `ends_at` alle 17:30) e la minima che non cambia né gli orari né la fine;
    - la fine mostrata prima di prenotare uguale a quella della conferma e delle email;
    - i form (il tempo da tenere libero per primo e obbligatorio, l'esempio delle 09:00, il componente comune), la riga « fino alle … al massimo », nessun `aria-label` scritto a mano;
    - i testi in tre lingue con gli spazi del francese, mai « calendario » negli aiuti; le durate in ore e minuti in `format.ts` (solo minuti, solo ore, 5 minuti, 12 ore).

- `tests/Feature/BookingSavedPriceTest.php`:
    - `Money`;
    - il salvataggio in `CreateBooking`, anche con un prezzo cambiato tra modulo e salvataggio e per le prenotazioni del professionista;
    - la factory e il seeder;
    - le email senza prezzo, tranne « richiesta inviata », che mostra il totale salvato;
    - `Money::format()` nelle tre lingue.
- `tests/Feature/Booking/SavedPriceMigrationTest.php`: la migrazione, `down()` compreso.
- `ProviderBookingsListTest` e `BookingConfirmationTest`: i prezzi mostrati sono quelli salvati, anche dopo un cambio del catalogo o un'opzione nascosta.
- Nei test, una prenotazione fatta con la factory riceve le opzioni con `attachBookingOptions()` (`tests/Pest.php`), che salva il loro prezzo attuale come `CreateBooking`.
- `tests/Feature/BookingReceivedNotificationTest.php`: l'email « richiesta inviata », cioè quando parte e quando no (senza email, honeypot, prenotazione del professionista), la lingua, il link privato con l'avviso, niente telefono, prezzo e note, l'oggetto, l'escaping, la coda, il payload del job senza il `public_token`, l'errore in coda, il limite per indirizzo (la quarta nell'ora, indirizzi diversi, maiuscole), i testi nelle tre lingue.
- `tests/Feature/LocaleReturnTest.php`: il cambio lingua torna alla pagina mandata, anche senza `Referer` e con gli orari letti prima; gli indirizzi rifiutati (un altro host, `\`, uno schema, a capo, un array, troppo lungo) lasciano `back()` e cambiano comunque la lingua; la pagina privata; gli orari con `X-Requested-With` non diventano l'« URL precedente »; i sorgenti del selettore e di `use-free-slots.ts`.
- `tests/Feature/LiveBookingStatusTest.php`: `no-store` sulla lista e sulla pagina di una prenotazione e non sulle altre pagine; nessun limite sulle quattro GET; dai sorgenti, `usePoll` con `pause` e gli intervalli, le props uguali a `live-pages.ts`, quando si ferma (stato, errore, finestra, inattività), la regione `role="status"` e il focus; i testi nelle tre lingue.
- `tests/Feature/SprintTenPolishTest.php`: il link del telefono (#207), il seeder con `BookingDuration`, la guardia di `FreeSlots`, il nome su una riga, il limite del profilo, le classi condivise, il testo WhatsApp delle prenotazioni del professionista, l'ordine delle categorie, il titolo delle impostazioni.
- `tests/Feature/PhoneNumberDisplayTest.php`: `PhoneNumber::display()` (i tre paesi, U+00A0, tutto il resto com'era, la lingua della pagina che non conta), la scheda, la pagina pubblica, le tre email in HTML e in testo.
- `tests/Feature/ProviderNavigationTest.php`: `awaitingAnswer()` uguale a `canConfirm()` su un dataset (futura, all'inizio esatto, iniziata, passata, confermata, annullata, anonima); `provider_nav` con il numero giusto, solo del professionista, `null` senza provider, assente nelle pagine pubbliche anche dopo una pagina dell'area; il ricarico parziale; un solo COUNT e le query delle pagine; dai sorgenti, i gruppi e le voci della barra, le rotte, la condizione di « Pagina pubblica », `aria-current`, il testo nascosto con il plurale, la dashboard senza pulsanti, gli elenchi `only`, il selettore di lingua (#202); i testi nelle tre lingue.
- `tests/Feature/LivePagesTest.php`: il ricarico dopo Indietro. Per le tre pagine, lo stato cambiato con i servizi veri (`ManageBooking`, `CancelBooking`) dopo l'apertura arriva con `reloadOnly()`, senza le altre props; la mappa di `live-pages.ts` corrisponde ai componenti e alle props dei controller; il listener controlla `history` e `persisted` ed è registrato prima di `createInertiaApp`.
- `tests/Feature/ProviderBookingDetailTest.php`: la pagina di una prenotazione (404 per le altre, login e ritorno, campi), il link dell'email « nuova prenotazione » in HTML e in testo, dove si torna dopo ogni risposta (successo, rifiuto dello stato, messaggio troppo lungo, limite di richieste, valori di `return` diversi da `detail`), i testi nelle tre lingue.
