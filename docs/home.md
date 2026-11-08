# Griot: home page per i clienti

La pagina di `/` (issue #144). È per tutti: anche un provider loggato vede la home normale, con « Il mio pannello » in testata, senza essere rimandato al pannello.

## Struttura decisa (dall'alto in basso)

1. Testata: quella del layout pubblico (logo provvisorio, lingua, accesso; [brand.md](brand.md)).
2. Fascia marrone a tutta larghezza: a sinistra l'h1, una frase e la ricerca per servizio e città; a destra il carousel delle categorie con foto (sotto, sui telefoni).
3. Categorie: una fila sottile di schede con foto (o un riquadro colorato) che si scorre di lato.
4. Professionisti in evidenza: provider pubblicati con almeno una foto, i più recenti per `published_at` (passo 3).
5. Come funziona: tre passi, ognuno con un'icona in un cerchio ambra e il suo numero.
6. Fascia per i professionisti: titolo, frase e i contatti del gestore; solo se ce n'è almeno uno.
7. Piè di pagina: privacy e contatti del gestore da configurazione (passo 4).

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
| 8     | Fila delle categorie con foto                                       | fatto     |
| 9     | Rifinitura grafica della fascia                                     | fatto     |
| 10    | Animazioni e reattività di fascia, carousel e schede                | fatto     |
| 11    | « Come funziona » con icone e fascia per i professionisti           | questa PR |

## Cosa c'è oggi

- Rotta `home` (`/`) → `HomeController`, pagina Inertia `home` (`resources/js/pages/home.tsx`), layout pubblico (`resources/js/app.tsx`).
- Prop `categories`: le categorie `offered()` (almeno un servizio attivo di un provider pubblicato) con `id`, `slug` e `name` tradotto (`displayName()`), ordinate sul nome mostrato, che cambia con la lingua. Una query.
- Prop `cities`: `Provider::publishedCities()`, le città dei provider pubblicati, senza doppioni, in ordine alfabetico; la stessa lista che `ProviderIndexRequest` accetta come filtro. Una query.
- In tutto la home fa **6 query** quando ci sono professionisti in evidenza (le categorie, le città e le 4 dei professionisti in evidenza: provider, foto, servizi, categorie dei servizi) e **4** quando non ce ne sono (provider e foto soltanto, senza servizi né categorie da caricare); mai di più con più categorie, città o provider (`HomeHeroSearchTest`). La ricerca ne aggiunge una, le città; le foto della fila delle categorie nessuna (sono file del frontend).
- « Categorie » (h2): nella fila condivisa `ScrollRow`, una scheda per categoria (vedi « Fila delle categorie »). Senza categorie offerte la sezione non c'è.
- « Professionisti in evidenza » (h2), tra le categorie e « Come funziona »: prop `featured`, scelta da `App\Home\FeaturedProviders`.
    - Chi: provider pubblicati con almeno una foto nella galleria e almeno un servizio attivo (la regola di `Category::offered()`: senza, non si prenota niente).
    - Ordine: `published_at` decrescente, a parità `id` decrescente; al massimo 8.
    - Copertina: la prima foto per `position`. Il suo id lo sceglie una sottoquery correlata con `LIMIT 1` (scritta da Laravel per ogni database, senza funzioni di un database solo); poi si caricano solo quelle foto. Quattro query in tutto, qualunque sia il numero di provider. La sottoquery (scope `Provider::withCoverPhotoId()`), il caricamento (`App\Support\ProviderCovers`) e la forma (`ProviderCoverResource`) sono gli stessi della lista dei professionisti (`docs/providers-list.md`).
    - Dati (`FeaturedProviderResource`): `id`, `slug`, `name`, `city`, `categories` (come nell'elenco) e `cover` (`width`, `height`, le varianti che esistono). Niente data di pubblicazione, contatti, bio, testo alternativo.
    - Scheda (`components/home/featured-provider-card.tsx`, nuova: quella dell'elenco ha un h2 e l'avatar): un link alla pagina del provider con la copertina decorativa (`alt=""`, lenta, `srcset`, `sizes="16rem"`, 4:3), il nome come h3 con la città in sr-only (« Trecce Sahel, a Bologna »), la città visibile e le categorie.
    - Nessun provider idoneo: la sezione non c'è.
- Fila condivisa `components/home/scroll-row.tsx`: una `<section>` con il suo h2, una regione `role="region"` con `aria-labelledby` sul titolo e `tabIndex={0}`, e il `<ul>` con `snap-x` (vedi `docs/accessibility.md`). La usano le categorie e i professionisti in evidenza.
- « Come funziona » (h2), vedi la sezione « Come funziona » qui sotto.
- « Sei un professionista? » (h2), vedi la sezione « Fascia per i professionisti » qui sotto.
- Anteprima dei link: `LinkPreview::publicPage()` senza titolo, quindi « Griot », la descrizione del sito e l'immagine predefinita; pagina indicizzabile.

## Fascia in alto

`resources/js/components/home/home-hero.tsx`, con la ricerca in `home-search.tsx` e il carousel in `home-carousel.tsx`; i colori in `resources/js/lib/home-hero.ts`.

- **A tutta larghezza**: per la home il layout pubblico lascia `<main>` senza contenitore (`FULL_WIDTH_PAGES` in `public-layout.tsx`); la pagina mette la fascia da bordo a bordo e le sezioni sotto nello stesso contenitore della testata (`max-w-6xl`, `px-4`). Niente `100vw`, che con una barra di scorrimento visibile fa scorrere la pagina di lato.
- **Colori**: i token `hero` (marrone scuro) e `hero-foreground` (crema) di `resources/css/app.css`, gli stessi nei due temi come ruolo; non `primary`, che nel tema scuro è ambra con il testo scuro. Nel tema scuro la fascia è vicina al marrone della pagina: ha un bordo sottile sopra e sotto (`dark:border-y dark:border-input`). Spigoli vivi, nessun `rounded` sulla fascia e sulle foto. Altezza minima e spazio verticale in rem (`md:min-h-[32rem]`, `py-12 md:py-16 lg:py-20`), quindi crescono con lo zoom; l'altezza la decide il contenuto.
- **Colonne**: da 768 px in su due metà (a sinistra testo e ricerca, a destra il carousel); sotto, una colonna sola: prima titolo e ricerca, poi il carousel. Senza nessuna categoria con foto il carousel non c'è e la fascia ha una colonna.
- **Testi**: l'h1 di sempre (« Il savoir-faire africano, vicino a te », `home.intro.title`), `text-3xl`, `md:text-4xl` e `lg:text-5xl` da 1024 px, con `leading-tight`, `text-balance` e al massimo `max-w-2xl`, così il titolo francese, più lungo, non lascia una parola sola su una riga; poi la frase `home.intro.text` (`md:text-lg`, `max-w-xl`). Il pulsante « Trova un professionista » non c'è più: la ricerca fa lo stesso, e con i campi vuoti porta all'elenco completo.
- **Ricerca** (`docs/search.md`): un `<form role="search" method="get">` HTML verso `/providers` (l'indirizzo da Wayfinder, `index().url`), che funziona anche senza JavaScript e non ne usa.
    - « Servizio o professionista »: campo `q`, `type="search"`, al massimo 100 caratteri come `ProviderIndexRequest`. Dentro il campo una lente (`Search` di lucide, `aria-hidden`, che non intercetta i clic) e un esempio come placeholder (`home.search.placeholder`, « Treccine, barbiere, unghie… »): l'esempio non sostituisce l'etichetta, che resta visibile sopra.
    - « Città »: un `<select>` nativo `city` con « Tutte le città » (valore vuoto) e le città della prop `cities`. Nativo perché il Select di Radix ha bisogno di JavaScript e di un campo nascosto per essere inviato con un form.
    - « Cerca »: invia. Un campo vuoto arriva vuoto e l'elenco lo ignora.
    - I campi hanno lo sfondo e il testo della pagina; l'anello del focus e i pulsanti usano il crema della fascia (con un margine di 2 px dal pulsante). Contro la fascia conta il bordo dei campi (`border-input`), non il loro sfondo: nel tema scuro lo sfondo del campo è troppo vicino al marrone.
    - Misure: campi e « Cerca » alti 44 px sui telefoni e 3rem da 768 px (`SIZE` in `home-search.tsx`), testo a dimensione normale. Da 768 px stanno su una riga (il campo di testo largo almeno 14rem, la città 11rem), che va a capo quando la colonna è stretta (tra 768 e circa 1024 px); sotto 768 px una colonna, tutto a tutta larghezza.

## Carousel

`resources/js/components/home/home-carousel.tsx`, nella colonna destra della fascia; le regole pure in `resources/js/lib/home-carousel.ts`.

- **Slide**: una per ogni categoria offerta **che ha una foto** in `HOME_PHOTOS` (oggi barbiere, nails e treccine), nell'ordine della prop `categories` (nessuna query in più). Le categorie senza foto restano nella fila « Categorie ». Nessuna slide: nessun carousel. Una sola: la slide, senza Precedente, Successiva né punti.
- **Contenuto**: la foto (3:2, spigoli vivi), e **sotto** il nome della categoria (un `<p>`, non un titolo), una frase (`home.carousel.sentence.<slug>`, o `home.carousel.sentence.default`) e « Vedi i professionisti », che porta all'elenco filtrato (`/providers?category=slug`) e per i lettori di schermo dice anche la categoria. Il testo non sta mai sulla foto: è sul marrone della fascia.
- **Disposizione**: sotto la foto, a sinistra il testo della slide e a destra i controlli, sulla stessa riga quando c'è posto (il testo almeno 16rem), altrimenti i controlli vanno sotto. Per questo foto e testi sono due pile separate, ognuna con tutte le slide nella stessa cella: i controlli restano gli stessi per ogni slide e non diventano mai invisibili sotto il focus. Il gruppo della slide contiene il suo testo; la foto è decorativa.
- **Comportamento** (schema APG del carousel senza rotazione automatica): nessun autoplay né timer; il cambio è una dissolvenza incrociata di 300 ms, solo senza « riduci movimento » (altrimenti immediato). Precedente e Successiva mostrano solo la freccia (il nome è un testo sr-only da `t()`, nessun `aria-label`), 44 px sotto 768 px e 36 px sopra; circolari (`nextIndex()` e `previousIndex()` di `lib/gallery.ts`); un punto per slide, pulsante con `aria-current` e nome « Vai alla slide N »; dopo ogni cambio una regione `role="status"` dice « Slide N di M » (vuota all'apertura della pagina); il focus resta sul pulsante premuto.
- **Altezza**: le foto e i testi delle slide stanno ognuno nella stessa cella della sua griglia, quindi il carousel è alto quanto la slide più alta e non salta mai; quelle non attive sono `invisible opacity-0`, fuori dal Tab e dai lettori di schermo una volta finita la dissolvenza.
- **Foto** (`docs/photos.md`): `slideImage()` cerca lo slug in `HOME_PHOTOS` (`lib/home-photos.ts`, file in `resources/images/home/` importati con Vite).
    - Decorativa (`alt=""`), con `width` e `height` veri, `srcset` delle tre varianti e `sizes="(min-width: 1152px) 540px, (min-width: 768px) 50vw, 100vw"`: tutta la larghezza sui telefoni, metà fascia da 768 px, al massimo 540 px (metà del contenitore meno gli spazi).
    - Caricamento: la foto della prima slide subito (`loading="eager"`, `fetchpriority="high"`). Quella di un'altra slide non è nemmeno nel DOM finché la slide non viene mostrata la prima volta (`seen`), poi resta. Prima che arrivi, il suo riquadro ha già la misura e il colore `bg-muted`.
- Il titolo della scheda del browser è « Prenota un professionista vicino a te - Griot » (`home.title`); l'anteprima dei link resta quella del sito.

## Fila delle categorie

`CategoriesSection` in `resources/js/pages/home.tsx`, con la scheda in `resources/js/components/home/category-card.tsx` e le regole pure in `resources/js/lib/home-categories.ts`.

- **Schede**: una per categoria offerta, nell'ordine della prop `categories`, costruite da `categoryCards()`; nessuna query in più. Ogni scheda è **un solo link** all'elenco filtrato (`index({ query: { category: slug } })`), larga `w-36` (9rem), con angoli `rounded-md` come il resto del sito.
- **Sopra**: la foto della categoria, alta `h-24` (6rem), `object-cover`. **Sotto**: il nome, sullo sfondo della pagina, quindi con il contrasto normale del testo. Il nome è il testo del link, non un titolo; non sta mai sopra la foto, quindi nessuna sfumatura scura.
- **Foto** (`HOME_PHOTOS`, le stesse del carousel): decorativa (`alt=""`), `loading="lazy"`, `decoding="async"`, `width` e `height` veri (niente salti), `src` la variante da 640, `srcset` di tutte e `sizes="9rem"`: il browser prende la 640 anche su uno schermo a 3x.
- **Senza foto**: al posto della foto un riquadro pieno, decorativo (`aria-hidden`), con un colore dei token a rotazione tra le categorie senza foto: marrone (`bg-primary`), ambra (`bg-highlight`), verde chiaro (`bg-info`), bianco (`bg-card`); mai rosso né arancio. Ogni riquadro ha il bordo `border-input`: ambra, verde chiaro e bianco sono troppo vicini allo sfondo della pagina (sotto 3:1), il bordo no (`AccessibilityContrastTest`), e resta visibile nei colori forzati.
- **Telefoni**: la fila scorre di lato con il dito (`snap-x`); la scheda è alta più di 44 px e larga 9rem, con `touchTarget` come ogni link isolato; scorre solo la fila, mai la pagina.

## Movimento

Le regole sono in `docs/accessibility.md` (« Movimento »); il codice in `hooks/use-reveal.ts`, `lib/motion.ts` e nel blocco `prefers-reduced-motion: no-preference` di `resources/css/app.css`. Nessuna query, rotta, testo o colore cambia.

- **Fascia**: alla prima visita il titolo sale (senza sparire), la frase e la ricerca compaiono salendo, una dopo l'altra (`data-home-enter`, solo se `HomeHero` non era già stata mostrata da quando il browser ha caricato il sito). Il carousel e la sua foto non hanno ingresso. I pulsanti premuti si riducono al 98%.
- **Carousel**: dissolvenza incrociata di 300 ms; il punto corrente è largo il doppio (una trasformazione).
- **Sotto la fascia**: le schede di « Categorie » e di « Professionisti in evidenza » (in `ScrollRow`) e i passi di « Come funziona » compaiono quando si scorre fino a loro, uno dopo l'altro (`useReveal`, `revealDelay()`). Ciò che è già sullo schermo all'apertura non si nasconde mai.
- **Schede**: sotto il mouse o con il focus salgono di 2 px con un'ombra e la foto cresce dentro la cornice (`CARD_MOTION`, condivisa). La fila tiene 8 px intorno alle schede (`p-2`), così l'anello del focus non viene tagliato.
- Con « riduci movimento » niente di tutto questo esiste: la pagina è quella della PR precedente, con l'ombra sotto il mouse.

## Come funziona

In `resources/js/pages/home.tsx`, dopo i professionisti in evidenza.

- Titolo h2 « Come funziona » e sotto una barra arancio di 4 px (`bg-brand`): decorativa, senza testo e senza significato (`aria-hidden`); nei colori forzati sparisce (`forced-colors:hidden`), senza perdere niente.
- Un `<ol>` di tre schede (`STEPS`: scegli, prenota, ricevi la conferma), con i testi di sempre (`home.steps.*`). Ogni scheda:
    - un'icona di lucide (`Search`, `CalendarCheck`, `MailCheck`, in `STEP_ICONS`) in un cerchio ambra (`bg-highlight`, icona `text-highlight-foreground`, 9,2:1 e 11,8:1). Decorativa (`aria-hidden`); nei colori forzati il cerchio ha un bordo;
    - un h3 che comincia con il numero del passo in parole, « Passo 1 » (`home.steps.number`): il numero è nel testo, non solo nell'icona;
    - la frase.
- Le schede compaiono allo scorrimento (`useReveal`, una dopo l'altra), solo senza « riduci movimento ». Non sono link: niente effetto sotto il mouse (`CARD_MOTION` resta per le schede cliccabili).

## Fascia per i professionisti

`resources/js/components/home/professionals-band.tsx`, l'ultima sezione del contenitore, sopra il footer.

- Titolo h2 « Sei un professionista? », la frase « Sviluppa la tua attività: fatti trovare e ricevi prenotazioni. » e i modi per scrivere a Griot: « Scrivici un'email » e « Scrivici su WhatsApp » (nuova scheda, con l'avviso per i lettori di schermo), chiavi `home.pros.*`.
- I contatti sono quelli del gestore, dalla prop condivisa `contact` (`config/contact.php`, `App\Support\SiteContact`), come nel footer: nessun indirizzo né numero nel codice. Il messaggio già scritto di WhatsApp è suo (`home.pros.whatsapp_message`), con la stessa `whatsappLink()` del footer. Senza nessun contatto configurato la fascia non c'è, nemmeno vuota.
- Nessun invito a registrarsi: la registrazione resta chiusa ai nuovi provider (Sprint 9).
- Marrone con testo crema (`bg-hero`, `text-hero-foreground`, gli stessi token della fascia in alto); nel tema scuro un bordo sottile (`dark:border-input`). Link sottolineati, 44 px sui telefoni (`touchTarget`), anello del focus crema.
- Compare allo scorrimento (`useReveal`), solo senza « riduci movimento ». Nessuna query in più: la prop `contact` è già condivisa da tutte le pagine.

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
