# Griot: checklist di produzione

Cosa deve essere vero prima di aprire Griot a persone reali (issue #58, #59, #86 e Sprint 9 in [roadmap.md](roadmap.md)). Il deploy su Render (#60), l'email (#62) e il monitoraggio (#63) hanno le loro pagine quando arrivano.

## Variabili d'ambiente

- Il modello è `.env.production.example`: tutte le variabili che servono, con un commento ciascuna, **senza nessun valore vero**. I valori si scrivono solo come variabili del servizio di hosting; `.env` e `.env.production` sono esclusi da Git.
- Le variabili segrete (marcate `[SEGRETO]`): `APP_KEY`, `DB_PASSWORD` o `DB_URL`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `MAIL_PASSWORD`.
- I valori obbligati:
    - `APP_ENV=production` e `APP_DEBUG=false`. `.env.example` (sviluppo) ha `APP_DEBUG=true`: non copiarlo;
    - `APP_URL` in https;
    - `SESSION_SECURE_COOKIE=true`, così il cookie di sessione viaggia solo in https;
    - `LOG_CHANNEL=stderr` e `LOG_LEVEL=info`: il disco del servizio si cancella, i log li tiene l'hosting;
    - `SESSION_DRIVER`, `CACHE_STORE` e `QUEUE_CONNECTION` a `database`, per lo stesso motivo;
    - `MEDIA_DISK_DRIVER=s3` con le variabili `AWS_*` ([media-storage.md](media-storage.md));
    - `MAIL_MAILER` un mailer vero: con `log` o `array` le pagine, la coda e lo scheduler non partono (`App\Support\ProductionMailer`);
    - `PRIVACY_REVIEWED=true` solo dopo la revisione del testo (#131).
- `ProductionHardeningTest` controlla che ogni variabile dell'esempio sia letta davvero da `config/` e che nessun segreto o contatto reale ci sia scritto.

## PHP e dipendenze

- PHP **8.4** almeno (`composer.json`: `^8.4`, come la piattaforma del lock): alcuni pacchetti Symfony lo richiedono. La CI usa PHP 8.5 e Node 22.
- Prima di ogni deploy: `composer audit` e `composer audit --no-dev` senza avvisi. Il job `audit` della CI li lancia a ogni push, con `npm audit --omit=dev` ([ci.md](ci.md)).
- Build: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, poi `php artisan config:cache`, `route:cache`, `view:cache`, `event:cache`. Il job `production-build` della CI rifà questi passi a ogni push, con migrazioni, categorie e un server che deve rispondere su `/up` e `/` ([ci.md](ci.md)).
- Database: la suite gira anche su PostgreSQL (job `tests-postgres`); le differenze da ricordare sono in [ci.md](ci.md).

## Dati iniziali

- Le categorie sono dati veri e si caricano anche in produzione, da sole:

    ```bash
    php artisan db:seed --class=CategorySeeder --force
    ```

- `DatabaseSeeder`, `ProviderSeeder` e `BookingSeeder` scrivono dati di sviluppo (account con la password nota `password`, professionisti e prenotazioni inventati): fuori da `local` e `testing` si fermano con un'eccezione prima di scrivere qualsiasi cosa (`Database\Seeders\DevelopmentOnly`). Quindi mai `php artisan db:seed` senza `--class` in produzione: si fermerebbe comunque.

## Account dei professionisti

- **Nessuna registrazione** (#58): Fortify non ha più la funzione `registration`, non esistono la rotta `/register`, la pagina né il link « Registrati » nel login.
- Un professionista si crea con:

    ```bash
    php artisan provider:create "Nome della vetrina" persona@example.test Bologna
    ```

    Il comando crea l'utente (email già verificata: la scrive il gestore) e il suo provider **non pubblicato**, con lo stesso nome e la città. Stampa una password iniziale casuale di 20 caratteri **una sola volta**: non viene salvata in chiaro né scritta nei log. Rifiuta un'email già usata (anche con maiuscole diverse), un'email non valida, un nome oltre 150 caratteri o una città vuota.

- La password va comunicata al professionista in modo sicuro. Lui la cambia in « Impostazioni → Sicurezza » o con « Password dimenticata ». **Oggi niente obbliga a cambiarla al primo accesso**: se serve, è un lavoro a parte.

## Dati condivisi con le pagine

- `auth.user` (#86) contiene solo `name`, `email` ed `email_verified_at` (`App\Http\Resources\AuthUserResource`), mai il modello: una relazione caricata da un controller (il provider, un giorno le prenotazioni) non finisce più in ogni pagina. Il tipo `User` di `resources/js/types/auth.ts` ha gli stessi campi; `auth.user` è `null` per un visitatore.

## Proxy, https e intestazioni

- **Proxy fidati**: `TRUSTED_PROXIES` (`config/app.php`, `trusted_proxies`): `*` dietro il bilanciatore dell'hosting, oppure gli indirizzi separati da virgole; vuota (sviluppo): nessuno. Si credono `X-Forwarded-For`, `-Proto`, `-Host` e `-Port`. Senza, dietro un proxy tutti i clienti avrebbero l'indirizzo del proxy (un solo contatore per i limiti di richieste) e i link sarebbero in http. `*` va bene solo se l'app è raggiungibile unicamente attraverso il proxy.
- **Intestazioni di sicurezza** (`App\Http\Middleware\SecurityHeaders`, su ogni risposta, pagine d'errore e `/up` comprese):
    - `X-Content-Type-Options: nosniff`;
    - `Referrer-Policy: strict-origin-when-cross-origin` (la pagina privata di una prenotazione tiene il suo `no-referrer`, più severo, e il suo `Cache-Control: no-store`);
    - `X-Frame-Options: SAMEORIGIN`;
    - `Strict-Transport-Security: max-age=31536000` solo in produzione e solo su una richiesta https; senza sottodomini e senza preload finché il dominio non è scelto.
- **Nessuna Content-Security-Policy** per ora: lo script in linea del tema in `app.blade.php` richiede prima un nonce. È un lavoro a parte.

## Limiti di richieste

Per IP (con i proxy fidati, l'IP vero del cliente), in `AppServiceProvider`, valori in `config/booking.php`:

| Limite           | Dove                                   | Valore                     |
| ---------------- | -------------------------------------- | -------------------------- |
| `providers-list` | la lista `/providers` e la sua ricerca | 60 al minuto               |
| `booking-slots`  | gli orari liberi                       | 60 al minuto               |
| `booking-store`  | la prenotazione pubblica               | 5 al minuto, 20 all'ora    |
| `booking-cancel` | l'annullamento dalla pagina privata    | 10 al minuto               |
| `login`          | l'accesso (Fortify)                    | 5 al minuto per email e IP |

L'area del provider ha i suoi limiti per utente (foto, servizi, prenotazioni). Oltre il limite: la pagina 429 del sito, o un errore nel modulo dove c'è un modulo.

## Test

I controlli automatici su GitHub sono in [ci.md](ci.md). `tests/Feature/ProductionHardeningTest.php` (comando, `auth.user`, seeder, proxy, intestazioni, limite della lista, pagine in produzione, esempio dell'ambiente, versione di PHP) e `tests/Feature/Auth/RegistrationTest.php` (la registrazione non esiste).
