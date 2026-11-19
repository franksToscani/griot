# Griot: deploy su Render (runbook)

Che cosa fare, passo per passo, per mettere Griot online su Render e tenerlo in piedi (issue #60, #63, #64). I file sono `Dockerfile`, `docker/` e `render.yaml`; la configurazione di produzione è in [production.md](production.md).

Regola di questo documento: quello che riguarda Render, Google o altri fornitori è scritto solo se letto su una loro pagina ufficiale, con il link. Il resto è segnato **da verificare**, con la pagina da controllare. Nessun valore vero (indirizzi, password, chiavi) va mai scritto qui o nella repository: negli esempi ci sono solo valori finti.

## 1. Che cosa c'è e quanto costa

- **Un servizio web Docker** (`render.yaml`, piano `0.5c-512mb`, Francoforte): un solo container con il server web (FrankenPHP), la coda (`queue:work`) e lo scheduler (`schedule:work`).
- **PostgreSQL 18** (`0.1c-256mb`, Francoforte), collegato al servizio con `DB_URL`.
- **La posta**: Gmail nella fase privata (sezione 4), poi un fornitore con dominio proprio.
- **Il bucket delle foto**: Scaleway Object Storage a Milano (sezione 5).

Costi, dalla pagina dei prezzi (https://render.com/pricing):

| Voce         | Piano        | Prezzo             |
| ------------ | ------------ | ------------------ |
| Servizio web | `0.5c-512mb` | 7 $ al mese        |
| PostgreSQL   | `0.1c-256mb` | 6 $ al mese        |
| Workspace    | Hobby        | 0 $ più il consumo |

**Stima: 13 $ al mese**, più il dominio (quando servirà) e l'IVA. Il piano di PostgreSQL superiore, `0.5c-1g`, costa 19 $ al mese. La regione non si cambia dopo la creazione: scegliere Francoforte (`frankfurt`) fin dall'inizio, come in `render.yaml`.

Traffico delle pagine che si aggiornano da sole ([bookings.md](bookings.md)): ogni scheda visibile della lista o di una prenotazione fa circa 3 richieste al minuto (una ogni 20 s), la pagina privata del cliente 2 (una ogni 30 s), cioè 2-4 richieste al minuto per scheda. Le schede nascoste e quelle ferme da 30 minuti non fanno richieste.

## 2. Prima di cominciare

1. Il repository su GitHub, già collegato a Render dall'account GitHub.
2. Un account Render.
3. Un account Gmail **nuovo, dedicato a Griot**, mai uno personale (sezione 4).
4. Una `APP_KEY`, creata in locale (sezione 3, passo 1).
5. I controlli di GitHub verdi su `main` ([ci.md](ci.md)): Render fa il deploy solo dopo i controlli (`autoDeployTrigger: checksPass` in `render.yaml`).

## 3. Primo deploy passo per passo

1. **Creare la chiave dell'applicazione**, sul proprio computer, nella cartella del progetto:

    ```bash
    php artisan key:generate --show
    ```

    Il comando stampa una riga che comincia con `base64:`. Copiarla: è un segreto, non va scritta in nessun file né messaggio.

2. **Creare il Blueprint** (https://render.com/docs/infrastructure-as-code):
    1. aprire la dashboard di Render e scegliere **New > Blueprint**;
    2. nell'elenco dei repository, **Connect** sul repository di Griot;
    3. dare un nome al Blueprint e scegliere il branch `main`; Render cerca `render.yaml` nella cartella principale;
    4. controllare l'elenco delle risorse che Render creerà (il servizio `griot` e il database `griot-db`);
    5. scrivere i valori delle variabili richieste (passo 3);
    6. **Deploy Blueprint**.

3. **Le variabili da scrivere a mano** (quelle con `sync: false` in `render.yaml`). Render chiede i valori per i servizi nuovi creati dal Blueprint; quando esattamente li chiede nella procedura è **da verificare** su https://render.com/docs/blueprint-spec. Gli esempi sono finti.

    | Variabile                    | Che cosa è                                                 | Dove si trova il valore                               | Esempio finto                                 |
    | ---------------------------- | ---------------------------------------------------------- | ----------------------------------------------------- | --------------------------------------------- |
    | `APP_KEY`                    | chiave di cifratura di sessioni e cookie                   | passo 1                                               | `base64:…` (non scriverla mai altrove)        |
    | `APP_URL`                    | l'indirizzo pubblico in https                              | l'indirizzo `onrender.com` del servizio, o il dominio | `https://griot.example.test`                  |
    | `MAIL_HOST`                  | il server SMTP                                             | sezione 4                                             | `smtp.gmail.com`                              |
    | `MAIL_USERNAME`              | l'utente SMTP                                              | l'indirizzo Gmail dedicato                            | `<indirizzo-gmail>`                           |
    | `MAIL_PASSWORD`              | la password SMTP                                           | la password per le app di Google (sezione 4)          | 16 caratteri                                  |
    | `MAIL_FROM_ADDRESS`          | il mittente delle email                                    | lo stesso indirizzo Gmail                             | `<indirizzo-gmail>`                           |
    | `AWS_ACCESS_KEY_ID`          | la chiave d'accesso del bucket                             | console di Scaleway (sezione 5)                       | `<chiave d'accesso>`                          |
    | `AWS_SECRET_ACCESS_KEY`      | la chiave segreta del bucket                               | console di Scaleway                                   | (segreto)                                     |
    | `AWS_DEFAULT_REGION`         | la regione del bucket                                      | console di Scaleway                                   | `it-mil`                                      |
    | `AWS_BUCKET`                 | il nome del bucket                                         | console di Scaleway                                   | `nome-del-bucket`                             |
    | `AWS_ENDPOINT`               | l'indirizzo del servizio S3                                | documentazione di Scaleway                            | `https://s3.it-mil.scw.cloud`                 |
    | `AWS_URL`                    | l'indirizzo pubblico delle foto                            | documentazione di Scaleway                            | `https://nome-del-bucket.s3.it-mil.scw.cloud` |
    | `CONTACT_EMAIL`              | contatto del sito nel footer (vuoto: nessun contatto)      | una casella di Griot                                  | `info@example.test`                           |
    | `CONTACT_WHATSAPP`           | WhatsApp del sito, formato internazionale (vuoto: nessuno) | il numero di Griot                                    | `<+39 e il numero>`                           |
    | `PRIVACY_CONTROLLER_NAME`    | il titolare del trattamento in `/privacy`                  | decisione di Franks ([legal.md](legal.md))            | `Nome Esempio`                                |
    | `PRIVACY_CONTACT_EMAIL`      | l'email per la privacy in `/privacy`                       | una casella di Griot                                  | `privacy@example.test`                        |
    | `TERMS_PUBLISHER_NAME`       | l'editore in `/terms`                                      | decisione di Franks                                   | `Nome Esempio`                                |
    | `TERMS_PUBLISHER_ADDRESS`    | l'indirizzo dell'editore                                   | decisione di Franks                                   | `Via Esempio 1, 00000 Città`                  |
    | `TERMS_PUBLISHER_VAT_NUMBER` | la partita IVA dell'editore                                | decisione di Franks                                   | `IT-ESEMPIO`                                  |

    Le variabili `AWS_*` servono solo con le foto (sezione 5). Se Render accetta valori vuoti per le variabili `sync: false` è **da verificare**. I contatti e i dati dell'editore vuoti mostrano un segnaposto o nessun contatto. `CONTACT_WHATSAPP` serve anche al link « Chiama » (`tel:`) del pannello di aiuto della home: lo stesso numero, solo le cifre con il `+` davanti.

4. **Aspettare il `preDeployCommand`** (`php artisan migrate --force`): crea le tabelle prima che la nuova versione parta. È disponibile sui servizi web a pagamento (https://render.com/docs/blueprint-spec). Se fallisce, il deploy si ferma: leggere i log (sezione 7).

5. **Controllare `/up`**: l'indirizzo del servizio seguito da `/up` deve rispondere 200. Render lo usa come controllo di salute (`healthCheckPath`).

    ```bash
    curl -s -o /dev/null -w '%{http_code}\n' https://<indirizzo-del-servizio>/up
    ```

    Senza una posta vera `/up` non risponde: `ProductionMailer` blocca ogni pagina se `MAIL_MAILER` è `log` o `array` ([production.md](production.md)). Con `MAIL_MAILER=smtp` (in `render.yaml`) e i valori della sezione 4 è a posto.

6. **Caricare le categorie**, dalla **Shell** del servizio (pagina **Shell** della dashboard, disponibile sui servizi web a pagamento: https://render.com/docs/ssh):

    ```bash
    php artisan db:seed --class=CategorySeeder --force
    ```

    Mai `php artisan db:seed` senza `--class`: i dati di sviluppo si rifiutano fuori da `local` e `testing`. Su un database che ha già le categorie di prima delle nove (sezione 9, « Le nove categorie ») non serve: le aggiorna la migrazione.

7. **Controllare che il sito sia privato** (`SITE_NOINDEX=true` in `render.yaml`):

    ```bash
    curl -s -D - -o /dev/null https://<indirizzo-del-servizio>/up | grep -i x-robots-tag
    curl -s https://<indirizzo-del-servizio>/robots.txt
    ```

    Attesi: `X-Robots-Tag: noindex, nofollow`, e `robots.txt` con `Disallow: /`.

## 4. Posta con Gmail (fase privata)

Solo per la fase privata, con poche email di prova. Al lancio pubblico serve un fornitore con dominio proprio (fine della sezione).

1. Creare un account Gmail **nuovo**, dedicato a Griot.
2. Attivare la **verifica in due passaggi** sull'account: è obbligatoria per avere una password per le app (https://support.google.com/accounts/answer/185833).
3. Creare una **password per le app** dalla pagina indicata in https://support.google.com/accounts/answer/185833 (« Create and manage your app passwords »). È un codice di 16 cifre. Non è disponibile se la verifica in due passaggi usa solo chiavi di sicurezza, per gli account di lavoro o scuola e con la Protezione avanzata (stessa pagina). Il percorso esatto dei menu è **da verificare** sulla pagina.
4. Scrivere le variabili nel servizio (sezione 10 per cambiarle):

    ```text
    MAIL_MAILER=smtp              (già in render.yaml)
    MAIL_HOST=smtp.gmail.com
    MAIL_PORT=587                 (già in render.yaml)
    MAIL_USERNAME=<indirizzo-gmail>
    MAIL_PASSWORD=<password per le app, 16 cifre>
    MAIL_FROM_ADDRESS=<indirizzo-gmail>
    ```

    `MAIL_FROM_NAME` non serve: senza, il nome del mittente è `APP_NAME`, cioè `Griot`. Host e porta di Gmail sono **da verificare** su https://support.google.com/mail/answer/7126229.

5. **Provare l'invio** dalla Shell del servizio, verso un indirizzo di prova:

    ```bash
    php artisan tinker --execute='Illuminate\Support\Facades\Mail::raw("Prova di invio di Griot", function ($message) { $message->to("<indirizzo-di-prova>")->subject("Prova di Griot"); });'
    ```

    L'email parte subito, senza la coda. Se il comando dà un errore, il messaggio dice il motivo (password, host, porta).

6. **Se una email delle prenotazioni non parte**: le email delle prenotazioni passano dalla coda, con tre tentativi (dopo 1 e 5 minuti). Dopo l'ultimo il job finisce in `failed_jobs`:

    ```bash
    php artisan queue:failed
    php artisan queue:retry all
    ```

    Ogni ora `jobs:report-failed` scrive nel log quanti job sono falliti dall'ultimo controllo (sezione 7).

7. **Limiti di Gmail** (https://support.google.com/mail/answer/22839): oltre 500 destinatari in un messaggio o oltre 500 email in un giorno, Gmail blocca l'invio (« You have reached a limit for sending mail ») e si riprende dopo 1-24 ore. **Al lancio pubblico Gmail non va usato**: il mittente sarebbe un indirizzo Gmail e non il dominio di Griot, con un limite giornaliero e senza le firme del dominio. Ogni prenotazione dal modulo con un'email del cliente manda due email (al professionista e « richiesta inviata » al cliente), più una per l'esito: circa 160 prenotazioni con email in un giorno bastano a toccare il tetto ([bookings.md](bookings.md)).

8. **Fornitore con dominio proprio** (#62): serve un dominio di Griot con i record DNS che il fornitore indica, SPF, DKIM e DMARC, per non finire nello spam. Proposta: Brevo o Resend. Le fasce gratuite sono **da verificare** su https://www.brevo.com/pricing/ e https://resend.com/pricing. Poi si cambiano `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` e `MAIL_FROM_ADDRESS`.

## 5. Foto

**Senza bucket** (letto nel codice):

- `render.yaml` imposta `MEDIA_DISK_DRIVER=s3`: il disco `media` è un bucket S3 (`config/filesystems.php`), con `throw` attivo.
- Non c'è nessun controllo all'avvio: il disco viene creato solo quando qualcosa lo usa (`App\Support\MediaDisk`). Lo usano il caricamento e la cancellazione di una foto, e l'indirizzo di una foto che esiste (`Provider::avatarUrl()`, `ProviderPhoto::urlFor()`).
- Quindi, senza foto nel database, le pagine funzionano. Il caricamento di una foto (del profilo o della galleria) con le variabili `AWS_*` vuote non salva niente: il professionista resta sulla sua pagina con il messaggio « Non è stato possibile salvare la foto. Riprova più tardi. » sul campo, e il log ha l'errore (`AvatarNotStored` o `PhotoNotStored`, provato nei test con il disco non configurato; [media-storage.md](media-storage.md)).
- **Le foto « temporanee » sul disco del container non funzionano**: con `MEDIA_DISK_DRIVER=local` i file andrebbero in `storage/app/public`, ma l'immagine non ha il collegamento `public/storage` (nessun `storage:link`), quindi gli indirizzi darebbero 404. In più il disco del container si cancella a ogni deploy.

**Il primo deploy, quindi, si fa senza foto**: i professionisti di prova non caricano foto finché il bucket non c'è.

**Configurare Scaleway Object Storage a Milano** (la scelta di [media-storage.md](media-storage.md)); i passi della console sono **da verificare** su https://www.scaleway.com/en/docs/object-storage/:

1. creare un bucket nella regione `it-mil`, con lettura pubblica degli oggetti (Laravel scrive i file con visibilità pubblica);
2. creare un'**applicazione IAM dedicata** (mai l'utente personale), con una policy che dà solo lettura, scrittura e cancellazione degli oggetti, limitata al progetto del sito, e una chiave API per quell'applicazione. La chiave scade dopo 1 anno: rinnovarla prima (promemoria: ottobre 2027) e aggiornare le variabili (sezione 10);
3. scrivere `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT` e `AWS_URL` nel servizio (sezione 10);
4. caricare una foto di prova e controllare i punti « Non verificato » di [media-storage.md](media-storage.md):
    - la foto si vede (lettura pubblica);
    - `Content-Type: image/webp`;
    - `Cache-Control: public, max-age=31536000, immutable`;

    ```bash
    curl -s -D - -o /dev/null "<indirizzo di una foto>" | grep -iE 'content-type|cache-control'
    ```

5. rifare un deploy e controllare che la foto ci sia ancora (#61).

Un 403 Forbidden sulla scrittura viene spesso da una chiave incollata due volte (access key e secret key scambiate o uguali): controllare `AWS_ACCESS_KEY_ID` e `AWS_SECRET_ACCESS_KEY`.

La prova gratuita di Object Storage termina il **12 febbraio 2027**, poi il costo è a consumo: verificare i prezzi nella pagina ufficiale di Scaleway.

## 6. Il primo professionista

Dalla Shell del servizio:

```bash
php artisan provider:create "Nome della vetrina" persona@example.test Città
```

- Il comando crea l'utente, con l'email già verificata, e il suo provider **non pubblicato**.
- Stampa una password iniziale **una sola volta**: copiarla subito. Non è salvata da nessuna parte.
- Consegnarla di persona o per telefono, mai per email o in una chat che resta. Il professionista la cambia in « Impostazioni → Sicurezza » ([production.md](production.md)).

## 7. Deploy normale, log e job falliti

- **Deploy**: si fa da solo dopo un merge su `main`, **solo quando i controlli di GitHub sono verdi** (`autoDeployTrigger: checksPass`, https://render.com/docs/blueprint-spec). Le migrazioni girano prima (`preDeployCommand`).
- **Log**: pagina **Logs** del servizio (https://render.com/docs/logging). Sul workspace Hobby i log restano 7 giorni. L'app scrive su `stderr` con livello `info`; il server web e la coda scrivono nello stesso log.
- **Job falliti**:

    ```bash
    php artisan queue:failed
    php artisan queue:retry all
    ```

- **Avviso orario**: `jobs:report-failed` (ogni ora, `routes/console.php`) scrive nel log una riga di errore « New failed jobs since the last check. » con il numero di job falliti nuovi, la loro classe e l'orario del primo e dell'ultimo. Mai il contenuto del job né l'email del cliente. Cercare quella frase nei log. Un avviso fuori dai log (email, notifica) **non c'è ancora**: è un punto aperto di #63.

## 8. Rollback

Dalla pagina **Deploys** del servizio, un deploy riuscito → **Rollback** → **Rollback to this deploy** (https://render.com/docs/rollbacks).

- Il rollback dalla dashboard **disattiva il deploy automatico**: riattivarlo dalle impostazioni del servizio dopo aver corretto il problema (stessa pagina).
- Il rollback salta la build. Se rilancia il `preDeployCommand` è **da verificare** sulla stessa pagina.
- **Le migrazioni non tornano indietro da sole.** Una migrazione già eseguita resta: la versione precedente del codice gira con il database nuovo. Per questo le migrazioni devono essere compatibili con la versione precedente (colonne aggiunte, non tolte o rinominate nello stesso rilascio). Annullare l'ultima migrazione si può solo se il suo `down()` non perde dati, dalla Shell:

    ```bash
    php artisan migrate:rollback --step=1 --force
    ```

## 9. Backup e ripristino di PostgreSQL (#63)

### Che cosa c'è (verificato sulla dashboard il 18/11/2026)

- **Database:** PostgreSQL 18, piano `0.1c-256mb` (6 $ al mese), gestito dal Blueprint (`render.yaml`), 15 GB di spazio.
- **Recupero a un punto nel tempo: attivo, finestra di 3 giorni.** Il workspace è Hobby; 7 giorni solo sui workspace Pro o superiori. Da https://render.com/docs/postgresql-backups (pagina letta): i database a pagamento sono salvati di continuo; non si può tornare agli ultimi dieci minuti; **il ripristino crea un database nuovo**, non sovrascrive quello esistente (pagina **Recovery** del database → **Restore Database** → nome e momento → **Start Recovery**).
- **Esportazioni manuali:** pagina **Recovery** → **Create export**, un archivio `.dir.tar.gz` (formato directory di `pg_dump`) che Render tiene 7 giorni. Render non le programma da solo.
- **Avvisi di Render:** impostati su « Only failure notifications » (https://render.com/docs/notifications). Riguardano deploy, servizi che non rispondono e cron job; **non** i backup né le esportazioni del database: per quelli Render non manda avvisi.
- **Il piano gratuito dei database non va bene per Griot**: secondo https://render.com/docs/free un database gratuito scade 30 giorni dopo la creazione (poi 14 giorni per passare a un piano a pagamento, quindi viene cancellato con tutti i dati) e non ha nessuna forma di backup.

### Decisioni (Franks, 18/11/2026)

- **Copie fuori da Render: solo manuali, per ora.** Un'esportazione al mese (procedura qui sotto), con un promemoria nel calendario. Il file si cifra subito, si tiene il meno possibile e si cancella **dopo 14 giorni**.
- **Copie automatiche con allarme** (un cron job di Render che salva ogni notte una copia in un bucket privato, più un controllo `backups:check` che avvisa per email se l'ultima copia manca, circa 1 $ al mese): **da decidere** dopo la prova del ripristino completo su Render e prima di aprire a molti professionisti.
- **Niente workspace Pro per ora:** la finestra del recupero a un punto nel tempo resta di 3 giorni.
- **I segreti** (`APP_KEY`, le due chiavi di Scaleway, la password della posta) hanno una copia in un gestore di password, fuori dalla repository e da Render. Gli altri valori della dashboard si ricostruiscono da questo documento e da [production.md](production.md).

### Prova di ripristino in locale (fatta il 18/11/2026)

Un'esportazione vera, ripristinata in un PostgreSQL sul proprio computer, senza toccare la produzione. Il file contiene dati personali dei clienti: tutto avviene in una cartella fuori dalla repository e alla fine si cancella.

**Esito del 18/11/2026: riuscita.** PostgreSQL 18.6 locale (pacchetto di sistema `postgresql-18`, più `php8.5-pgsql` per PHP). `pg_restore` in 0,156 s, exit 0, nessun errore. Conteggi uguali alla produzione: 3 servizi, 10 prenotazioni, 9 categorie, 1 utente, 1 professionista, 3 foto, 23 migrazioni. 10 chiavi esterne ripristinate, contatori degli id coerenti, `migrate:status` tutto « Ran »; con `php artisan serve` la home e l'elenco si aprono (le foto no: restano su Scaleway). Pulizia fatta: database cancellato, cartella cancellata, niente in `Downloads`.

**Passi**, con valori tra `<…>` da sostituire (mai scritti in un file):

1. Prima, dalla Shell di Render, i conteggi della produzione (servizi, prenotazioni, categorie, utenti, professionisti, foto, migrazioni), per confrontarli dopo.
2. Pagina **Recovery** del database → **Create export** → scaricare l'archivio.
3. In locale, una volta sola: `sudo apt install postgresql-18 php8.5-pgsql` (la stessa versione principale della produzione) e un utente PostgreSQL che possa creare database.
4. Ripristino, in una cartella fuori dalla repository:

    ```bash
    mkdir -p ~/<cartella-di-prova> && cd ~/<cartella-di-prova>
    tar -xzf <archivio>.dir.tar.gz
    createdb griot_restore
    time pg_restore --no-owner --no-acl --dbname=griot_restore <cartella-scompattata>
    ```

    Deve finire con exit 0 e nessun errore.

5. Controlli sul database ripristinato:

    ```bash
    psql griot_restore -c "select count(*) from services"   # e così per bookings, categories, users, providers, provider_photos, migrations
    psql griot_restore -c "select count(*) from pg_constraint where contype = 'f'"   # le chiavi esterne
    psql griot_restore -c "select max(id) from bookings"
    psql griot_restore -c "select last_value from bookings_id_seq"   # mai più basso del max(id)
    ```

6. L'app sulla copia, con le variabili passate **solo al comando** (mai scritte in `.env`), dalla cartella del progetto e senza configurazione in cache (`php artisan config:clear` se serve):

    ```bash
    DB_CONNECTION=pgsql DB_HOST=/var/run/postgresql DB_DATABASE=griot_restore DB_USERNAME="$USER" DB_PASSWORD= \
    MAIL_MAILER=array QUEUE_CONNECTION=sync MEDIA_DISK_DRIVER=local \
    php artisan migrate:status
    ```

    Poi, con le stesse variabili, `php artisan serve --port=<una porta libera>` e aprire la home e l'elenco.

    **Sulla copia non si lancia MAI `queue:work` né lo scheduler** (`schedule:work`, `schedule:run`): la tabella `jobs` può contenere email per clienti veri, che partirebbero davvero.

7. Pulizia:

    ```bash
    dropdb griot_restore
    rm -r ~/<cartella-di-prova>
    ```

    E cancellare l'archivio da `Downloads` (e dal cestino). Il disco del computer non è cifrato: i dati in chiaro devono restarci il meno possibile.

8. Una riga nel registro privato delle prove (sotto), mai nella repository.

### Non ancora provato: il ripristino completo su Render (da fare prima del lancio)

Il recupero a un punto nel tempo crea un **database nuovo**, con un nome nuovo. Il servizio prende `DB_URL` da `fromDatabase` nel Blueprint (`render.yaml`, `name: griot-db`): per collegarlo al database ripristinato serve una modifica a `render.yaml` (una PR) oppure un `DB_URL` scritto a mano nella pagina **Environment**. **Non sappiamo** se una sincronizzazione del Blueprint sovrascriverebbe un `DB_URL` scritto a mano. È proprio il caso in cui il sito servirebbe davvero, quindi va provato prima del lancio: un ripristino di prova in un database temporaneo, come ricollegare il servizio (senza farlo sulla produzione, o in un momento senza utenti), quanto tempo serve, e poi cancellare il database temporaneo (che si paga finché esiste).

### Ogni mese: l'esportazione manuale

1. Pagina **Recovery** del database → **Create export** → scaricare l'archivio.
2. Cifrarlo subito e togliere il file in chiaro:

    ```bash
    gpg --symmetric --cipher-algo AES256 <archivio>.dir.tar.gz
    rm <archivio>.dir.tar.gz
    ```

    La frase di accesso va nel gestore di password, mai accanto al file. Svuotare anche `Downloads` e il cestino.

3. Tenere il file cifrato fuori dalla repository e **cancellarlo dopo 14 giorni** (un secondo promemoria nel calendario).
4. Una riga nel registro privato (data dell'esportazione, data di cancellazione).

Per leggerlo: `gpg --decrypt <archivio>.dir.tar.gz.gpg > <archivio>.dir.tar.gz`, poi i passi della prova qui sopra.

### Rischio accettato: le foto non hanno copia (decisione del 18/11/2026)

Le foto su Scaleway **non hanno né copia né versioning**. Se una foto viene cancellata (per un bug, un errore o una chiave rubata, che può anche cancellare) è persa per sempre, salvo che il professionista la ricarichi. Anche un ripristino del database a qualche giorno prima non la riporta. **Da rivedere prima di aprire a molti professionisti.** Per chi deciderà: con il versioning le versioni vecchie si possono far scadere con una regola di ciclo di vita (serve anche per non tenere troppo le foto che un professionista ha cancellato); secondo la documentazione di Scaleway il versioning, una volta attivato, si può sospendere ma non togliere (da verificare su https://www.scaleway.com/en/docs/object-storage/how-to/use-bucket-versioning/).

### Registro delle prove e delle esportazioni

Da copiare in un posto privato (non nella repository, perché contiene date e dettagli dei dati):

| Data | Tipo (punto nel tempo / esportazione / pg_dump) | Momento ripristinato | Database di destinazione | Controlli fatti (righe di `bookings`, `providers`, `users`) | Esito | Durata | Cancellato il | Note |
| ---- | ----------------------------------------------- | -------------------- | ------------------------ | ----------------------------------------------------------- | ----- | ------ | ------------- | ---- |
|      |                                                 |                      |                          |                                                             |       |        |               |      |

### Le nove categorie (migrazione `rename_and_merge_categories`)

Una migrazione di soli dati: rinomina quattro categorie (`barbiere` → `taglio-uomo`, `nails` → `unghie`, `couture` → `moda-afro`, `ristorazione` → `food`), unisce `ciglia` e `sopracciglia` in `ciglia-sopracciglia` spostando i servizi, e aggiunge `taglio-donna`, `locs` e `make-up`. Parte **da sola al deploy**, come ogni migrazione: il `preDeployCommand` di `render.yaml` lancia `php artisan migrate --force` prima che la versione nuova parta. La sequenza sicura:

1. **Prima del merge**, i conteggi dalla **Shell** del servizio (sola lettura):

    ```bash
    php artisan tinker --execute="foreach (App\Models\Category::withCount('services')->orderBy('slug')->get() as \$c) echo \$c->slug.' '.\$c->services_count.PHP_EOL;"
    ```

    Annotare anche il totale dei servizi e delle prenotazioni (`App\Models\Service::count()`, `App\Models\Booking::count()`).

2. **Una copia del database**: un'esportazione dalla dashboard, come in « Ogni mese: l'esportazione manuale » qui sopra. Il `down()` della migrazione **non sa separare** i servizi uniti di ciglia e sopracciglia: per tornare indietro del tutto serve questa copia.
3. **Merge** su `main`: con i controlli verdi parte il deploy, e il `preDeployCommand` lancia la migrazione. Se fallisce, il deploy si ferma e la versione di prima resta online (la migrazione è in una transazione: tutto o niente).
4. **Dopo il deploy**, gli stessi conteggi: nove categorie; i servizi di `barbiere`, `nails`, `couture` e `ristorazione` sotto i nomi nuovi; `ciglia-sopracciglia` con la somma di ciglia e sopracciglia; `taglio-donna`, `locs` e `make-up` a 0. Il totale dei servizi e delle prenotazioni **identico** a prima.
5. **Il `CategorySeeder` non serve**: la migrazione fa tutto. Se lo si lancia, deve essere la versione nuova (nove slug): quella vecchia ricreerebbe `barbiere` e le altre come categorie in più.

## 10. Aggiornare un segreto

Pagina **Environment** del servizio → cambiare il valore → salvare con una delle tre scelte (https://render.com/docs/configure-environment-variables):

- **Save, rebuild, and deploy**: nuova build e deploy;
- **Save and deploy**: deploy della build esistente con le variabili nuove;
- **Save only**: le variabili valgono dal deploy successivo.

L'app mette in cache la configurazione all'avvio del container (`docker/start.sh`): un valore nuovo vale solo dopo un deploy. Di solito basta **Save and deploy**.

## 11. Se la coda o lo scheduler si fermano

**La coda** (`queue:work`):

- Segni: le email delle prenotazioni non arrivano; nei log ci sono errori ripetuti del processo `queue`; la tabella `jobs` cresce:

    ```bash
    php artisan tinker --execute='echo Illuminate\Support\Facades\DB::table("jobs")->count(), PHP_EOL;'
    ```

- Rimedi:
    1. leggere i log: un errore della posta (sezione 4) o del database;
    2. `php artisan queue:failed` e, dopo la correzione, `php artisan queue:retry all`;
    3. un nuovo deploy (**Save and deploy** o un deploy manuale dalla dashboard; il nome del pulsante è **da verificare**) riavvia il container.

    Supervisord riavvia da solo la coda quando si ferma (`docker/supervisord.conf`).

**Lo scheduler** (`schedule:work`):

- Segni: i promemoria delle richieste in attesa non partono; le prenotazioni vecchie non diventano anonime; ogni ora manca la riga di `jobs:report-failed` quando ci sono job falliti.
- Rimedi:

    ```bash
    php artisan schedule:list
    php artisan bookings:remind-pending
    php artisan bookings:anonymize-old --force
    php artisan jobs:report-failed
    ```

    Poi un nuovo deploy. Anche lo scheduler è riavviato da supervisord.

**Il server web**: se si ferma, il container esce e Render ne avvia uno nuovo (`docker/stop-on-web-exit.sh`, controllo di salute su `/up`).

## 12. Primo deploy: cose da controllare

- **Render è più restrittivo di Docker in locale**: sul primo deploy `frankenphp` non partiva (`couldn't exec frankenphp: EPERM`) perché l'immagine base gli dà la capability `cap_net_bind_service`. Ora il `Dockerfile` la toglie (`setcap -r`, come indica https://frankenphp.dev/docs/docker/ per le porte da 1024 in su) e il job `docker-build` avvia il container con `--cap-drop=ALL` e `--security-opt=no-new-privileges`, per vedere lo stesso errore prima di Render ([ci.md](ci.md)).
- **`DB_SSLMODE`**: `render.yaml` non la imposta, quindi vale `prefer`; `.env.production.example` dice `require`. Il servizio usa l'indirizzo interno del database (`connectionString`), dove TLS è facoltativo e con certificati auto-firmati (https://render.com/docs/postgresql-creating-connecting). `prefer` va bene. Se la connessione fallisce: leggere l'errore nei log del `preDeployCommand` e non usare una modalità che verifica il certificato.
- **Memoria del container**: 512 MB per server web, coda e scheduler insieme, e una foto di 4000×4000 occupa circa 64 MB mentre viene ricodificata. Se i log mostrano riavvii per memoria, cercare « memory » o « killed » nei log e valutare il piano da 25 $ al mese (prezzi su https://render.com/pricing).
- **Log delle richieste** (https://render.com/docs/logging, consultata il 19/11/2026): « If you have a Pro workspace or higher, Render generates a log entry for each HTTP request », con il metodo, il codice di stato e l'indirizzo richiesto, quindi il percorso. Sul workspace Hobby di Griot questi log non ci sono: il codice segreto delle pagine private (`/bookings/{codice}`) non finisce nei log di Render. Con il Pro ci finirebbe, conservato 14 giorni (sulla stessa pagina: Hobby 7 giorni, Pro 14, Scale ed Enterprise 30). Prima di passare al Pro si rivede la #186 ([legal.md](legal.md), « Decisioni sulla #186 »). Il server web del container non scrive log di accesso.

## 13. Da privato a pubblico

Solo quando tutti i punti sono fatti:

1. testi legali rivisti da una persona competente (#131, [legal.md](legal.md)): poi `PRIVACY_REVIEWED=true` e `TERMS_REVIEWED=true`;
2. dati del titolare e dell'editore scritti (`PRIVACY_*`, `TERMS_PUBLISHER_*`);
3. posta con un fornitore e un dominio proprio, non Gmail (#62);
4. #186 chiusa: log, `failed_jobs`, codice segreto nell'URL, compresa la verifica dei log del proxy di Render;
5. il dominio di Griot con https, e `APP_URL` aggiornato. I link che il sito costruisce si aggiornano da soli, perché nascono da `APP_URL` a ogni richiesta (`App\Support\PublicUrl`, [production.md](production.md), « Il tuo link »): il riquadro « Il tuo link » della dashboard, l'indirizzo completo sotto il campo del profilo, le anteprime dei link e le email. **I link già diffusi no**: quelli che i professionisti hanno copiato, condiviso o mandato su WhatsApp con il vecchio indirizzo funzionano solo finché il vecchio indirizzo risponde. Lo stesso per il QR code: nella dashboard nasce dal nuovo indirizzo, ma quelli già stampati portano al vecchio. **Non far stampare QR code prima del dominio definitivo**, e dopo il cambio avvisare i professionisti di ricopiare il link e riscaricare il QR ([provider-link.md](provider-link.md));
6. `SITE_NOINDEX=false`, poi controllare che `/robots.txt` torni a permettere tutto e che `X-Robots-Tag` non ci sia più sulle pagine pubbliche.
