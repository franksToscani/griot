# Griot: deploy su Render (runbook)

Che cosa fare, passo per passo, per mettere Griot online su Render e tenerlo in piedi (issue #60, #63, #64). I file sono `Dockerfile`, `docker/` e `render.yaml`; la configurazione di produzione è in [production.md](production.md).

Regola di questo documento: quello che riguarda Render, Google o altri fornitori è scritto solo se letto su una loro pagina ufficiale, con il link. Il resto è segnato **da verificare**, con la pagina da controllare. Nessun valore vero (indirizzi, password, chiavi) va mai scritto qui o nella repository: negli esempi ci sono solo valori finti.

## 1. Che cosa c'è e quanto costa

- **Un servizio web Docker** (`render.yaml`, piano `0.5c-512mb`, Francoforte): un solo container con il server web (FrankenPHP), la coda (`queue:work`) e lo scheduler (`schedule:work`).
- **PostgreSQL 18** (`0.1c-256mb`, Francoforte), collegato al servizio con `DB_URL`.
- **La posta**: Gmail nella fase privata (sezione 4), poi un fornitore con dominio proprio.
- **Il bucket delle foto**: Scaleway Object Storage a Parigi, quando servono le foto (sezione 5).

Costi, dalla pagina dei prezzi (https://render.com/pricing):

| Voce         | Piano        | Prezzo             |
| ------------ | ------------ | ------------------ |
| Servizio web | `0.5c-512mb` | 7 $ al mese        |
| PostgreSQL   | `0.1c-256mb` | 6 $ al mese        |
| Workspace    | Hobby        | 0 $ più il consumo |

**Stima: 13 $ al mese**, più il dominio (quando servirà) e l'IVA. Il piano di PostgreSQL superiore, `0.5c-1g`, costa 19 $ al mese. La regione non si cambia dopo la creazione: scegliere Francoforte (`frankfurt`) fin dall'inizio, come in `render.yaml`.

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

    | Variabile                    | Che cosa è                                                 | Dove si trova il valore                               | Esempio finto                                    |
    | ---------------------------- | ---------------------------------------------------------- | ----------------------------------------------------- | ------------------------------------------------ |
    | `APP_KEY`                    | chiave di cifratura di sessioni e cookie                   | passo 1                                               | `base64:…` (non scriverla mai altrove)           |
    | `APP_URL`                    | l'indirizzo pubblico in https                              | l'indirizzo `onrender.com` del servizio, o il dominio | `https://griot.example.test`                     |
    | `MAIL_HOST`                  | il server SMTP                                             | sezione 4                                             | `smtp.gmail.com`                                 |
    | `MAIL_USERNAME`              | l'utente SMTP                                              | l'indirizzo Gmail dedicato                            | `<indirizzo-gmail>`                              |
    | `MAIL_PASSWORD`              | la password SMTP                                           | la password per le app di Google (sezione 4)          | 16 caratteri                                     |
    | `MAIL_FROM_ADDRESS`          | il mittente delle email                                    | lo stesso indirizzo Gmail                             | `<indirizzo-gmail>`                              |
    | `AWS_ACCESS_KEY_ID`          | la chiave d'accesso del bucket                             | console di Scaleway (sezione 5)                       | `<chiave d'accesso>`                             |
    | `AWS_SECRET_ACCESS_KEY`      | la chiave segreta del bucket                               | console di Scaleway                                   | (segreto)                                        |
    | `AWS_DEFAULT_REGION`         | la regione del bucket                                      | console di Scaleway                                   | `fr-par`                                         |
    | `AWS_BUCKET`                 | il nome del bucket                                         | console di Scaleway                                   | `griot-foto-esempio`                             |
    | `AWS_ENDPOINT`               | l'indirizzo del servizio S3                                | documentazione di Scaleway                            | `https://s3.fr-par.scw.cloud`                    |
    | `AWS_URL`                    | l'indirizzo pubblico delle foto                            | documentazione di Scaleway                            | `https://griot-foto-esempio.s3.fr-par.scw.cloud` |
    | `CONTACT_EMAIL`              | contatto del sito nel footer (vuoto: nessun contatto)      | una casella di Griot                                  | `info@example.test`                              |
    | `CONTACT_WHATSAPP`           | WhatsApp del sito, formato internazionale (vuoto: nessuno) | il numero di Griot                                    | `<+39 e il numero>`                              |
    | `PRIVACY_CONTROLLER_NAME`    | il titolare del trattamento in `/privacy`                  | decisione di Franks ([legal.md](legal.md))            | `Nome Esempio`                                   |
    | `PRIVACY_CONTACT_EMAIL`      | l'email per la privacy in `/privacy`                       | una casella di Griot                                  | `privacy@example.test`                           |
    | `TERMS_PUBLISHER_NAME`       | l'editore in `/terms`                                      | decisione di Franks                                   | `Nome Esempio`                                   |
    | `TERMS_PUBLISHER_ADDRESS`    | l'indirizzo dell'editore                                   | decisione di Franks                                   | `Via Esempio 1, 00000 Città`                     |
    | `TERMS_PUBLISHER_VAT_NUMBER` | la partita IVA dell'editore                                | decisione di Franks                                   | `IT-ESEMPIO`                                     |

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

    Mai `php artisan db:seed` senza `--class`: i dati di sviluppo si rifiutano fuori da `local` e `testing`.

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

7. **Limiti di Gmail** (https://support.google.com/mail/answer/22839): oltre 500 destinatari in un messaggio o oltre 500 email in un giorno, Gmail blocca l'invio (« You have reached a limit for sending mail ») e si riprende dopo 1-24 ore. **Al lancio pubblico Gmail non va usato**: il mittente sarebbe un indirizzo Gmail e non il dominio di Griot, con un limite giornaliero e senza le firme del dominio.

8. **Fornitore con dominio proprio** (#62): serve un dominio di Griot con i record DNS che il fornitore indica, SPF, DKIM e DMARC, per non finire nello spam. Proposta: Brevo o Resend. Le fasce gratuite sono **da verificare** su https://www.brevo.com/pricing/ e https://resend.com/pricing. Poi si cambiano `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` e `MAIL_FROM_ADDRESS`.

## 5. Foto

**Senza bucket** (letto nel codice):

- `render.yaml` imposta `MEDIA_DISK_DRIVER=s3`: il disco `media` è un bucket S3 (`config/filesystems.php`), con `throw` attivo.
- Non c'è nessun controllo all'avvio: il disco viene creato solo quando qualcosa lo usa (`App\Support\MediaDisk`). Lo usano il caricamento e la cancellazione di una foto, e l'indirizzo di una foto che esiste (`Provider::avatarUrl()`, `ProviderPhoto::urlFor()`).
- Quindi, senza foto nel database, le pagine funzionano. Il primo caricamento di una foto, con le variabili `AWS_*` vuote, fallisce. Che cosa vede il professionista (la pagina di errore o il messaggio « foto non salvata ») non è provato: **da verificare** sul primo deploy, dopo aver configurato il bucket.
- **Le foto « temporanee » sul disco del container non funzionano**: con `MEDIA_DISK_DRIVER=local` i file andrebbero in `storage/app/public`, ma l'immagine non ha il collegamento `public/storage` (nessun `storage:link`), quindi gli indirizzi darebbero 404. In più il disco del container si cancella a ogni deploy.

**Il primo deploy, quindi, si fa senza foto**: i professionisti di prova non caricano foto finché il bucket non c'è.

**Configurare Scaleway Object Storage a Parigi** (la scelta di [media-storage.md](media-storage.md)); i passi della console sono **da verificare** su https://www.scaleway.com/en/docs/object-storage/:

1. creare un bucket nella regione `fr-par`, con lettura pubblica degli oggetti;
2. creare una chiave d'accesso con lettura, scrittura e cancellazione su quel bucket;
3. scrivere `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT` e `AWS_URL` nel servizio (sezione 10);
4. caricare una foto di prova e controllare i punti « Non verificato » di [media-storage.md](media-storage.md):
    - la foto si vede (lettura pubblica);
    - `Content-Type: image/webp`;
    - `Cache-Control: public, max-age=31536000, immutable`;

    ```bash
    curl -s -D - -o /dev/null "<indirizzo di una foto>" | grep -iE 'content-type|cache-control'
    ```

5. rifare un deploy e controllare che la foto ci sia ancora (#61).

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

Da https://render.com/docs/postgresql-backups (pagina letta):

- **Recupero a un punto nel tempo**: i database a pagamento sono salvati di continuo. Sul workspace Hobby si può tornare a un momento degli **ultimi 3 giorni** (7 sui workspace Pro o superiori). Non vale per il piano gratuito.
- **Il ripristino crea un database nuovo**, non sovrascrive quello esistente. Pagina **Recovery** del database → **Restore Database** → nome e momento (non negli ultimi dieci minuti) → **Start Recovery**. Poi si collega il servizio al database nuovo (`DB_URL`).
- **Esportazioni**: dalla pagina **Recovery**, **Create export**. Un archivio compresso che Render tiene **7 giorni**.

**Una copia manuale periodica** con `pg_dump`, dal proprio computer, conservata fuori da Render. L'indirizzo esterno del database si trova nel menu **Connect** della pagina del database; le connessioni esterne sono sempre cifrate (https://render.com/docs/postgresql-creating-connecting). Valori tra `<…>` da sostituire, mai da scrivere in un file:

```bash
pg_dump "<URL esterno del database>" --format=custom --no-owner --file="griot-<AAAA-MM-GG>.dump"
```

Ripristino di prova in un database **vuoto** (per esempio un PostgreSQL locale):

```bash
pg_restore --no-owner --dbname="<URL di un database vuoto>" "griot-<AAAA-MM-GG>.dump"
```

Il file contiene dati personali dei clienti: va tenuto in un posto sicuro e cancellato quando non serve più ([legal.md](legal.md)).

**La prova di ripristino va fatta davvero e registrata** (#63). Modello di registro, da copiare in un posto privato (non nella repository, perché contiene date e dettagli dei dati):

| Data | Tipo (punto nel tempo / esportazione / pg_dump) | Momento ripristinato | Database di destinazione | Controlli fatti (righe di `bookings`, `providers`, `users`) | Esito | Durata | Note |
| ---- | ----------------------------------------------- | -------------------- | ------------------------ | ----------------------------------------------------------- | ----- | ------ | ---- |
|      |                                                 |                      |                          |                                                             |       |        |      |

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
- **Log delle richieste**: secondo https://render.com/docs/logging, i log di ogni richiesta HTTP con il percorso ci sono solo sui workspace Pro o superiori. Sul workspace Hobby quindi il codice segreto delle pagine private (`/bookings/{codice}`) non dovrebbe comparire nei log di Render: **da verificare** sul primo deploy (#186). Il server web del container non scrive log di accesso.

## 13. Da privato a pubblico

Solo quando tutti i punti sono fatti:

1. testi legali rivisti da una persona competente (#131, [legal.md](legal.md)): poi `PRIVACY_REVIEWED=true` e `TERMS_REVIEWED=true`;
2. dati del titolare e dell'editore scritti (`PRIVACY_*`, `TERMS_PUBLISHER_*`);
3. posta con un fornitore e un dominio proprio, non Gmail (#62);
4. #186 chiusa: log, `failed_jobs`, codice segreto nell'URL, compresa la verifica dei log del proxy di Render;
5. il dominio di Griot con https, e `APP_URL` aggiornato;
6. `SITE_NOINDEX=false`, poi controllare che `/robots.txt` torni a permettere tutto e che `X-Robots-Tag` non ci sia più sulle pagine pubbliche.
