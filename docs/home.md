# Griot: home page per i clienti

La pagina di `/` (issue #144). È per tutti: anche un provider loggato vede la home normale, con « Il mio pannello » in testata, senza essere rimandato al pannello.

## Struttura decisa (dall'alto in basso)

1. Testata: quella del layout pubblico (logo, lingua, accesso).
2. Fascia verde a tutta larghezza: a sinistra l'h1, una frase e la ricerca per servizio e città; a destra il carousel delle categorie con foto (sotto, sui telefoni).
3. Categorie: una fila sottile di schede con foto (o un riquadro colorato) che si scorre di lato.
4. Professionisti in evidenza: provider pubblicati con almeno una foto, i più recenti per `published_at` (passo 3).
5. Come funziona: tre passi.
6. Piè di pagina: privacy e contatti del gestore da configurazione (passo 4).

## Piano dei passi

| Passo | Contenuto                                                           | Stato     |
| ----- | ------------------------------------------------------------------- | --------- |
| 1     | `providers.published_at` per ordinare i provider per pubblicazione  | fatto     |
| 2     | Pagina `home` su `/`: blocco introduttivo, categorie, come funziona | fatto     |
| 3     | Professionisti in evidenza                                          | fatto     |
| 4     | Piè di pagina con i contatti da configurazione                      | fatto     |
| 5     | Carousel con riquadri colorati                                      | fatto     |
| 6     | Foto vere da archivio (`docs/photos.md`, decisione #142)            | fatto     |
| 7     | Fascia con ricerca e carousel ridisegnato                           | fatto     |
| 8     | Fila delle categorie con foto                                       | questa PR |

## Cosa c'è oggi

- Rotta `home` (`/`) → `HomeController`, pagina Inertia `home` (`resources/js/pages/home.tsx`), layout pubblico (`resources/js/app.tsx`).
- Prop `categories`: le categorie `offered()` (almeno un servizio attivo di un provider pubblicato) con `id`, `slug` e `name` tradotto (`displayName()`), ordinate sul nome mostrato, che cambia con la lingua. Una query.
- Prop `cities`: `Provider::publishedCities()`, le città dei provider pubblicati, senza doppioni, in ordine alfabetico; la stessa lista che `ProviderIndexRequest` accetta come filtro. Una query.
- In tutto la home fa **6 query** quando ci sono professionisti in evidenza (le categorie, le città e le 4 dei professionisti in evidenza: provider, foto, servizi, categorie dei servizi) e **4** quando non ce ne sono (provider e foto soltanto, senza servizi né categorie da caricare); mai di più con più categorie, città o provider (`HomeHeroSearchTest`). La ricerca ne aggiunge una, le città; le foto della fila delle categorie nessuna (sono file del frontend).
- « Categorie » (h2): nella fila condivisa `ScrollRow`, una scheda per categoria (vedi « Fila delle categorie »). Senza categorie offerte la sezione non c'è.
- « Professionisti in evidenza » (h2), tra le categorie e « Come funziona »: prop `featured`, scelta da `App\Home\FeaturedProviders`.
    - Chi: provider pubblicati con almeno una foto nella galleria e almeno un servizio attivo (la regola di `Category::offered()`: senza, non si prenota niente).
    - Ordine: `published_at` decrescente, a parità `id` decrescente; al massimo 8.
    - Copertina: la prima foto per `position`. Il suo id lo sceglie una sottoquery correlata con `LIMIT 1` (scritta da Laravel per ogni database, senza funzioni di un database solo); poi si caricano solo quelle foto. Quattro query in tutto, qualunque sia il numero di provider.
    - Dati (`FeaturedProviderResource`): `id`, `slug`, `name`, `city`, `categories` (come nell'elenco) e `cover` (`width`, `height`, le varianti che esistono). Niente data di pubblicazione, contatti, bio, testo alternativo.
    - Scheda (`components/home/featured-provider-card.tsx`, nuova: quella dell'elenco ha un h2 e l'avatar): un link alla pagina del provider con la copertina decorativa (`alt=""`, lenta, `srcset`, `sizes="16rem"`, 4:3), il nome come h3 con la città in sr-only (« Trecce Sahel, a Bologna »), la città visibile e le categorie.
    - Nessun provider idoneo: la sezione non c'è.
- Fila condivisa `components/home/scroll-row.tsx`: una `<section>` con il suo h2, una regione `role="region"` con `aria-labelledby` sul titolo e `tabIndex={0}`, e il `<ul>` con `snap-x` (vedi `docs/accessibility.md`). La usano le categorie e i professionisti in evidenza.
- « Come funziona » (h2): un `<ol>` di tre passi, ognuno con un h3 e una frase.
- Anteprima dei link: `LinkPreview::publicPage()` senza titolo, quindi « Griot », la descrizione del sito e l'immagine predefinita; pagina indicizzabile.

## Fascia in alto

`resources/js/components/home/home-hero.tsx`, con la ricerca in `home-search.tsx` e il carousel in `home-carousel.tsx`; i colori in `resources/js/lib/home-hero.ts`.

- **A tutta larghezza**: per la home il layout pubblico lascia `<main>` senza contenitore (`FULL_WIDTH_PAGES` in `public-layout.tsx`); la pagina mette la fascia da bordo a bordo e le sezioni sotto nello stesso contenitore della testata (`max-w-6xl`, `px-4`). Niente `100vw`, che con una barra di scorrimento visibile fa scorrere la pagina di lato.
- **Colori**: i token `hero` (verde scuro) e `hero-foreground` (bianco) di `resources/css/app.css`, gli stessi nei due temi come ruolo; non `primary`, che nel tema scuro è un verde chiaro con il testo scuro. Spigoli vivi, nessun `rounded` sulla fascia e sulle foto. Altezza minima in rem (`md:min-h-[28rem]`), quindi cresce con lo zoom; la decide il contenuto.
- **Colonne**: da 768 px in su due metà (a sinistra testo e ricerca, a destra il carousel); sotto, una colonna sola: prima titolo e ricerca, poi il carousel. Senza nessuna categoria con foto il carousel non c'è e la fascia ha una colonna.
- **Testi**: l'h1 di sempre (« Il savoir-faire africano, vicino a te », `home.intro.title`) e la frase `home.intro.text`. Il pulsante « Trova un professionista » non c'è più: la ricerca fa lo stesso, e con i campi vuoti porta all'elenco completo.
- **Ricerca** (`docs/search.md`): un `<form role="search" method="get">` HTML verso `/providers` (l'indirizzo da Wayfinder, `index().url`), che funziona anche senza JavaScript e non ne usa.
    - « Servizio o professionista »: campo `q`, `type="search"`, al massimo 100 caratteri come `ProviderIndexRequest`.
    - « Città »: un `<select>` nativo `city` con « Tutte le città » (valore vuoto) e le città della prop `cities`. Nativo perché il Select di Radix ha bisogno di JavaScript e di un campo nascosto per essere inviato con un form.
    - « Cerca »: invia. Un campo vuoto arriva vuoto e l'elenco lo ignora.
    - I campi hanno lo sfondo e il testo della pagina; l'anello del focus e i pulsanti usano il bianco della fascia (con un margine di 2 px dal pulsante).

## Carousel

`resources/js/components/home/home-carousel.tsx`, nella colonna destra della fascia; le regole pure in `resources/js/lib/home-carousel.ts`.

- **Slide**: una per ogni categoria offerta **che ha una foto** in `HOME_PHOTOS` (oggi barbiere, nails e treccine), nell'ordine della prop `categories` (nessuna query in più). Le categorie senza foto restano nella fila « Categorie ». Nessuna slide: nessun carousel. Una sola: la slide, senza Precedente, Successiva né punti.
- **Contenuto**: la foto (3:2, spigoli vivi), e **sotto** il nome della categoria (un `<p>`, non un titolo), una frase (`home.carousel.sentence.<slug>`, o `home.carousel.sentence.default`) e « Vedi i professionisti », che porta all'elenco filtrato (`/providers?category=slug`) e per i lettori di schermo dice anche la categoria. Il testo non sta mai sulla foto: è sul verde della fascia.
- **Comportamento** (schema APG del carousel senza rotazione automatica): nessun autoplay né timer; il cambio è immediato, senza effetti. Precedente e Successiva circolari (`nextIndex()` e `previousIndex()` di `lib/gallery.ts`); un punto per slide, pulsante con `aria-current` e nome « Vai alla slide N »; dopo ogni cambio una regione `role="status"` dice « Slide N di M » (vuota all'apertura della pagina); il focus resta sul pulsante premuto.
- **Altezza**: tutte le slide stanno nella stessa cella di una griglia, quindi il carousel è alto quanto la più alta e non salta mai; quelle non attive sono `invisible`, fuori dal Tab e dai lettori di schermo.
- **Foto** (`docs/photos.md`): `slideImage()` cerca lo slug in `HOME_PHOTOS` (`lib/home-photos.ts`, file in `resources/images/home/` importati con Vite).
    - Decorativa (`alt=""`), con `width` e `height` veri, `srcset` delle tre varianti e `sizes="(min-width: 1152px) 540px, (min-width: 768px) 50vw, 100vw"`: tutta la larghezza sui telefoni, metà fascia da 768 px, al massimo 540 px (metà del contenitore meno gli spazi).
    - Caricamento: la foto della prima slide subito (`loading="eager"`, `fetchpriority="high"`). Quella di un'altra slide non è nemmeno nel DOM finché la slide non viene mostrata la prima volta (`seen`), poi resta. Prima che arrivi, il suo riquadro ha già la misura e il colore `bg-muted`.
- Il titolo della scheda del browser è « Prenota un professionista vicino a te - Griot » (`home.title`); l'anteprima dei link resta quella del sito.

## Fila delle categorie

`CategoriesSection` in `resources/js/pages/home.tsx`, con la scheda in `resources/js/components/home/category-card.tsx` e le regole pure in `resources/js/lib/home-categories.ts`.

- **Schede**: una per categoria offerta, nell'ordine della prop `categories`, costruite da `categoryCards()`; nessuna query in più. Ogni scheda è **un solo link** all'elenco filtrato (`index({ query: { category: slug } })`), larga `w-36` (9rem), con angoli `rounded-md` come il resto del sito.
- **Sopra**: la foto della categoria, alta `h-24` (6rem), `object-cover`. **Sotto**: il nome, sullo sfondo della pagina, quindi con il contrasto normale del testo. Il nome è il testo del link, non un titolo; non sta mai sopra la foto, quindi nessuna sfumatura scura.
- **Foto** (`HOME_PHOTOS`, le stesse del carousel): decorativa (`alt=""`), `loading="lazy"`, `decoding="async"`, `width` e `height` veri (niente salti), `src` la variante da 640, `srcset` di tutte e `sizes="9rem"`: il browser prende la 640 anche su uno schermo a 3x.
- **Senza foto**: al posto della foto un riquadro pieno, decorativo (`aria-hidden`), con un colore dei token a rotazione tra le categorie senza foto: verde (`bg-primary`), giallo (`bg-highlight`), azzurro (`bg-info`), bianco (`bg-card`); mai rosso. Ogni riquadro ha il bordo `border-input`: giallo, azzurro e bianco sono troppo vicini allo sfondo della pagina (sotto 3:1), il bordo no (`AccessibilityContrastTest`), e resta visibile nei colori forzati.
- **Telefoni**: la fila scorre di lato con il dito (`snap-x`); la scheda è alta più di 44 px e larga 9rem, con `touchTarget` come ogni link isolato; scorre solo la fila, mai la pagina.

## Piè di pagina e contatti

Il footer è quello del layout pubblico (`resources/js/layouts/public-layout.tsx`), quindi vale per tutte le pagine pubbliche: home, elenco, pagina di un provider, privacy, pagina di una prenotazione.

- Il link alla privacy c'è sempre.
- I contatti del gestore vengono da `config/contact.php`, che legge solo l'ambiente: `CONTACT_EMAIL` e `CONTACT_WHATSAPP` (formato internazionale con `+` o `00`, per esempio `+39…`). Non sono il contatto della privacy (`PRIVACY_CONTACT_EMAIL`), che serve alle richieste sui dati personali.
- `App\Support\SiteContact` li pulisce: un'email che non passa `filter_var` o un numero senza `+` o `00` diventano `null`, senza errore. Arrivano al frontend nella prop condivisa `contact` (`email`, `whatsapp_url`), una closure in `HandleInertiaRequests`.
- Il link WhatsApp usa la stessa regola dei provider, scritta una volta in `App\Support\WhatsApp::url()` (anche `Provider::whatsappUrl()` la usa), e un messaggio già scritto e generico (`footer.contact.whatsapp_message`, « Ciao, ho una domanda su Griot. ») aggiunto con `whatsappLink()`.
- Nel footer: « Scrivici » (`mailto:`) e « WhatsApp » (nuova scheda, con l'avviso per i lettori di schermo), ognuno solo se configurato, in un gruppo con nome sr-only (« Contatti di Griot »). Nessun titolo: il footer è in tutte le pagine e non deve cambiare l'ordine dei loro titoli.
- Senza configurazione resta solo la privacy, come prima, e nessun gruppo vuoto.
- **In produzione** le due variabili vanno impostate nel servizio di hosting (sul server di Render, come le altre variabili): senza, il footer non mostra nessun contatto. Nessun contatto vero va scritto nella repo, nemmeno in `.env.example`.

## Decisioni

- Solo le categorie offerte: un link a un elenco vuoto non aiuta, e `ProviderIndexRequest` ignora una categoria non offerta e mostrerebbe tutti i provider.
- Nessun redirect per chi è loggato: un provider può vedere il sito come lo vedono i clienti.
- Il cambio lingua resta solo in testata.

## Aggiungere una sezione

1. I dati nel `HomeController`, con un numero di query fisso (eager loading) e solo campi pubblici, attraverso una Resource.
2. Una sezione `<section>` con il suo h2 (mai un altro h1; gli elementi interni h3). Per una fila che scorre di lato, `ScrollRow` con `titleId`, `title`, `items` e `renderItem`.
3. Se la sezione può restare vuota, non montarla.
4. Testi in `lang/{it,fr,en}.json` con chiavi `home.*`.
5. Test in `tests/Feature/HomePageTest.php`: props esatte, query, struttura e testi.
