# Griot: roadmap degli sprint

Oct 17, 2026 · @Franks Toscani Koudja

## Panoramica

Griot mette in contatto chi cerca il savoir-faire africano (bellezza, couture, ristorazione e altro) con i professionisti che lo praticano; il piano porta l'MVP da dati di prova a un sito online in 8 sprint di una o due settimane.

**Scope dell'MVP**

- Solo scoperta dei provider e prenotazione.
- Nessuna gestione dei pagamenti.
- I clienti non hanno account: prenotano con nome e telefono.
- Solo i provider hanno un account.
- Le durate dei servizi sono variabili (minima e massima), e le prenotazioni non devono sovrapporsi.

**Stack**

- Laravel 13, Inertia.js, React con TypeScript.
- MySQL in locale, database di produzione da decidere nello Sprint 8.
- Qualità del codice: Pest (test), Pint (stile PHP), PHPStan (analisi PHP), controllo del frontend con `vp`. Il comando unico è `composer ci:check`.

**Metodo di lavoro**

- Sprint da una settimana (circa 10 ore), una PR per user story, `main` protetta.
- Commit nel formato `tipo: descrizione` (feat, fix, docs, refactor, test, chore, style).
- Un passo alla volta: il progetto serve anche per imparare.

**Stato attuale (Sprint 0 chiuso)**

- Repo su GitHub con `main` protetta e flusso a branch e PR.
- Schema del database completo: 8 tabelle con migrazioni, modelli, factory e test; seeder di sviluppo con 4 provider di esempio.
- Rimandati a fine progetto: chiusura della registrazione, controlli automatici su GitHub e deploy su Render.

## Sprint 1: scoperta pubblica

Chi visita il sito trova un provider e vede cosa offre, senza account; a fine sprint, con i dati del seeder, la navigazione funziona da telefono. Stima: 1 settimana.

**Elenco dei provider** _Come cliente voglio vedere i provider disponibili, per scegliere a chi rivolgermi._

- Compaiono solo i provider con `is_published = true`.
- Ogni card mostra nome, città, avatar e le categorie dei suoi servizi.
- L'elenco è paginato.

**Filtri per categoria e città** _Come cliente voglio filtrare per categoria e città, per trovare chi mi serve vicino a me._

- Il filtro categoria mostra i provider con almeno un servizio attivo in quella categoria.
- Il filtro città mostra le città dei provider pubblicati.
- I filtri si combinano e restano nell'URL, quindi il link è condivisibile.
- Se non c'è nessun risultato, compare un messaggio chiaro.

**Scheda del provider** _Come cliente voglio vedere la scheda di un provider, per decidere se prenotare._

- Indirizzo del tipo `/providers/{slug}`.
- Mostra bio, servizi attivi con prezzo e durata (da min a max), opzioni con il loro extra, orari settimanali, contatti WhatsApp e Instagram.
- Un provider non pubblicato o inesistente dà un 404.

**Uso da telefono** _Come cliente uso il sito dal telefono, perché è lì che oggi usa WhatsApp._

- Elenco e scheda leggibili a 375 px di larghezza, senza scorrimento orizzontale.

**Attività tecniche**

- Rotte, controller sottili e API Resource per passare i dati alle pagine Inertia, mai modelli grezzi.
- Query con Eloquent e `with()`, per non interrogare il database una volta per ogni provider.
- Pagine React in TypeScript: elenco, scheda e componenti riusabili (card del provider, filtri).
- Test Pest per ogni story: provider non pubblicato escluso, filtri combinati, 404.
- `composer ci:check` verde a ogni PR, una PR per story.

**Da decidere durante lo sprint**

- La categoria non è del provider ma dei suoi servizi: le categorie di un provider si ricavano dai servizi attivi.
- Lingua dell'interfaccia: aggiungere le traduzioni a pagine già scritte costa più che farlo dall'inizio, quindi conviene decidere prima di scrivere le pagine.

## Sprint 2: calcolo degli slot liberi

Dati un provider, un servizio, le opzioni scelte e un giorno, il sistema restituisce gli orari prenotabili; è la logica più delicata del progetto, quindi qui c'è codice di dominio e molti test, senza interfaccia. Stima: 2 settimane.

**Regole da fissare all'inizio**

| Decisione                            | Proposta                                                                                                                                            |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Quale durata blocca lo slot          | `duration_max` più gli extra delle opzioni scelte: evita le sovrapposizioni, ma riduce gli slot offerti. Da confermare con i provider intervistati. |
| Ogni quanto parte uno slot           | Ogni 30 minuti, dentro le fasce di disponibilità.                                                                                                   |
| Quali prenotazioni occupano il tempo | Quelle `pending` e `confirmed`; le `cancelled` liberano lo slot.                                                                                    |
| Anticipo minimo                      | Nessuno slot nel passato, più un margine configurabile (per esempio 2 ore).                                                                         |
| Finestra massima                     | Si prenota fino a 60 giorni avanti.                                                                                                                 |

**Fasce di disponibilità di un giorno** _Come sistema voglio sapere in quali fasce un provider lavora in un dato giorno, per partire dagli orari giusti._

- Si usa l'Enum `Weekday` (lunedì = 1) e `dayOfWeekIso` di Carbon, mai `dayOfWeek`.
- Più fasce nello stesso giorno sono ammesse (per esempio 9-13 e 15-19).
- Un giorno senza fasce non produce slot.

**Durata effettiva di una prenotazione** _Come sistema voglio calcolare quanto dura una prenotazione, per sapere quanto tempo occupa._

- Durata = `duration_max` del servizio più la somma delle `extra_duration` delle opzioni scelte.
- Le opzioni devono appartenere al servizio, altrimenti errore di validazione.

**Slot liberi** _Come cliente voglio vedere gli orari in cui posso prenotare, per scegliere quello giusto._

- Uno slot è valido solo se l'intera durata sta dentro una fascia di disponibilità.
- Uno slot è escluso se si sovrappone a una prenotazione `pending` o `confirmed` dello stesso provider.
- Gli slot nel passato o dentro il margine minimo sono esclusi.
- Due prenotazioni consecutive (una finisce quando l'altra inizia) non si considerano sovrapposte.

**Fusi orari** _Come provider voglio che "dalle 9 alle 13" voglia dire 9-13 a Bologna, tutto l'anno._

- Le fasce sono orari locali di `Europe/Rome`, il database resta in UTC.
- Il calcolo converte con attenzione, e i test coprono i due giorni di cambio tra ora solare e legale.

**Endpoint degli slot** _Come frontend voglio una chiamata che restituisca gli slot di un giorno, per mostrarli nella pagina di prenotazione._

- Input: provider, servizio, opzioni e data. Output: elenco di orari di inizio.
- Dati non validi (servizio di un altro provider, opzioni non coerenti, data fuori finestra) danno un errore chiaro.

**Attività tecniche**

- Una classe di dominio dedicata (Action o Service) con il calcolo, fuori da controller e modelli.
- Controller sottile, Form Request per la validazione, API Resource per la risposta.
- Test Pest in abbondanza: giorno senza fasce, più fasce, prenotazione che copre mezza fascia, slot che finiscono a fine fascia, prenotazioni cancellate, slot nel passato, cambio ora legale.
- Calcoli sulle date in PHP con Carbon e non in SQL: i test girano su SQLite e l'app su MySQL, e un confronto di date potrebbe comportarsi in modo diverso.
- `composer ci:check` verde a ogni PR, una PR per story.

**Da tenere presente**

Il controllo degli slot non protegge da due clienti che prenotano nello stesso istante: quel caso si risolve nello Sprint 3, dove si crea la prenotazione.

## Sprint 3: prenotazione pubblica (senza account)

Un cliente sceglie servizio, opzioni e orario, lascia i suoi dati e la prenotazione viene creata senza sovrapposizioni; a fine sprint il percorso completo funziona dal telefono. Stima: 2 settimane.

**Decisioni da fissare all'inizio**

| Decisione                               | Proposta                                                                                                |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Stato iniziale                          | `pending`: il provider deve confermare.                                                                 |
| Dati del cliente                        | Nome e telefono obbligatori, email e note facoltative.                                                  |
| Come il cliente ritrova la prenotazione | Un link con un codice segreto lungo e casuale, mostrato alla fine e inviato per email se l'ha lasciata. |
| Annullamento da parte del cliente       | Possibile dal link, fino a un certo anticipo (per esempio 24 ore prima).                                |
| Notifica al provider                    | Email nell'MVP; WhatsApp resta una possibilità futura.                                                  |
| Protezione dallo spam                   | Limite di richieste per IP e campo trappola nascosto (honeypot), senza captcha per ora.                 |
|                                         |                                                                                                         |

**Form di prenotazione** _Come cliente voglio scegliere servizio, opzioni e orario dalla scheda del provider, per prenotare._

- Il form parte dalla scheda del provider (Sprint 1) e usa gli slot dello Sprint 2.
- Mostra prezzo e durata totali aggiornati alle opzioni scelte.
- Dati mancanti o non validi danno messaggi chiari, in italiano.

**Creazione sicura della prenotazione** _Come sistema voglio creare la prenotazione solo se lo slot è ancora libero, per non avere sovrapposizioni._

- Lo slot viene ricontrollato al momento dell'inserimento, dentro una transazione con un blocco sul provider (per esempio `lockForUpdate`).
- Se lo slot nel frattempo è stato preso, il cliente vede un messaggio e gli slot aggiornati.
- Si verifica che il servizio appartenga al provider e che le opzioni appartengano al servizio.
- `provider_id` viene impostato dal codice tramite la relazione, mai dall'input.
- Test con due richieste per lo stesso slot: ne passa una sola.

**Pagina di conferma** _Come cliente voglio vedere un riepilogo dopo la prenotazione, per sapere che è andata a buon fine._

- Mostra provider, servizio, opzioni, orario in ora di Bologna, prezzo e stato `in attesa di conferma`.
- Contiene il link privato alla prenotazione.

**Avviso al provider** _Come provider voglio ricevere una notifica quando arriva una prenotazione, per rispondere in fretta._

- Email con i dati della prenotazione.
- L'invio avviene in coda e non blocca la risposta al cliente; un errore di invio non annulla la prenotazione.

**Gestione da parte del cliente** _Come cliente voglio rivedere o annullare la mia prenotazione dal link, senza account._

- Il codice del link non è indovinabile e non è l'`id` numerico.
- L'annullamento rispetta l'anticipo minimo e libera lo slot.
- Un link non valido dà un 404.

**Protezione dallo spam** _Come provider voglio che l'agenda non si riempia di prenotazioni false._

- Limite di richieste per IP sul form.
- Campo honeypot: se compilato, la richiesta viene scartata in silenzio.

**Attività tecniche**

- Un'Action di dominio (per esempio `CreateBooking`) con transazione e controllo dello slot, che riusa il calcolo dello Sprint 2.
- Controller sottile e Form Request per la validazione.
- Una migrazione per il codice segreto della prenotazione (non è nello schema attuale): colonna unica.
- Notifica Laravel per l'email, in coda.
- Test Pest per concorrenza, coerenza servizio e opzioni, stati e annullamento.
- `composer ci:check` verde a ogni PR, una PR per story.

**Limite da conoscere**

Il blocco `lockForUpdate` funziona su MySQL, ma i test girano su SQLite in memoria, che non lo supporta allo stesso modo: il test della concorrenza reale va fatto a mano su MySQL, oppure con un test dedicato su quel database.

## Sprint 4: area provider, parte 1 (login e profilo)

Un provider entra con il suo account e gestisce il profilo pubblico che i clienti vedono nella scheda; a fine sprint, con gli utenti del seeder, puoi entrare, modificare il profilo e vederne il risultato sul sito. Stima: 1 settimana.

**Decisioni da fissare all'inizio**

| Decisione                     | Proposta                                                                                                                                                                                        |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Utente senza profilo provider | Dopo il login va a una pagina "completa il tuo profilo" che crea il `Provider` collegato. È il caso del `Test User` del seeder e di chiunque si registri, finché la registrazione resta aperta. |
| Pubblicazione del profilo     | `is_published` parte a `false`; si può pubblicare solo con i campi minimi compilati (nome, città, almeno un servizio e una disponibilità).                                                      |
| Slug                          | Generato dal nome, modificabile, unico; se cambia, il vecchio indirizzo smette di funzionare.                                                                                                   |
| Immagine del profilo          | Caricamento con limiti di formato e peso, su disco locale; l'archiviazione in produzione si decide nello Sprint 8.                                                                              |
| Contatti                      | WhatsApp e Instagram facoltativi, con controllo del formato.                                                                                                                                    |

**Area riservata ai provider** _Come provider voglio entrare nella mia area, per gestire la mia attività._

- Login e logout del kit funzionanti, pagine dell'area protette.
- Un utente non loggato viene mandato al login.
- Un utente con provider vede la propria area; uno senza vede la pagina "completa il tuo profilo".

**Creazione del profilo provider** _Come nuovo provider voglio creare il mio profilo, per comparire sul sito._

- Campi: nome, città, bio, WhatsApp, Instagram.
- Il provider si crea tramite la relazione (`$user->provider()->create(...)`), mai con `user_id` dall'input.
- Un utente può creare un solo provider; un secondo tentativo viene bloccato.

**Modifica del profilo** _Come provider voglio modificare i dati del mio profilo, per tenerli aggiornati._

- Una Policy garantisce che ognuno modifichi solo il proprio profilo: un altro provider riceve 403.
- Validazione con Form Request, messaggi in italiano.
- Lo slug resta unico anche in modifica (ignorando se stesso).

**Immagine del profilo** _Come provider voglio caricare una foto, per farmi riconoscere._

- Formati consentiti (per esempio JPG, PNG, WebP) e peso massimo.
- La nuova foto sostituisce la vecchia, che viene cancellata dal disco.
- Un file non valido dà un errore chiaro e non salva niente.

**Pubblicazione** _Come provider voglio decidere quando il mio profilo diventa visibile, per non mostrare una scheda incompleta._

- L'interruttore "pubblicato" funziona solo con i requisiti minimi soddisfatti, altrimenti dice cosa manca.
- Un profilo non pubblicato non compare nell'elenco e dà 404 alla scheda (Sprint 1).

**Attività tecniche**

- Controller sottili, Form Request e API Resource per le pagine Inertia.
- `ProviderPolicy` registrata e testata.
- Gestione dei file con lo storage di Laravel e link simbolico per servire le immagini.
- Pagine React in TypeScript: area riservata, form profilo, caricamento immagine.
- Test Pest: Policy (403 su profilo altrui), un solo provider per utente, validazione dello slug, pubblicazione con e senza requisiti.
- `composer ci:check` verde a ogni PR, una PR per story.

**Da tenere presente**

Finché la registrazione resta aperta (fino allo Sprint 8), chiunque può creare un account e un profilo: la prima story va scritta pensando al caso dell'utente senza provider.

## Sprint 5: area provider, parte 2 (servizi, opzioni e disponibilità)

Il provider costruisce da solo la propria offerta: cosa vende, con quali extra e quando lavora; a fine sprint tutto ciò che il seeder inserisce a mano si crea e si modifica dall'interfaccia, e il risultato si vede subito nella scheda pubblica e negli slot. Stima: 1-2 settimane.

**Decisioni da fissare all'inizio**

| Decisione                            | Proposta                                                                                                        |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------- |
| Cancellare un servizio               | Se non ha prenotazioni si elimina; se ne ha, si disattiva (`is_active = false`), così lo storico resta intatto. |
| Durata                               | `duration_min` non può superare `duration_max`; passo di 5 minuti.                                              |
| Modifiche con prenotazioni esistenti | Cambiare durata, prezzo o disponibilità non tocca le prenotazioni già fatte, vale per le nuove.                 |
| Fasce di disponibilità               | Orari locali di Bologna, inizio prima della fine, fasce dello stesso giorno non sovrapposte.                    |
| Categoria del servizio               | Scelta da un elenco fisso, gestito da te (per ora dal seeder).                                                  |
| Ordine dei servizi                   | Per ora in ordine di creazione.                                                                                 |

**Elenco e creazione dei servizi** _Come provider voglio aggiungere i miei servizi, per metterli in vendita._

- Campi: nome, descrizione, categoria, prezzo, durata min e max.
- Il servizio si crea tramite la relazione (`$provider->services()->create(...)`), mai con `provider_id` dall'input.
- `category_id` viene validato con una regola `exists`.
- Il nuovo servizio compare nella scheda pubblica solo se attivo e se il profilo è pubblicato.

**Modifica, disattivazione ed eliminazione** _Come provider voglio modificare o togliere un servizio, per tenere l'offerta aggiornata._

- Una Policy permette di toccare solo i propri servizi: un altro provider riceve 403.
- Un servizio con prenotazioni non si elimina, e l'interfaccia propone di disattivarlo.
- Un servizio disattivato sparisce dagli slot e dalla scheda, ma le sue prenotazioni restano visibili.

**Opzioni ed extra** _Come provider voglio aggiungere extra a un servizio, per vendere varianti._

- Campi: nome, extra di prezzo, extra di durata.
- Stesse regole di Policy e di creazione tramite relazione.
- Un'opzione già usata in una prenotazione non si elimina, e si può nascondere.

**Disponibilità settimanale** _Come provider voglio indicare quando lavoro, per ricevere prenotazioni solo in quei momenti._

- Per ogni giorno si possono avere una o più fasce, usando l'Enum `Weekday`.
- Validazione: fine dopo l'inizio e nessuna sovrapposizione tra fasce dello stesso giorno.
- Una fascia modificata cambia subito gli slot offerti (Sprint 2), senza toccare le prenotazioni esistenti.

**Anteprima per il provider** _Come provider voglio vedere come i clienti vedono la mia offerta, per controllare di aver fatto bene._

- Un link alla propria scheda pubblica, anche se non ancora pubblicata, visibile solo a lui.

**Attività tecniche**

- Controller sottili, Form Request e API Resource, come negli sprint precedenti.
- Policy per `Service`, `ServiceOption` e `Availability`, ognuna testata (403 sui dati altrui).
- Pagine React in TypeScript: elenco e form dei servizi, gestione delle opzioni, griglia settimanale delle disponibilità.
- Test Pest per validazioni, eliminazione contro disattivazione, sovrapposizione tra fasce e Policy.
- `composer ci:check` verde a ogni PR, una PR per story.

**Da tenere d'occhio**

- Le story su servizi, opzioni e disponibilità sono simili (elenco, form, Policy): conviene estrarre i pezzi comuni, ma solo dopo tre casi concreti.
- Il prezzo pagato non è "congelato" nella prenotazione: oggi non c'è una colonna per quello. Da valutare dopo.

## Sprint 6: area provider, parte 3 (calendario e gestione delle prenotazioni)

Il provider vede le prenotazioni in arrivo, le conferma o le rifiuta e avvisa il cliente: qui si chiude il ciclo iniziato con la prenotazione pubblica dello Sprint 3. A fine sprint, con le prenotazioni del seeder, si provano tutti i passaggi di stato dall'interfaccia. Stima: 2 settimane.

**Decisioni da fissare all'inizio**

| Decisione                                | Proposta                                                                                                                                                        |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Passaggi di stato consentiti             | `pending` verso `confirmed` o `cancelled`; `confirmed` verso `cancelled`. Una prenotazione cancellata non torna indietro; tutto il resto è vietato e dà errore. |
| Rifiuto e annullamento                   | Entrambi portano a `cancelled`. Un messaggio facoltativo per il cliente viaggia nell'email e non viene salvato, quindi non serve una nuova colonna.             |
| Richieste in attesa che nessuno gestisce | Una `pending` blocca lo slot. Niente scadenza automatica nell'MVP: il provider vede in evidenza quelle da gestire e riceve un promemoria.                       |
| Come si avvisa il cliente                | Email se l'ha lasciata; altrimenti un pulsante apre WhatsApp con un messaggio già scritto verso il numero del cliente (senza WhatsApp Business API).            |
| Vista del calendario                     | Per l'MVP un elenco per giorno e per settimana, senza libreria di calendario.                                                                                   |
| Prenotazioni inserite dal provider       | Il provider può creare una prenotazione già `confirmed` (cliente venuto in negozio o arrivato su WhatsApp), per tenere l'agenda allineata.                      |

**Elenco delle prenotazioni** _Come provider voglio vedere le mie prenotazioni per giorno e per settimana, per organizzare il lavoro._

- Si vedono solo le proprie prenotazioni (Policy: un altro provider riceve 403).
- Ogni riga mostra orario in ora di Bologna, cliente, servizio, opzioni, prezzo e stato.
- Le `pending` sono evidenziate e in cima.
- Filtro per stato e per intervallo di date.

**Conferma di una prenotazione** _Come provider voglio confermare una richiesta, per dire al cliente che l'orario è suo._

- Solo una `pending` si può confermare.
- Se nel frattempo il cliente l'ha annullata, il provider vede un messaggio chiaro e non succede niente.
- Il cliente viene avvisato (email, oppure pulsante WhatsApp).

**Rifiuto e annullamento** _Come provider voglio rifiutare o annullare una prenotazione, per liberare l'orario._

- Una `pending` si può rifiutare e una `confirmed` si può annullare, con messaggio facoltativo.
- Lo slot torna disponibile subito (Sprint 2).
- I passaggi vietati (per esempio riconfermare una cancellata) danno un errore chiaro.

**Avviso al cliente** _Come cliente voglio sapere come è andata la mia richiesta, per organizzarmi._

- Email di conferma o di annullamento, con i dettagli e il link privato della prenotazione.
- L'email parte in coda: se l'invio fallisce, lo stato della prenotazione non cambia e l'errore resta registrato.
- Senza email, il provider ha il pulsante WhatsApp con il testo precompilato.

**Prenotazione inserita dal provider** _Come provider voglio aggiungere una prenotazione a mano, per tenere l'agenda allineata con ciò che accade fuori dal sito._

- Stesso controllo delle sovrapposizioni dello Sprint 3 e stesso calcolo della durata.
- Nasce già `confirmed`; nome e telefono del cliente sono gli unici dati obbligatori.
- Bloccare tempo per ferie o impegni (una prenotazione "interna") si decide in questo sprint oppure si rimanda.

**Promemoria al provider** _Come provider voglio ricordarmi delle richieste in attesa, per non farle scadere nel vuoto._

- Un promemoria via email se una richiesta resta `pending` oltre un tempo stabilito (per esempio 12 ore).
- Un solo promemoria per prenotazione, mai ripetuto.

**Attività tecniche**

- Le regole dei passaggi di stato dentro l'Enum `BookingStatus` (un metodo che dice quali passaggi sono ammessi) e un'Action per ciascun passaggio, non nei controller.
- `BookingPolicy` e test del 403.
- Notifiche Laravel per le email, in coda, più un comando schedulato per i promemoria.
- Pagine React in TypeScript: elenco con filtri, dettaglio, form di inserimento manuale.
- Test Pest per ogni passaggio di stato (anche quelli vietati), per la Policy e per il caso "il cliente ha annullato mentre il provider confermava".
- `composer ci:check` verde a ogni PR, una PR per story.

**Limiti da tenere presenti**

- Il telefono del cliente è testo libero, ma il link WhatsApp richiede il formato internazionale: va normalizzato (per esempio aggiungendo il prefisso +39 quando manca).
- Il promemoria schedulato richiede che sul server giri lo scheduler di Laravel: in locale si lancia a mano, e va previsto nel deploy dello Sprint 8.

## Sprint 7: prova con utenti reali

I provider già intervistati usano Griot, tu osservi dove si bloccano e correggi; a fine sprint hai feedback scritto, i problemi di usabilità più gravi risolti e l'interfaccia in italiano, francese e inglese. Stima: 2 settimane, con una parte lunga di attesa e coordinamento.

**Come far provare il sito, finché è solo in locale**

| Opzione                                                                                                        | Pro                                              | Contro                                                                                                 |
| -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------ |
| A. Prova in presenza, sito aperto sul tuo computer o telefono                                                  | Nessun costo, vedi in diretta dove esitano       | Un provider alla volta, non provano da soli                                                            |
| B. Tunnel temporaneo verso il tuo computer (Cloudflare Tunnel, ngrok: da verificare nella loro documentazione) | Possono provare dal loro telefono                | Il computer deve restare acceso e il sito è raggiungibile da fuori: solo dati finti e account di prova |
| C. Anticipare il deploy di una versione di prova su Render                                                     | Prova realistica, senza il tuo computer in mezzo | Costo e configurazione rimandati                                                                       |

Si parte con la A per i primi due o tre provider; la C diventa utile per una prova più lunga.

**Decisioni da fissare all'inizio**

| Decisione                     | Proposta                                                                                                                                                                                         |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Quanti provider               | 3-5, di tipi diversi (treccine, barbiere, nails e, se possibile, couture o ristorazione).                                                                                                        |
| Dati della prova              | Servizi e orari veri del provider, ma prenotazioni di prova; niente dati personali di clienti reali.                                                                                             |
| Come si raccoglie il feedback | Sessione di 20-30 minuti per provider con 4-5 compiti (creare un servizio, impostare gli orari, confermare una prenotazione) e 5 domande finali.                                                 |
| Come si misura                | Per ogni compito: riuscito da solo, con aiuto o non riuscito; contano i punti in cui si fermano più persone.                                                                                     |
| Lingue                        | Italiano (riferimento), francese e inglese da file di traduzione.                                                                                                                                |
| Privacy                       | Il sito raccoglie nome e telefono dei clienti: prima del lancio servono un'informativa e una regola di conservazione dei dati. Per il testo vero conviene farlo controllare a chi se ne intende. |

**Preparazione della prova** _Come sviluppatore voglio un ambiente pronto per la prova, per non perdere tempo durante le sessioni._

- Uno script o un seeder crea, per ogni provider pilota, un account e un profilo con i suoi dati veri.
- Un modo semplice per riportare tutto allo stato iniziale tra una sessione e l'altra.
- Un copione scritto con i compiti e le domande, uguale per tutti.

**Sessioni con i provider** _Come sviluppatore voglio osservare i provider mentre usano l'app, per capire dove sbagliano._

- Almeno 3 sessioni svolte.
- Per ogni sessione, una nota con compiti riusciti, punti di blocco e frasi dette.
- Un elenco ordinato dei problemi, per gravità e per numero di persone coinvolte.

**Correzioni di usabilità** _Come provider voglio che l'app non mi blocchi nei compiti più comuni._

- I problemi che hanno fermato almeno due provider sono corretti o hanno una decisione scritta di rimando.
- Ogni correzione passa da una PR con `composer ci:check` verde.

**Traduzioni** _Come utente voglio usare il sito nella mia lingua._

- Tutti i testi dell'interfaccia sono in file di traduzione, senza testo scritto direttamente nei componenti.
- Cambio lingua dal sito, ricordato dal browser.
- Date, orari e prezzi formattati secondo la lingua scelta, con gli orari sempre in ora di Bologna.
- Un controllo automatico (per esempio un test) segnala le chiavi mancanti tra le tre lingue.

**Accessibilità di base** _Come utente voglio poter usare il sito anche con difficoltà visive o con la tastiera._

- Contrasto sufficiente, campi con etichetta, navigazione con la tastiera nei form di prenotazione.
- Verifica con uno strumento automatico (per esempio Lighthouse) e un controllo a mano delle due pagine principali.

**Privacy di base** _Come cliente voglio sapere come vengono usati i miei dati._

- Informativa breve nel form di prenotazione, con link a una pagina dedicata.
- Una regola di conservazione scritta (per esempio cancellare o anonimizzare i dati dei clienti dopo un certo periodo) e un comando che la applica.

**Attività tecniche**

- Libreria di traduzioni per React (da scegliere) e file per IT, FR, EN.
- Test Pest per il comando di conservazione dei dati.
- Un file di note delle sessioni nel repo, senza dati personali.

**Avvertenze**

- Il rischio più grande non è tecnico: è la disponibilità dei provider. Conviene fissare le sessioni nei primi giorni.
- Il feedback può smentire scelte date per buone, per esempio la durata massima come blocco dello slot: lascia spazio nello sprint per correggere.
- Se non c'è ancora un sistema di traduzioni, questo sprint diventa più pesante: va saputo prima di stimare.

## Sprint 8: pre-lancio

Griot va online in modo sicuro, prima per i provider pilota e poi per un pubblico più ampio; a fine sprint il sito è su un indirizzo vero, la registrazione è chiusa, i controlli girano da soli su GitHub e sai cosa fare se qualcosa si rompe. Stima: 2 settimane, con possibili imprevisti di configurazione.

**Decisioni da fissare all'inizio**

| Decisione              | Proposta                                                                                                                                                                                                                                  |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Database di produzione | Da decidere qui. Render offre Postgres gestito; MySQL gestito, per quanto verificato, no; SQLite richiede un servizio a pagamento con disco persistente. Prima di scegliere, guarda i prezzi aggiornati nella pagina ufficiale di Render. |
| Se cambiare database   | Con Postgres, test e sviluppo locale vanno allineati (almeno la CI). Conviene deciderlo prima di scrivere il resto, non il giorno del deploy.                                                                                             |
| Immagini dei profili   | Il disco di un servizio Render è effimero: servono un disco persistente o un archivio esterno compatibile con lo storage di Laravel.                                                                                                      |
| Email in produzione    | Un servizio di invio vero, con dominio verificato per non finire nello spam.                                                                                                                                                              |
| Registrazione          | Chiusa; i provider si creano a mano o con un comando riservato.                                                                                                                                                                           |
| Dominio                | Un nome tuo con HTTPS; per i pilota può bastare l'indirizzo assegnato da Render.                                                                                                                                                          |
| Processi in background | Servono un lavoratore per le code (email) e lo scheduler (promemoria dello Sprint 6), come servizi o comandi separati.                                                                                                                    |

**Controlli automatici su GitHub (CI)** _Come sviluppatore voglio che ogni PR venga controllata da sola, per non rompere `main`._

- A ogni PR gira `composer ci:check` (Pint, PHPStan, test, controllo del frontend e dei tipi).
- Un secondo gruppo di test gira sul database di produzione scelto, per scoprire le differenze con SQLite (date, concorrenza, vincoli).
- La protezione di `main` richiede che i controlli siano verdi.
- Un fallimento è leggibile: si capisce quale controllo e perché.

**Chiusura della registrazione** _Come gestore voglio che nessuno si crei un account da solo, per non avere profili sconosciuti._

- La funzione di registrazione è disattivata in `config/fortify.php`, con ogni funzione su una riga propria (un "a capo" perso aveva già disattivato il reset password per errore).
- Pagine e link di registrazione rimossi dal frontend, con i tipi che tornano puliti dopo aver rigenerato Wayfinder (`php artisan wayfinder:generate --with-form`).
- Un comando riservato (per esempio un comando artisan) crea un provider pilota con la password da cambiare al primo accesso.
- Test che verificano che la rotta di registrazione non esista e che il reset della password funzioni ancora.

**Configurazione di produzione** _Come gestore voglio che il sito giri in modo sicuro, per non esporre errori o segreti._

- `APP_ENV=production` e `APP_DEBUG=false`; chiavi e password solo come variabili d'ambiente di Render, mai nel repo.
- Il seeder di sviluppo non gira in produzione (crea account con password note): un controllo esplicito lo impedisce, oltre alla disciplina.
- Ottimizzazione per la produzione (cache di configurazione, rotte e viste) nel comando di build.
- Controllo che `.env` e i file di sviluppo non finiscano nel repo.

**Deploy su Render** _Come gestore voglio un deploy ripetibile, per aggiornare il sito senza passaggi a mano._

- Un file di configurazione del servizio nel repo (se Render lo prevede, da verificare nella documentazione), oppure i passaggi scritti.
- Build del frontend e delle dipendenze, migrazioni eseguite alla pubblicazione e un controllo di salute della pagina.
- Il sito risponde in HTTPS, con il dominio scelto.
- Un servizio per le code e uno per lo scheduler, oppure un modo documentato per eseguirli.

**Archiviazione delle immagini** _Come provider voglio che la mia foto resti dopo un nuovo deploy._

- Le foto sono salvate in uno spazio che sopravvive ai deploy (disco persistente o archivio esterno).
- Prova: carico una foto, rilancio il deploy, la foto c'è ancora.

**Email in produzione** _Come cliente e provider voglio ricevere le email, per sapere come è andata la prenotazione._

- Un servizio di invio configurato, con il dominio verificato.
- Prova end to end: una prenotazione di prova genera l'email al provider e, alla conferma, al cliente.
- Gli errori di invio finiscono in un registro leggibile e non rompono la prenotazione.

**Monitoraggio e copie di sicurezza** _Come gestore voglio accorgermi dei problemi e non perdere i dati._

- Registro degli errori consultabile (anche solo i log di Render) e un avviso quando un'email o un lavoro in coda fallisce.
- Una copia periodica del database e una prova di ripristino fatta davvero, non solo scritta.
- Una pagina di errore comprensibile per gli utenti.

**Passaggio di consegne a se stessi** _Come sviluppatore voglio una lista scritta di cosa fare in caso di problemi, per non improvvisare._

- Un file nel repo con: come fare un deploy, come tornare a una versione precedente, dove guardare i log, come creare un provider.
- Il `CLAUDE.md` locale aggiornato (timezone, Definition of Done, comandi veri).

**Attività tecniche**

- Un workflow di GitHub Actions con i controlli della prima story.
- Un controllo in avvio che rifiuta di eseguire il seeder di sviluppo in produzione.
- Test Pest per il comando che crea i provider e per le rotte chiuse.
- `composer ci:check` verde a ogni PR, una PR per story.

**Rischi da conoscere**

- Il passaggio di database (se cambia) è il lavoro più imprevedibile dello sprint: più tardi lo scopri, più costa.
- I costi (servizio web, database, disco, processi in background) si sommano: prima del deploy, fai un conto vero con i prezzi aggiornati.
- Da quando il sito è online, nome e telefono dei clienti sono dati reali: informativa e regola di conservazione dello Sprint 7 devono essere pronte prima.

## Riepilogo e punti aperti

Il piano vale 13-14 settimane a 10 ore a settimana, un obiettivo ambizioso con studi e lavoro: va rivisto dopo gli Sprint 1 e 2, quando ci sarà un ritmo vero.

| Sprint | Tema                                           | Durata stimata |
| ------ | ---------------------------------------------- | -------------- |
| 1      | Scoperta pubblica                              | 1 settimana    |
| 2      | Calcolo degli slot liberi                      | 2 settimane    |
| 3      | Prenotazione pubblica                          | 2 settimane    |
| 4      | Area provider: login e profilo                 | 1 settimana    |
| 5      | Area provider: servizi, opzioni, disponibilità | 1-2 settimane  |
| 6      | Area provider: calendario e gestione           | 2 settimane    |
| 7      | Prova con utenti reali                         | 2 settimane    |
| 8      | Pre-lancio                                     | 2 settimane    |

**Da fare prima di andare online**

- [ ] Chiudere la registrazione pubblica in `config/fortify.php`, ogni funzione su una riga propria.
- [ ] Non eseguire mai il seeder di sviluppo in produzione.
- [ ] Controllare la riga sulla timezone nel `CLAUDE.md` locale (app in UTC, conversione in `Europe/Rome` solo per mostrare orari e calcolare gli slot) e togliere dalla Definition of Done "funzionante in produzione su Render" finché non c'è un deploy.
- [ ] Decidere il database di produzione (SQLite con disco o Postgres) con i prezzi aggiornati di Render.
- [ ] Preparare informativa sulla privacy e regola di conservazione dei dati dei clienti.

**Punti aperti**

- Confermare con i provider intervistati che la durata massima come blocco dello slot sia la scelta giusta.
- Il prezzo pagato non è salvato nella prenotazione: valutare se serve.
- Wireframe a bassa fedeltà di elenco provider, scheda e form di prenotazione: rimandati, da fare prima dello Sprint 1 se servono.
- Lingua dell'interfaccia: decidere il sistema di traduzioni prima di scrivere le pagine dello Sprint 1.
