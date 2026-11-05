# Griot: home page per i clienti

La pagina di `/` (issue #144). È per tutti: anche un provider loggato vede la home normale, con « Il mio pannello » in testata, senza essere rimandato al pannello.

## Struttura decisa (dall'alto in basso)

1. Testata: quella del layout pubblico (logo, lingua, accesso).
2. Carousel delle categorie, senza movimento automatico, con Precedente/Successiva e punti (passo 5).
3. Categorie: una fila di schede che si scorre di lato.
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
| 5     | Carousel con riquadri colorati                                      | questa PR |
| 6     | Foto vere da archivio (`docs/photos.md`, decisione #142)            |           |

## Cosa c'è oggi

- Rotta `home` (`/`) → `HomeController`, pagina Inertia `home` (`resources/js/pages/home.tsx`), layout pubblico (`resources/js/app.tsx`).
- Prop `categories`: le categorie `offered()` (almeno un servizio attivo di un provider pubblicato) con `id`, `slug` e `name` tradotto (`displayName()`), ordinate sul nome mostrato, che cambia con la lingua. Una query.
- Blocco introduttivo (`components/home/home-intro.tsx`): l'unico h1, una frase e « Trova un professionista » verso `/providers`. Isolato perché il carousel possa andare sopra o al suo posto.
- « Categorie » (h2): nella fila condivisa `ScrollRow`; ogni scheda porta all'elenco già filtrato, `index({ query: { category } })`. Senza categorie offerte la sezione non c'è.
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

## Carousel

`resources/js/components/home/home-carousel.tsx`, subito sotto il blocco introduttivo e prima di « Categorie »; le regole pure in `resources/js/lib/home-carousel.ts`.

- **Slide**: una per ogni categoria offerta, con gli stessi dati della prop `categories` (nessuna query in più). Nessuna categoria: nessun carousel. Una sola: la slide, senza Precedente, Successiva né punti.
- **Contenuto**: il nome della categoria in grande (un `<p>`, non un titolo), una frase (`home.carousel.sentence.<slug>`, o `home.carousel.sentence.default` per una categoria che non ne ha una: una categoria nuova non rompe niente) e « Vedi i professionisti », che porta all'elenco filtrato e per i lettori di schermo dice anche la categoria.
- **Colori**, a rotazione e solo dai token: verde (`primary`, con il pulsante chiaro `secondary` e l'anello del focus del colore del testo del riquadro), giallo (`highlight`), azzurro (`info`), bianco (`card`, con il bordo dei campi). Mai il rosso. Ogni coppia è misurata in `AccessibilityContrastTest`, che legge le classi dal componente.
- **Comportamento** (schema APG del carousel senza rotazione automatica): nessun autoplay né timer; il cambio è immediato, senza effetti. Precedente e Successiva circolari (`nextIndex()` e `previousIndex()` di `lib/gallery.ts`); un punto per slide, pulsante con `aria-current` e nome « Vai alla slide N »; dopo ogni cambio una regione `role="status"` dice « Slide N di M » (vuota all'apertura della pagina); il focus resta sul pulsante premuto.
- **Altezza**: tutte le slide stanno nella stessa cella di una griglia, quindi il carousel è alto quanto la più alta e non salta mai; quelle non attive sono `invisible`, fuori dal Tab e dai lettori di schermo.
- **Foto (passo 6)**: ogni slide ha già `image` (oggi `null`): una foto decorativa (`alt=""`) dietro il testo, con `src`, `srcSet`, `width` e `height`, senza cambiare il componente. Prima di metterle va ricontrollato il contrasto del testo sopra la foto.
- Il titolo della scheda del browser è « Prenota un professionista vicino a te - Griot » (`home.title`); l'anteprima dei link resta quella del sito.

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
