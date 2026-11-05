# Griot: archiviazione delle immagini

Dove stanno le immagini del sito (oggi la foto del provider, domani la galleria della #143) e cosa serve in produzione (issue #61).

## Il disco `media`

- Il codice salva e legge le immagini solo dal disco `media` di `config/filesystems.php`, sempre attraverso `App\Support\MediaDisk` (l'unico punto che ne scrive il nome).
- `MEDIA_DISK_DRIVER` sceglie il tipo:
    - `local` (predefinito, sviluppo e test): la cartella `storage/app/public` e l'indirizzo `APP_URL/storage`, serviti dal link simbolico `public/storage` (`php artisan storage:link`). È la stessa cartella del disco `public`.
    - `s3` (produzione): un bucket compatibile S3, con i file pubblici (`visibility` `public`), un errore se una scrittura fallisce (`throw`), e l'intestazione `Cache-Control: public, max-age=31536000, immutable` su ogni file caricato (i nomi sono casuali, quindi un file non cambia mai).
- Il disco `public` e il disco predefinito (`FILESYSTEM_DISK`) non cambiano.
- L'indirizzo di un'immagine è assoluto: in locale parte da `APP_URL`, in produzione da `AWS_URL`. Non è salvato nel database: viene ricostruito dal percorso a ogni pagina.
- Il driver S3 è il pacchetto `league/flysystem-aws-s3-v3`. `composer.json` richiede anche le estensioni PHP `gd` (con WebP) ed `exif`, usate per ricodificare la foto.

## Al deploy (produzione)

1. Creare un bucket a Parigi (Scaleway Object Storage, regione `fr-par`), con lettura pubblica degli oggetti.
2. Creare una chiave d'accesso con i permessi su quel bucket (lettura, scrittura, cancellazione).
3. Impostare le variabili nel servizio di hosting, mai nel repo:

    ```bash
    MEDIA_DISK_DRIVER=s3
    AWS_ACCESS_KEY_ID=…
    AWS_SECRET_ACCESS_KEY=…
    AWS_DEFAULT_REGION=fr-par
    AWS_BUCKET=<nome del bucket>
    AWS_ENDPOINT=https://s3.fr-par.scw.cloud
    AWS_URL=https://<nome del bucket>.s3.fr-par.scw.cloud
    AWS_USE_PATH_STYLE_ENDPOINT=false
    ```

4. **Nessun `php artisan storage:link` in produzione**: le immagini non passano dal disco del server.
5. Prova della #61: caricare una foto, rilanciare il deploy, la foto c'è ancora.

## Cancellazione

- Una foto nuova sostituisce la vecchia, che viene tolta dal disco dopo il salvataggio (`App\Profile\StoreProviderAvatar`).
- Cancellando un account provider (`Settings\ProfileController::destroy`), la sua foto viene tolta dal disco **dopo** che l'account è stato cancellato. Se toglierla fallisce, l'account resta cancellato e il log ha un avviso senza dati personali; il file va tolto a mano.
- Se la cancellazione dell'account è rifiutata (un provider con prenotazioni, #133), la foto resta.
- Togliere la sola foto, senza sostituirla, non è ancora possibile (#143).

## Non verificato

Senza un bucket vero non sono stati provati:

- che i file arrivino con `Content-Type: image/webp` (Flysystem lo ricava dal file; dipende anche dal servizio);
- che la lettura pubblica funzioni con la `visibility` `public` su Scaleway (alcuni servizi richiedono anche una regola sul bucket);
- che l'intestazione `Cache-Control` venga conservata e restituita;
- che `og:image` con una foto WebP sia mostrata da WhatsApp, Instagram e Facebook (la documentazione di Meta per `og:image:type` elenca solo JPEG, GIF e PNG; vedi [link-previews.md](link-previews.md)).
