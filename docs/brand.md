# Griot: logo provvisorio

Il logo del sito è **provvisorio**: un'idea di Franks, ricavata da un'immagine (« Logo GRIOT_ Talenti e Comunità.png »), in attesa del logo definitivo di un professionista. Tutto è fatto per sostituirlo in fretta: una cartella, un modulo, uno script. I colori della palette sono in [accessibility.md](accessibility.md) (« Palette calda »).

## Cosa c'è oggi

| Dove                                                                                                                  | Cosa                                                            | Misure                                         |
| --------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | ---------------------------------------------- |
| Testata pubblica e menu laterale (`components/app-logo.tsx`)                                                          | la scritta GRIOT con il segnaposto, senza motto e senza figura  | 28 px di altezza sotto 768 px, 36 px da 768 px |
| Menu laterale ridotto, pagine di accesso, pagine d'errore (`components/app-logo-icon.tsx`, `errors/layout.blade.php`) | il segnaposto su un quadrato marrone con gli angoli arrotondati | 32 o 36 px                                     |
| Scheda del browser (`public/favicon.ico`)                                                                             | l'icona quadrata                                                | 16, 32 e 48 px nello stesso file               |
| Schermata di un telefono (`public/apple-touch-icon.png`)                                                              | l'icona quadrata, opaca e senza angoli (iOS li arrotonda)       | 180 px                                         |
| App installata (`/manifest.webmanifest`, [production.md](production.md))                                              | l'icona quadrata (`any`), e piena senza angoli (`maskable`)     | 192 e 512 px                                   |
| Anteprima dei link (`public/og-default.png`, [link-previews.md](link-previews.md))                                    | sfondo bianco caldo, la scritta al centro, il filo arancio      | 1200×630                                       |

`public/favicon.svg` non c'è più: il segno nuovo esiste solo come immagine, non come disegno vettoriale. Tutti i browser leggono `favicon.ico`.

## File

- `resources/images/brand/`: `wordmark-light-{1,2,3}x.webp` (lettere marroni), `wordmark-dark-{1,2,3}x.webp` (lettere crema, per il tema scuro), `pin-{1,2,3}x.webp` (il segnaposto trasparente). Importati con Vite da `resources/js/lib/brand.ts` (`WORDMARK`, `PIN`: `src`, `srcSet` 1x, 2x, 3x, `width` e `height` della 1x), come le foto della home.
- `public/brand/`: `pin.png` (il segno delle pagine d'errore, che non passano da Vite), `icon-{16,32,48,180,192,512}.png` e `icon-maskable-{192,512}.png` (le icone dell'app installata che il telefono ritaglia a cerchio o a goccia: quadrato marrone pieno, il segnaposto al 56% del lato, dentro il cerchio di raggio 40% che ogni forma conserva). Nient'altro: `BrandAssetsTest` controlla l'elenco.
- `public/favicon.ico`, `public/apple-touch-icon.png`, `public/og-default.png`.

Pesi di oggi (`BrandAssetsTest` controlla misure e soglie):

| File                                   | Misure                  | Peso              | Soglia     |
| -------------------------------------- | ----------------------- | ----------------- | ---------- |
| `wordmark-light-1x/2x/3x.webp`         | 150×36, 299×72, 449×108 | 3,1, 7,5, 14,7 KB | 30 KB      |
| `wordmark-dark-1x/2x/3x.webp`          | 150×36, 299×72, 449×108 | 2,3, 5,9, 11,3 KB | 30 KB      |
| `pin-1x/2x/3x.webp`                    | 22×24, 44×48, 66×72     | 0,8, 1,6, 2,5 KB  | 10 KB      |
| `public/brand/pin.png`                 | 66×72                   | 6,5 KB            | 10 KB      |
| `icon-16/32/48.png`                    |                         | 0,5, 1,1, 2,0 KB  | 10 KB      |
| `icon-180.png`, `apple-touch-icon.png` | 180×180                 | 14,3, 13,4 KB     | 30 KB      |
| `icon-192.png`                         | 192×192                 | 15,9 KB           | 30 KB      |
| `icon-512.png`                         | 512×512                 | 75,5 KB           | 120 KB     |
| `icon-maskable-192/512.png`            | 192×192, 512×512        | 12,3, 61,3 KB     | 30, 120 KB |
| `favicon.ico`                          | 16, 32, 48              | 3,6 KB            | 10 KB      |
| `og-default.png`                       | 1200×630                | 85,5 KB           | 200 KB     |

## Come sono fatti

`scripts/brand-assets.php`, solo GD (già usato dall'app), nessuna dipendenza nuova:

1. Ritaglia dall'originale la scritta con il segnaposto (`WORD`, in pixel dell'originale 1536×1024).
2. Trova il segnaposto come zona arancio intorno a un punto (`PIN_SEED`), con quello che racchiude (il centro bianco e il pallino).
3. Toglie gli archi arancio che attraversano le lettere: ogni pixel arancio o giallo lontano dal segnaposto diventa bianco.
4. Trasforma il bianco in trasparenza una volta, a piena misura, senza aloni (« colour to alpha »: ogni pixel perde il bianco che contiene). Il segnaposto resta opaco con il suo centro bianco; il suo bordo perde il bianco come il resto, quindi niente filo chiaro su uno sfondo scuro.
5. Riduce alle misure, per il tema scuro cambia il marrone delle lettere e dell'anello in crema (`--hero-foreground`), compone le icone sul marrone (`--hero`) e l'anteprima sul crema (`--background`) con il filo (`--decor`).

I colori stanno in un posto solo, `app/Support/BrandColours.php` (i token di `resources/css/app.css`, tema chiaro, più il marrone del tema scuro): lo script lo include con `require_once`, senza Composer, e lo leggono anche il manifest e i `theme-color` delle pagine. `BrandAssetsTest` controlla che restino uguali ai token e che lo script non ne scriva a mano.

## Rigenerare i file

Gli originali **non entrano nella repo**: tenerli in una cartella propria, per esempio `~/griot-logo/`, con il nome esatto « Logo GRIOT_ Talenti e Comunità.png ». Il secondo file, la tavola delle idee (« Présentazione del brand GRIOT africano.png »), non serve allo script.

```bash
php scripts/brand-assets.php ~/griot-logo
```

Lo script scrive i file al loro posto e stampa misure e pesi. Un secondo argomento cambia la cartella di destinazione (lo usano i test, con un'immagine di prova).

## Sostituire il logo con quello definitivo

- **Se arriva un'immagine simile** (scritta e segnaposto su fondo bianco): cambiare `LOGO`, `WORD` e `PIN_SEED` nello script e rilanciarlo. Nomi, misure e codice restano uguali.
- **Se arriva un SVG**: è la strada migliore. Si può tornare a un `favicon.svg` e disegnare il segno in SVG (come la vecchia « G »), lasciando a `brand.ts` lo stesso ruolo. Le misure in pagina (`h-7 md:h-9`) e i nomi accessibili non cambiano.
- In entrambi i casi aggiornare questa pagina e le misure dichiarate in `lib/brand.ts` (lo controlla `BrandAssetsTest`).

## Cosa dipende dal logo provvisorio

Il logo verrà rifatto. La palette C e la tipografia (rinnovo visivo, PR 1) ne dipendono così:

- **Dipendono dal logo, da rivedere quando cambia:**
    - i colori presi dal logo: il marrone `--hero` (`oklch(0.25 0.07 40)`, il marrone esatto delle lettere), che è anche il colore del testo; l'arancio di `--brand` e `--decor` (vicino a quello del logo, `#f14e05`, ma spostato a `oklch(0.65 0.19 48)` per stare a 20° di tinta dal rosso degli errori); l'ambra di `--highlight`. Se i colori del logo nuovo restano « più o meno quelli », vanno rimisurati e i contrasti ricalcolati (`AccessibilityContrastTest`);
    - le costanti `BROWN`, `BROWN_DARK`, `CREAM`, `BACKGROUND` e `DECOR` di `app/Support/BrandColours.php`, uguali ai token (`BrandAssetsTest`), che danno anche i colori del manifest e dei `theme-color`;
    - lo script stesso, scritto per **questa** immagine (`WORD`, `PIN_SEED`, gli archi arancio tolti dalle lettere): con un logo diverso va in gran parte riscritto;
    - tutte le immagini generate (scritta, segnaposto, icone, icone `maskable` dell'app, favicon, `apple-touch-icon`, `og-default.png`) e i test che ne leggono i pixel (`BrandAssetsTest`, `VisualIdentityTest`, `LinkPreviewTest`).
- **Non dipendono dal logo:** il turchese delle azioni, il bianco caldo, la sabbia dei badge, gli stati, i caratteri (Atkinson Hyperlegible Next e Fraunces sono scelti per la leggibilità, non per somigliare alle lettere del logo).
- **Il motivo neutro dei riquadri vuoti** (rinnovo visivo, PR 2): le categorie senza foto e le schede dei professionisti senza foto del lavoro mostrano un fondo sabbia con l'icona `Sparkles` di lucide (`components/empty-picture.tsx`), **mai il segnaposto del logo** (`pin-*.webp`): con il logo nuovo non c'è niente da rifare lì. Restano legati al logo solo l'arancio della barretta sotto i titoli e del bordo della scheda per i professionisti (`--brand`), da rimisurare come gli altri colori.

## Accessibilità

- **Nome accessibile**: le due immagini della scritta hanno come testo alternativo il nome dell'app (`usePage().props.name`, lo stesso di sempre); il link della testata prende quel nome. Il segno quadrato è decorativo (`aria-hidden`, `alt=""`): accanto c'è sempre il nome, scritto o nascosto per i lettori di schermo.
- **Tema chiaro e scuro**: due `<img>`, una con `dark:hidden` e l'altra con `hidden dark:not-forced-colors:block`. Non `<picture>` con `prefers-color-scheme`: il sito ha il suo interruttore dell'aspetto (classe `.dark`). Un'immagine con `display: none` non viene nemmeno scaricata.
- **Colori forzati**: il sistema non ricolora le immagini, e una scritta marrone su fondo nero o crema su fondo bianco sparirebbe. Le immagini si nascondono (`forced-colors:hidden`) e compare il nome come testo (`forced-colors:inline`), nei colori del sistema. Il quadrato del segno ha un bordo (`forced-colors:border`), perché il suo sfondo sparisce.
- **Larghezza**: a 28 px di altezza la scritta è larga circa 117 px; su uno schermo di 320 px lingua e « Accedi » vanno uno sotto l'altro quando non c'è posto, mai fuori dallo schermo.
