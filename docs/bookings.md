# Griot: il prezzo salvato sulla prenotazione

Come una prenotazione tiene il suo prezzo (issue #97). Griot non gestisce pagamenti: il prezzo è un'informazione per il cliente e per il professionista, in euro.

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

| Dove                                                       | Che cosa                                                                                                                    |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Pagina privata del cliente (`BookingConfirmationResource`) | `service.price_cents`, `options[].extra_price_cents`, `total_cents`; l'etichetta è « Prezzo al momento della prenotazione » |
| Lista del professionista (`ProviderBookingResource`)       | `price_cents` (il totale) e `options[].extra_price_cents`; « + X € » solo per un'opzione che costa qualcosa                 |
| Modulo di prenotazione, pubblico e del professionista      | il catalogo di oggi, « Prezzo indicativo »: la prenotazione non esiste ancora                                               |

Il frontend non somma più niente: formatta i centesimi con `formatCents(locale, cents)` (`resources/js/lib/format.ts`).

**Le email, i promemoria e il messaggio WhatsApp restano senza prezzo**, come prima:

- danno al professionista e al cliente il servizio, le opzioni e l'orario; il prezzo si legge sulla pagina;
- non c'è un pagamento da confermare;
- un importo in un'email non si può più correggere, la pagina sì.

`BookingSavedPriceTest` controlla che le email non contengano un prezzo.

## Le prenotazioni fatte prima del #97

La migrazione `2026_11_09_100000_add_saved_prices_to_bookings` le ha riempite con i prezzi **attuali** del servizio e delle opzioni, quelli del giorno della migrazione. Quelli del giorno della prenotazione non erano salvati da nessuna parte: **per le prenotazioni vecchie il prezzo è un'approssimazione**. Dal giorno della migrazione in poi, ogni prenotazione nuova ha il prezzo esatto.

Come funziona la migrazione:

1. aggiunge le due colonne come nullable;
2. le riempie a blocchi di 200 prenotazioni con il query builder; la tabella `booking_service_option` non ha `id`, quindi ogni riga si aggiorna con la coppia `booking_id` e `service_option_id`;
3. le rende obbligatorie (`nullable(false)->change()`).

Nessun SQL grezzo: gira uguale su SQLite, MySQL e PostgreSQL. Il `down()` toglie solo le due colonne, i prezzi del catalogo non vengono mai toccati; un nuovo `up()` riempie di nuovo con i prezzi di quel momento.

## Test

- `tests/Feature/BookingSavedPriceTest.php`:
    - `Money`;
    - il salvataggio in `CreateBooking`, anche con un prezzo cambiato tra modulo e salvataggio e per le prenotazioni del professionista;
    - la factory e il seeder;
    - le email senza prezzo.
- `tests/Feature/Booking/SavedPriceMigrationTest.php`: la migrazione, `down()` compreso.
- `ProviderBookingsListTest` e `BookingConfirmationTest`: i prezzi mostrati sono quelli salvati, anche dopo un cambio del catalogo o un'opzione nascosta.
- Nei test, una prenotazione fatta con la factory riceve le opzioni con `attachBookingOptions()` (`tests/Pest.php`), che salva il loro prezzo attuale come `CreateBooking`.
