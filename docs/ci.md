# Griot: i controlli automatici su GitHub (CI)

Cosa gira da solo a ogni push su `main` e a ogni Pull Request (issue #57), come leggerlo e come rifarlo in locale. I file sono in `.github/workflows/`; `tests/Feature/WorkflowFilesTest.php` li legge e fallisce se un job sparisce, cambia nome o perde un controllo.

## I cinque job

| Job (nome esatto)  | File                    | Cosa controlla                                                                                         |
| ------------------ | ----------------------- | ------------------------------------------------------------------------------------------------------ |
| `ci`               | `tests.yml`             | `composer ci:check`: formattazione e lint del frontend, `tsc`, Pint, PHPStan, tutta la suite su SQLite |
| `audit`            | `production-checks.yml` | nessun avviso di sicurezza noto nei pacchetti Composer (con e senza dev) e npm (solo produzione)       |
| `production-build` | `production-checks.yml` | la build di produzione si costruisce, si avvia e risponde                                              |
| `tests-postgres`   | `production-checks.yml` | tutta la suite su PostgreSQL, il database probabile della produzione                                   |
| `docker-build`     | `production-checks.yml` | l'immagine di produzione (`Dockerfile`) si costruisce, parte come su Render e risponde                 |

**Protezione di `main`** (si imposta a mano su GitHub: Settings → Branches → la regola di `main` → « Require status checks to pass »): i cinque nomi sopra, `ci`, `audit`, `production-build`, `tests-postgres`, `docker-build`. GitHub li propone solo dopo che hanno girato almeno una volta: `docker-build` va aggiunto dopo il primo giro verde. Nella pagina della PR compaiono come « tests / ci » e « production-checks / audit » e così via: il nome da scegliere nella protezione è quello del job.

Tutti e due i workflow:

- leggono soltanto la repository (`permissions: contents: read`); nessun job chiede di più;
- non usano segreti di GitHub e non contengono contatti veri: la chiave dell'applicazione è generata nel job e nascosta nel log, gli indirizzi sono su `example.test`;
- usano le azioni fissate a un commit (lo SHA, con la versione in commento), lo stesso commit in tutti i file;
- non hanno `continue-on-error`: un job che fallisce è rosso.

In `production-checks.yml` un push nuovo sulla stessa PR annulla il giro ancora in corso (`concurrency`); su `main` ogni giro arriva in fondo. Il job `ci` resta com'era.

### `ci`

Lo stesso `composer ci:check` di prima di ogni PR. Se è rosso, si rifà in locale con lo stesso comando.

### `audit`

Installa i pacchetti del lock (senza avviare l'applicazione) e lancia:

- `composer audit`: tutti i pacchetti PHP, anche quelli di sviluppo;
- `composer audit --no-dev`: quelli che vanno in produzione;
- `npm audit --omit=dev`: i pacchetti npm che finiscono nella build.

Se è rosso: il log dice il pacchetto, l'avviso e la versione corretta. Si aggiorna quel pacchetto con un aggiornamento mirato (`composer update vendor/pacchetto -w`, oppure `npm update pacchetto`), si rilancia `composer audit` in locale e si apre una PR solo per quello. Un avviso può comparire anche senza nessuna modifica al codice, quando viene pubblicato: per questo il job gira a ogni push.

### `production-build`

Rifà i passi del deploy in [production.md](production.md) su una copia usa e getta:

1. variabili come `.env.production.example` (`APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES=*`, sessioni, cache e code nel database), ma SQLite in un file temporaneo al posto del database gestito, il disco locale al posto del bucket e un mailer `smtp` verso `smtp.example.test`: `ProductionMailer` vuole un mailer vero, e nessuna email parte;
2. una `APP_KEY` generata nel job;
3. `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`;
4. `php artisan config:cache`, `route:cache`, `view:cache`, `event:cache`;
5. `php artisan about` e `route:list`: l'applicazione si avvia con la cache;
6. `migrate --force` e `db:seed --class=CategorySeeder --force`;
7. `db:seed --force` (DatabaseSeeder) deve **rifiutarsi**, con il messaggio che indica il comando delle categorie, e nessun utente deve esistere dopo;
8. avvia `php artisan serve` e chiede `/up` e `/`: 200 tutte e due, con `X-Content-Type-Options: nosniff`.

Come leggerlo: il passo rosso dice cosa si è rotto.

- `Install production dependencies`: un pacchetto di sviluppo usato dal codice di produzione.
- `Build the frontend`: la build.
- `Cache config, routes, views and events`: una configurazione che non si può mettere in cache, una vista che non compila, due rotte con lo stesso nome.
    - Le closure nelle rotte invece funzionano: Laravel le serializza, quindi `Route::get('x', fn () => redirect('/y'))` va bene.
- `Check that the development seeders refuse production`: la protezione di #59 non c'è più.
- `Start the server…`: il log del server è stampato sotto il codice di risposta.

### `tests-postgres`

Un contenitore `postgres:18` (immagine ufficiale) accanto al job, con un controllo di salute (`pg_isready`): il job parte solo quando il database risponde. È solo per i test: nessuna password (`trust`), raggiungibile solo dal job, cancellato alla fine. PHP ha l'estensione `pdo_pgsql`; le variabili sono `DB_CONNECTION=pgsql`, `DB_DATABASE=griot_test` e le altre `DB_*`. Poi `php artisan test`, la suite intera.

È un job separato da `ci` per distinguere subito un errore di PostgreSQL da uno di tutto il resto: se `ci` è verde e `tests-postgres` è rosso, il problema è una differenza tra i due database (sotto).

### `docker-build`

L'immagine di produzione non si può costruire sui computer di sviluppo (Docker non c'è): la costruisce questo job, solo con i comandi `docker` del runner, senza azioni di terzi.

1. `docker build` del `Dockerfile` (vendor, assets, runtime).
2. Avvia il container come su Render, con valori usa e getta: una `APP_KEY` generata nel job, un database e un server di posta su `example.test` che non vengono mai raggiunti (`/up` non li usa), `SITE_NOINDEX=true`.
3. Aspetta `/up` con 200.
4. Controlla il sito privato: `X-Robots-Tag: noindex, nofollow` su `/up` e su un file statico (`/favicon.ico`, servito da Caddy), `/robots.txt` che vieta tutto, nessun `X-Powered-By`.
5. Controlla che nessun processo giri come root.
6. Controlla le estensioni (`pdo_pgsql`, `gd` con WebP, `exif`, OPcache) e i valori di `docker/php.ini`.
7. Ferma il server web dentro il container e controlla che il container esca, così Render ne avvia uno nuovo (`docker/stop-on-web-exit.sh`).

Se è rosso: il passo dice che cosa manca; i log del container sono stampati sotto. Un errore nel passo di build è quasi sempre una dipendenza o un'estensione mancante.

## Riprodurre in locale

### La build di produzione

**In una copia della repository, mai nella cartella di lavoro:** `config:cache` e `route:cache` scrivono in `bootstrap/cache/` e lo sviluppo userebbe quella cache. Per esempio:

```bash
rsync -a --exclude vendor --exclude node_modules --exclude .env --exclude public/build --exclude public/hot ./ /tmp/griot-prod/
cd /tmp/griot-prod
export APP_ENV=production APP_DEBUG=false APP_URL=http://127.0.0.1:8000 LOG_CHANNEL=stderr \
  TRUSTED_PROXIES='*' SESSION_DRIVER=database SESSION_SECURE_COOKIE=true CACHE_STORE=database \
  QUEUE_CONNECTION=database MAIL_MAILER=smtp MAIL_HOST=smtp.example.test MAIL_PORT=587 \
  MAIL_FROM_ADDRESS=noreply@example.test DB_CONNECTION=sqlite DB_DATABASE=/tmp/griot-prod/production.sqlite
export APP_KEY="base64:$(openssl rand -base64 32)"
touch "$DB_DATABASE"
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan migrate --force && php artisan db:seed --class=CategorySeeder --force
php artisan db:seed --force   # deve rifiutarsi
php artisan serve --port=8000 # poi /up e / nel browser
```

Se per sbaglio la cache finisce nella cartella di lavoro: `php artisan optimize:clear`.

### I test su un altro database

`phpunit.xml` scrive `DB_CONNECTION=sqlite` e `DB_DATABASE=:memory:` **senza** `force`: una variabile d'ambiente già impostata vince. Senza variabili i test restano su SQLite in memoria, come sempre. Con PostgreSQL acceso in locale:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=griot_test DB_USERNAME=griot DB_PASSWORD= php artisan test
```

- Il nome del database deve finire in `_test`: `TechnicalRetentionTest` lo controlla, perché i test lo svuotano a ogni giro (`RefreshDatabase`).
- Mai il database di sviluppo.

## Differenze di PostgreSQL da ricordare

PostgreSQL è più severo di SQLite (e di MySQL). Quello che è già sistemato, e le regole per il codice nuovo:

- **Un errore SQL dentro una transazione la rovina tutta**: ogni query dopo fallisce (« current transaction is aborted ») finché non si torna indietro. I test girano dentro una transazione (`RefreshDatabase`). Quindi:
    - un test che aspetta un errore del database e poi fa altre query mette l'istruzione che fallisce in `DB::transaction(...)` (un savepoint, annullato da solo);
    - il codice fa lo stesso: `ManageService` e `ManageOption` cancellano dentro `DB::transaction` e leggono l'errore fuori.
    - se l'errore arriva da una richiesta HTTP (la risposta è la pagina 500, il test non riceve l'eccezione), la richiesta va in `failingRequestInSavepoint(fn () => ...)` (`tests/Pest.php`): su PostgreSQL torna al savepoint, su SQLite lo rilascia e lascia tutto come prima, così una scrittura fatta prima dell'errore resta visibile al test.
- **Le quattro prove di gara sullo slug** (tre in `ProviderProfileCreateTest`, una in `ProviderProfileEditTest`) simulano l'altra richiesta con un insert nella stessa transazione del test. Su PostgreSQL non si può e sono saltate con il loro motivo (`skipOnPostgresSameTransactionRace()` in `tests/Pest.php`). In produzione la gara vera arriva da un'altra connessione, e il codice la gestisce.
    - Totale dei saltati: 3 su SQLite, 7 su PostgreSQL.
- **Chiave esterna violata e unicità**: codici diversi per database, nella tabella sotto.
- **Colonne che non esistono**: SQLite legge un nome sconosciuto tra virgolette doppie come un testo e non dice niente, PostgreSQL rifiuta la query (`42703`). Succede con `orderBy('id')` su una tabella senza `id`: `booking_service_option` (la chiave è la coppia `booking_id`, `service_option_id`), `password_reset_tokens`, `cache`, `cache_locks`. `PostgresCompatibilityTest` cerca questi casi in `app/`, `database/` e `tests/`, e gira anche su SQLite.
- **Ordine senza `orderBy`**: SQLite restituisce le righe nell'ordine di inserimento, PostgreSQL non promette niente (una riga aggiornata può finire in fondo). Una lista confrontata con un ordine preciso deve avere il suo `orderBy`.
- **Booleani letti senza modello** (`DB::table(...)->value('is_published')`): `1` su SQLite e MySQL, `true` su PostgreSQL. Si confrontano con `(bool)`, o si legge dal modello che ha il cast.
- **LIKE distingue le maiuscole**: la ricerca usa `lower()` su colonna e testo (`App\Support\LikePattern`, [search.md](search.md)).
- **Ordine alfabetico**: PostgreSQL ordina secondo la lingua (maiuscole e minuscole insieme), SQLite per codice (tutte le maiuscole prima). I test usano nomi con l'iniziale maiuscola, uguali nei due ordini.
- **Lunghezza delle stringhe**: SQLite non controlla `string(..., 150)`, PostgreSQL sì. La validazione (Form Request) deve avere lo stesso limite della colonna.
- **Nessun SQL grezzo nuovo senza una ragione**: oggi ce ne sono solo tre e funzionano sui tre database.
    - `LikePattern` usa `lower() like ? escape '!'`.
    - L'ordine delle prenotazioni usa `case when status = ? then 0 else 1 end`.
    - Una migrazione copia `updated_at` con `DB::raw`.

## Codici SQLSTATE per database

Gli errori di integrità che il codice legge. Il primo valore di `errorInfo` è lo SQLSTATE, il secondo il codice del driver.

| Errore                                    | SQLite                                                          | MySQL                              | PostgreSQL                                       | Chi lo riconosce                              |
| ----------------------------------------- | --------------------------------------------------------------- | ---------------------------------- | ------------------------------------------------ | --------------------------------------------- |
| riga ancora usata, cancellazione bloccata | `23000`, codice 19, messaggio « FOREIGN KEY constraint failed » | `23000`, codice 1451               | `23001` (restrict_violation, `restrictOnDelete`) | `App\Profile\ForeignKeyViolation`             |
| chiave esterna violata in generale        | come sopra                                                      | `23000`, codice 1452 (inserimento) | `23503` (foreign_key_violation)                  | `App\Profile\ForeignKeyViolation`             |
| valore già usato (unique)                 | `23000`, codice 19, messaggio « UNIQUE constraint failed »      | `23000`, codice 1062               | `23505` (unique_violation)                       | Laravel: `UniqueConstraintViolationException` |

- `ForeignKeyViolation` è usata da `ManageService` e `ManageOption`: un servizio o un'opzione prenotati non si cancellano, e il provider vede il messaggio « ha prenotazioni » invece di un errore 500.
- L'unicità (slug in `CreateProviderProfile` e `UpdateProviderProfile`) la riconosce Laravel per ogni database: il codice prende solo `UniqueConstraintViolationException`, senza leggere codici.
- `PostgresCompatibilityTest` prova i codici della tabella, e provoca una violazione vera di ogni tipo sul database su cui girano i test: SQLite in locale, PostgreSQL nel job `tests-postgres`.

**La lezione**: i codici di errore vanno verificati su PostgreSQL vero, non dedotti dalla documentazione o da un altro database. La prima versione di `ForeignKeyViolation` riconosceva `23503`, il codice « generale »; PostgreSQL però, per una cancellazione bloccata da `restrictOnDelete`, dà `23001`, e il primo giro di `tests-postgres` ha avuto 8 test rossi. Un codice nuovo si aggiunge alla tabella solo dopo averlo visto in un errore vero (il log del job o un test che lo provoca).
