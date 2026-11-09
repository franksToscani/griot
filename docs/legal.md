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

- **Cancellazione di un account con prenotazioni (#133):** il database la rifiuta e il professionista vede una pagina d'errore. L'informativa dice che in quel caso deve scrivere al contatto.
- **Strumenti per le richieste dei clienti:** chi prenota non ha un account. Oggi non può vedere, correggere, esportare o cancellare da solo i propri dati, e il gestore non ha né un pannello né un comando per farlo. L'informativa dice che le richieste si gestiscono a mano, per email.
- **Banner dei cookie:** da valutare con l'esperto. Il sito usa solo cookie tecnici e di preferenza, nessun cookie di analisi o di altri siti.
- **Il cookie `appearance`** (tema) viene scritto alla prima visita, anche senza una scelta. L'informativa lo dice.
- **Log che potrebbero contenere dati dei clienti** (non verificato): il messaggio di un errore del database in Laravel contiene i valori della query, quindi un errore durante il salvataggio di una prenotazione potrebbe scrivere nel log nome, telefono o note. Anche `failed_jobs` contiene l'email del cliente, per 30 giorni.
- **Codice segreto nell'URL:** il link privato di una prenotazione (`/bookings/{codice}`) può finire nei log di accesso dell'hosting e nei messaggi WhatsApp.
- **Campo note libero:** ora ha un avviso, ma resta un testo libero di 1000 caratteri che arriva al professionista anche per email.
- **Età:** nessun controllo, né per chi prenota né per le persone nelle foto.
- **Copie fuori dal database:** l'anonimizzazione non tocca le email già inviate, i messaggi WhatsApp, i log e le eventuali copie di sicurezza (#63).
- **Fornitori di produzione:** hosting (previsto Render), database gestito (da scegliere), archivio delle foto (previsto Scaleway, Parigi), invio email (da scegliere, #62). Le regioni e gli accordi con i fornitori sono da verificare.

## Domande per un esperto

1. Ruoli: Griot e i professionisti rispetto ai dati delle prenotazioni. Titolari autonomi, titolare e responsabile, o altro?
2. Base giuridica per ogni trattamento: richiesta di prenotazione, email di esito al cliente, promemoria al professionista, dati tecnici e limiti di richieste, foto con persone riconoscibili.
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
