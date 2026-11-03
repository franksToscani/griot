# Griot: anteprima dei link

Cosa compare quando qualcuno condivide un link di Griot su WhatsApp, Instagram, Facebook o simili (issue #138).

## Come funziona

- I programmi che leggono i link per fare l'anteprima (i "crawler") **non eseguono JavaScript**. Per questo i tag stanno nell'HTML della prima risposta del server, in `resources/views/partials/meta.blade.php`, incluso da `resources/views/app.blade.php`.
- I `<Head>` delle pagine React non bastano: Inertia qui non ha l'SSR (manca `bootstrap/ssr`, la build è `vp build`), quindi aggiungono il titolo solo nel browser. Nel browser Inertia toglie il `<title>` del server e mette il suo: non nascono due titoli.
- I valori li prepara `App\Support\LinkPreview`, uno per richiesta (lo crea il middleware `NoIndexPrivatePages`).
- **Ogni pagina è privata finché il suo controller non dice il contrario**: ha `<meta name="robots" content="noindex, nofollow">`, l'header `X-Robots-Tag: noindex, nofollow` e nessuna anteprima. Così una pagina nuova non può mostrare un nome o una foto per dimenticanza.
- Pagine pubbliche oggi:
    - elenco dei provider e privacy: `publicPage()`, con il titolo della pagina e la descrizione del sito o della pagina;
    - pagina di un provider pubblicato: `forProvider()`. Titolo « Nome - Categoria, Categoria a Città » (al massimo 2 categorie, poi « … »); descrizione dalla bio in testo semplice, su una riga, tagliata sulla parola a 160 caratteri con « … »; se la bio è vuota, « Prenota online da Nome a Città »; immagine: la foto del provider se c'è, altrimenti quella predefinita.
- Tag scritti: `description`, `link rel="canonical"`, Open Graph (`og:type`, `og:site_name`, `og:title`, `og:description`, `og:url`, `og:locale`, `og:image`, `og:image:alt`, e tipo e misure solo per l'immagine predefinita) e Twitter (`summary_large_image` con l'immagine predefinita, `summary` con la foto).
- Tutti gli indirizzi sono assoluti e partono da `APP_URL`, mai dall'host della richiesta. Blade fa l'escape di ogni valore: una bio con virgolette o `< >` resta testo.
- Le pagine d'errore hanno il loro `noindex` e non usano `LinkPreview`: la 404 di un provider non pubblicato non dice niente di lui.
- Una pagina pubblica nuova deve chiamare `publicPage()` (o un metodo apposta) nel suo controller, e avere un test in `tests/Feature/LinkPreviewTest.php`.

## Immagine predefinita

- `public/og-default.png`, 1200×630 (la misura consigliata da Meta), generata da `resources/images/og-default.svg`: la « G », la scritta « Griot » e i colori della palette Laguna. Nessun altro testo: va bene per tutte le lingue. Il testo alternativo è tradotto (`link_preview.image_alt`).
- Per cambiarla: modificare l'SVG, poi
    ```bash
    rsvg-convert -w 1200 -h 630 resources/images/og-default.svg -o public/og-default.png
    ```
    (`rsvg-convert` sta nel pacchetto `librsvg2-bin`). Se cambiano le misure, aggiornare `LinkPreview::DEFAULT_IMAGE_WIDTH` e `DEFAULT_IMAGE_HEIGHT`. Meta tiene le immagini in cache per indirizzo: un'immagine nuova con lo stesso nome può restare vecchia nelle anteprime già fatte (vedi sotto).

## Foto dei provider

- Oggi la foto è WebP, al massimo 512 px per lato (`App\Profile\StoreProviderAvatar`).
- La documentazione di Meta per `og:image:type` elenca solo JPEG, GIF e PNG, e consiglia almeno 1200×630 (minimo 200×200). Non è verificato se Facebook, Instagram e WhatsApp mostrano bene una WebP quadrata di 512 px: va provato con un indirizzo pubblico. Se non va, la soluzione è salvare anche una copia JPEG della foto per le anteprime.

## Lingua

I crawler non mandano il cookie della lingua: vedono la lingua predefinita (italiano). `og:locale` e `<html lang>` dicono sempre la lingua della risposta.

## Come provare un link vero

In locale non si può: i crawler devono raggiungere il sito da internet. Serve un indirizzo pubblico (dopo il deploy, o con un tunnel temporaneo verso il computer).

1. In produzione `APP_URL` deve essere l'indirizzo pubblico in HTTPS (per esempio `https://griot.example`), e i proxy di Render devono essere fidati (#59, #60): altrimenti gli indirizzi nei tag sono sbagliati.
2. Controllare l'HTML grezzo, come lo vede un crawler: `curl -s https://<indirizzo>/providers/<slug> | grep -E 'og:|twitter:|<title|canonical|robots'`.
3. Facebook e Instagram: lo strumento **Sharing Debugger** di Meta (developers.facebook.com/tools/debug) mostra i tag letti, gli avvisi, e fa rileggere la pagina.
4. WhatsApp: mandarsi il link in una chat. WhatsApp tiene in cache le anteprime: dopo un cambio può servire tempo, o un indirizzo diverso (per esempio con `?v=2` alla fine) per vedere quella nuova.
