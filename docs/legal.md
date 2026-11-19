# Griot: testi legali (bozze)

Lo stato dei testi legali e cosa serve prima di aprire il sito a persone esterne (issue #131, **ancora aperta**). Una persona competente, un consulente privacy o un avvocato, deve scrivere o rivedere i testi. Questa pagina non contiene consigli legali: descrive che cosa fa il codice e che cosa resta da decidere.

## Che cosa è bozza

| Pagina                                | Dove                                                                     | Avviso                                                        | Valori da configurare                                                                                      |
| ------------------------------------- | ------------------------------------------------------------------------ | ------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Informativa sulla privacy, `/privacy` | `PrivacyController`, `resources/js/pages/privacy.tsx`, testi `privacy.*` | « Bozza in revisione » finché `PRIVACY_REVIEWED` non è `true` | `PRIVACY_CONTROLLER_NAME`, `PRIVACY_CONTACT_EMAIL`                                                         |
| Termini d'uso, `/terms`               | `TermsController`, `resources/js/pages/terms.tsx`, testi `terms.*`       | « Bozza in revisione » finché `TERMS_REVIEWED` non è `true`   | `TERMS_PUBLISHER_NAME`, `TERMS_PUBLISHER_ADDRESS`, `TERMS_PUBLISHER_VAT_NUMBER`; contatto: `CONTACT_EMAIL` |

- I link alle due pagine sono nel footer di tutte le pagine pubbliche (`resources/js/layouts/public-layout.tsx`).
- Un valore mancante si vede come segnaposto evidenziato, per esempio « [Nome del titolare da inserire] ». La legge applicabile dei termini è un segnaposto fisso: « da verificare ».
- I testi sono in italiano, francese e inglese. Ogni numero (mesi di conservazione, ore per annullare, durata dei cookie) arriva dalla configurazione, mai scritto a mano.
- La data dell'ultimo aggiornamento è in `config/privacy.php` e `config/terms.php` (`updated_on`): va cambiata insieme ai testi.
- Nel modulo di prenotazione, sotto il campo note, un avviso chiede di non scrivere dati sulla salute o altre informazioni sensibili (`booking.form.notes_help`). Il campo è lo stesso componente (`CustomerFields`) nel modulo del cliente e in quello del professionista: in entrambi contiene un dato del cliente, e l'avviso compare in entrambi.

## Ipotesi sui ruoli (da confermare)

Scritta così nella bozza dell'informativa, come ipotesi in corso di verifica:

- Griot è titolare dei dati dell'account dei professionisti e dei dati tecnici (sessioni, indirizzi IP per i limiti di richieste).
- Per i dati di chi prenota, è il professionista a decidere a che cosa servono; Griot fornisce lo strumento per gestire le prenotazioni.

Chi è Griot, cioè la persona o l'ente titolare e la sua forma giuridica, non è ancora deciso.

## Che cosa manca o va valutato

- **Cancellazione di un account con prenotazioni (#133):** è rifiutata con un messaggio chiaro e il contatto del sito, per qualunque prenotazione, anche passata o resa anonima (lo impone la chiave esterna); il professionista resta collegato e niente viene cancellato. È quello che dice l'informativa (`privacy.rights.providers`). Che cosa fare delle prenotazioni quando un professionista vuole andarsene (annullarle e avvisare i clienti, cancellarle, o conservarle anonime) resta una domanda per l'esperto (domande 1 e 9).
- **Strumenti per le richieste dei clienti:** chi prenota non ha un account. Oggi non può vedere, correggere, esportare o cancellare da solo i propri dati, e il gestore non ha né un pannello né un comando per farlo. L'informativa dice che le richieste si gestiscono a mano, per email.
- **Banner dei cookie:** da valutare con l'esperto. Il sito usa solo cookie tecnici e di preferenza, nessun cookie di analisi o di altri siti.
- **Il cookie `appearance`** (tema) viene scritto alla prima visita, anche senza una scelta. L'informativa lo dice.
- **Log che potrebbero contenere dati dei clienti** (#186): il messaggio di un errore del database in Laravel contiene i valori della query, quindi un errore durante il salvataggio di una prenotazione scriverebbe nel log nome, telefono, note e il codice segreto; un errore della posta contiene l'indirizzo del destinatario. **Corretto:** per questi due tipi di errore il log riceve solo la classe, il codice (SQLSTATE o codice SMTP), la connessione e il punto del codice (`App\Support\PrivateExceptionLog`, `bootstrap/app.php`), mai il messaggio; gli altri errori sono registrati come prima. Il messaggio completo resta solo nella colonna `exception` di `failed_jobs`, nel database. `failed_jobs` contiene anche l'email del cliente: dal novembre 2026 per 14 giorni (prima 30).
- **Codice segreto nell'URL:** il link privato di una prenotazione (`/bookings/{codice}`) può finire nei messaggi WhatsApp e, su un workspace Pro di Render, nei log delle richieste: vedi « Decisioni sulla #186 » qui sotto.
- **Codice segreto nella casella del cliente (#186):** dal novembre 2026 l'email « richiesta inviata » (`BookingReceivedNotification`) manda il link privato all'indirizzo scritto nel modulo, con l'avviso di non inoltrarla. **Accettato per iscritto dal gestore:** il codice finisce anche nella casella del cliente, e in quella di chiunque abbia accesso a quella casella. Non finisce nella coda: il job porta solo l'id della prenotazione e il codice si rilegge dal database (un test lo controlla). Le decisioni sui log, su `failed_jobs` e sui log delle richieste di Render sono in « Decisioni sulla #186 » qui sotto.
- **Email « richiesta inviata »:** contiene le note scritte dal cliente e il prezzo salvato, mai il telefono. Al massimo 3 all'ora verso lo stesso indirizzo; la chiave del limite è un HMAC dell'indirizzo, mai l'indirizzo in chiaro.
- **Campo note libero:** ora ha un avviso, ma resta un testo libero di 1000 caratteri che arriva per email al professionista e, se ha lasciato un'email, al cliente stesso.
- **Età:** nessun controllo, né per chi prenota né per le persone nelle foto.
- **Copie fuori dal database:** l'anonimizzazione non tocca le email già inviate, i messaggi WhatsApp, i log e le eventuali copie di sicurezza (#63).
- **Google Calendar (dal novembre 2026):** il professionista può aggiungere una prenotazione confermata al suo Google Calendar con un pulsante. Google riceve i dati **solo quando il professionista clicca**, e solo il servizio con le opzioni, l'orario, il nome del cliente e l'indirizzo della prenotazione nell'area del professionista (solo l'id). Mai telefono, email, note, prezzo, motivo dell'annullamento né il link privato del cliente ([bookings.md](bookings.md), « Aggiungi a Google Calendar »). L'informativa lo dice (`privacy.recipients.google_calendar`). Un evento salvato resta nel calendario del professionista anche dopo un annullamento e dopo che la prenotazione è resa anonima: è una copia fuori dal database, come i messaggi WhatsApp.
- **Fornitori di produzione:** hosting e database PostgreSQL gestito su Render (regione Francoforte), archivio delle foto (Scaleway Object Storage, Milano), invio email (da scegliere, #62). Le regioni e gli accordi con i fornitori sono da verificare.

## Decisioni sulla #186 (2026-11-19)

Log, `failed_jobs` e codice segreto nell'URL: una decisione per ogni punto, del gestore.

1. **Errori del database e della posta nel log:** risolto con la PR #222. Per questi due tipi di errore il log riceve solo la classe, il codice (SQLSTATE o codice SMTP), il file e la riga, mai il messaggio, che può contenere nome, telefono, email, note e il codice segreto (`App\Support\PrivateExceptionLog`, `tests/Feature/PrivateExceptionLogTest.php`).
2. **`failed_jobs`: 14 giorni** (`queue:prune-failed --hours=336`, prima 30). Un'email non partita resta lì con l'indirizzo del destinatario, e il messaggio completo dell'errore resta nella colonna `exception` per gli stessi 14 giorni: scelta consapevole, serve a capire che cosa è andato storto. L'informativa lo dice (`privacy.retention.technical`).
3. **Copie nella posta inviata di Gmail:** accettate finché la posta parte dalla casella Gmail della fase privata. Quando arrivano il dominio e il servizio di posta dedicato (#62) si rivede questo punto e si aggiorna l'informativa (`privacy.recipients.email`).
4. **Link privato nel testo WhatsApp e nell'email del cliente:** già accettati. WhatsApp è nell'informativa (`privacy.recipients.whatsapp`); l'email del cliente è nella riga « Codice segreto nella casella del cliente » qui sopra.
5. **Log delle richieste di Render:** sul workspace Hobby di oggi Render non registra le richieste HTTP, quindi nemmeno il percorso con il codice; dal workspace Pro sì, con l'indirizzo richiesto, conservati 14 giorni (https://render.com/docs/logging, consultata il 2026-11-19; [deploy.md](deploy.md), sezione 12). **Prima di passare al workspace Pro si rivede questo punto.**
6. **Cronologia del browser:** inevitabile con un link: chi lo apre lo ritrova nella cronologia del proprio browser. La cache invece è già esclusa (`Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `PrivateBookingPage`).
7. **Nessun limite di richieste sulla pagina privata in GET:** il codice di 48 caratteri non si indovina, e un limite potrebbe bloccare clienti veri dietro lo stesso indirizzo IP (una rete aziendale, un operatore mobile). L'annullamento, in POST, ha già il suo limite.

## Domande per un esperto

1. Ruoli: Griot e i professionisti rispetto ai dati delle prenotazioni. Titolari autonomi, titolare e responsabile, o altro?
2. Base giuridica per ogni trattamento: richiesta di prenotazione, email « richiesta inviata » al cliente, email di esito al cliente, promemoria al professionista, dati tecnici e limiti di richieste, foto con persone riconoscibili. Ipotesi da verificare per l'email « richiesta inviata »: esecuzione di misure precontrattuali richieste dall'interessato (art. 6, par. 1, lett. b GDPR), perché è la ricevuta della richiesta che il cliente ha appena inviato e parte solo se ha scritto un'email; il limite per indirizzo, nell'interesse legittimo a evitare abusi (lett. f).
3. Durata: 6 mesi dopo la fine dell'appuntamento è adeguata? Come trattare le copie fuori dal database (email inviate, WhatsApp, log, copie di sicurezza)?
4. Cookie: `appearance` (scritto alla prima visita), `locale`, `sidebar_state` e il cookie « Ricordami » del login richiedono un banner o un consenso, o basta l'informativa?
5. Campo note: l'avviso contro i dati sensibili è sufficiente? Come trattare dati di questo tipo già arrivati?
6. Minori: serve un limite d'età o un'indicazione nel modulo? E per le foto in cui compare un minore?
7. Foto: la dichiarazione del professionista sul consenso delle persone ritratte, con la sola data salvata, è sufficiente, o servono altri elementi?
8. Fornitori: quali accordi servono con hosting, database, email e archivio delle foto? Ci sono trasferimenti fuori dall'Unione europea, a seconda delle regioni scelte?
9. Richieste dei clienti senza account: come verificare l'identità, in quali tempi rispondere, e se servono strumenti nel sito.
10. Termini d'uso: che cosa deve contenere la versione definitiva, per i clienti e per i professionisti (servono condizioni separate per i professionisti?), quali dati dell'editore vanno mostrati, legge applicabile e foro competente.
11. Il link privato con codice segreto, che può finire nei log e nei messaggi WhatsApp, è una misura adeguata per la pagina di una prenotazione?

## Test

- `tests/Feature/PrivacyTest.php`: la pagina, la durata (conta dalla fine dell'appuntamento, come `Booking::dueForAnonymization()`), i cookie reali, compreso quello di « Ricordami », i testi prudenti.
- `tests/Feature/LegalDraftsTest.php`: la pagina `/terms` nelle tre lingue con l'avviso di bozza, i segnaposto dell'editore, i link nel footer, l'avviso sotto le note.
