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
| 2     | Pagina `home` su `/`: blocco introduttivo, categorie, come funziona | questa PR |
| 3     | Professionisti in evidenza                                          |           |
| 4     | Piè di pagina con i contatti da configurazione                      |           |
| 5     | Carousel con riquadri colorati                                      |           |
| 6     | Foto vere da archivio (`docs/photos.md`, decisione #142)            |           |

## Cosa c'è oggi

- Rotta `home` (`/`) → `HomeController`, pagina Inertia `home` (`resources/js/pages/home.tsx`), layout pubblico (`resources/js/app.tsx`).
- Una sola prop: `categories`, cioè le categorie `offered()` (almeno un servizio attivo di un provider pubblicato) con `id`, `slug` e `name` tradotto (`displayName()`), ordinate sul nome mostrato, che cambia con la lingua. Una query.
- Blocco introduttivo (`components/home/home-intro.tsx`): l'unico h1, una frase e « Trova un professionista » verso `/providers`. Isolato perché il carousel possa andare sopra o al suo posto.
- « Categorie » (h2): un `<ul>` che scorre di lato dentro una regione con nome (vedi `docs/accessibility.md`); ogni scheda porta all'elenco già filtrato, `index({ query: { category } })`. Senza categorie offerte la sezione non c'è.
- « Come funziona » (h2): un `<ol>` di tre passi, ognuno con un h3 e una frase.
- Anteprima dei link: `LinkPreview::publicPage()` senza titolo, quindi « Griot », la descrizione del sito e l'immagine predefinita; pagina indicizzabile.

## Decisioni

- Solo le categorie offerte: un link a un elenco vuoto non aiuta, e `ProviderIndexRequest` ignora una categoria non offerta e mostrerebbe tutti i provider.
- Nessun redirect per chi è loggato: un provider può vedere il sito come lo vedono i clienti.
- Il cambio lingua resta solo in testata.

## Aggiungere una sezione

1. I dati nel `HomeController`, con un numero di query fisso (eager loading) e solo campi pubblici, attraverso una Resource.
2. Una sezione `<section>` con il suo h2 (mai un altro h1; gli elementi interni h3).
3. Se la sezione può restare vuota, non montarla.
4. Testi in `lang/{it,fr,en}.json` con chiavi `home.*`.
5. Test in `tests/Feature/HomePageTest.php`: props esatte, query, struttura e testi.
