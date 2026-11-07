# Griot: accessibilità

Risultati del lavoro sull'issue #55: cosa è stato cambiato, come è protetto dai test, cosa è stato misurato e cosa no.

## 1. Obiettivo e perimetro

**Obiettivo di lavoro**: WCAG 2.2 livello AA. È il riferimento usato per decidere e misurare, non una certificazione: nessun audit esterno, nessuna dichiarazione di conformità.

**Dentro**

- Sito pubblico: elenco dei provider con i filtri, pagina del provider, privacy.
- Prenotazione: il modulo nel pannello laterale (Sheet) con opzioni, giorno, orari e dati del cliente.
- Area provider: pannello, profilo, servizi, opzioni, disponibilità, lista prenotazioni, nuova prenotazione.
- Accesso e impostazioni: login, registrazione, password dimenticata, impostazioni (profilo, sicurezza, aspetto).
- Pagine di errore (403, 404, 419, 429, 500, 503 e una generale per gli altri codici): autosufficienti, senza Vite, database, sessione né JavaScript, con i colori della palette e il carattere di sistema.
- Tema chiaro e scuro, italiano, francese e inglese.

**Fuori**

- Le email (`resources/views/mail`).
- I contenuti scritti dai provider (bio, nomi e descrizioni dei servizi): restano nella lingua in cui li scrivono e senza attributo `lang` proprio.
- I componenti del kit che l'app non usa (`ui/toggle`, `ui/navigation-menu`, `ui/skeleton`).

## 2. Cosa è stato fatto, per PR

| PR   | Contenuto                                                                                                                                                                                                                                                   |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| #124 | Contrasto: bordi dei campi, anelli del focus e messaggi di stato verdi portati alle soglie (3:1 per bordi e anelli, 4.5:1 per il testo), in chiaro e in scuro.                                                                                              |
| #125 | Errori dei moduli di prenotazione e di accesso: ogni errore collegato al suo campo con `aria-describedby`, focus sul primo campo sbagliato, errori senza campo annunciati con `role="alert"`, orari liberi annunciati.                                      |
| #126 | Tastiera: link "Salta al contenuto", ordine del Tab uguale all'ordine della pagina, mostra password raggiungibile col Tab, selettore della lingua Radix (le frecce non cambiano pagina), focus al titolo dopo un'azione nella lista prenotazioni.           |
| #127 | Nomi accessibili: "Prenota" con il nome del servizio, avviso "si apre in una nuova scheda", avatar decorativi, `<html lang>` aggiornato al cambio lingua, messaggi di stato in `role="status"`, nome tradotto della regione delle notifiche.                |
| #128 | Bersagli di tocco di almeno 44×44 px sotto 768 px, e movimento ridotto con `prefers-reduced-motion`.                                                                                                                                                        |
| #129 | Correzioni dalla verifica con axe: contrasto dell'avviso "Elimina account" e dei tab dell'aspetto, menu laterale come `nav` con nome, titolo nascosto del menu dentro il suo pannello, nome del logo nelle pagine di accesso, "Orari" e "Contatti" come h2. |
| #134 | Colori forzati e spaziatura del testo: contorno col focus su pulsanti, campi, caselle e Select; bordo dei pulsanti pieni; `aria-pressed` e bordo sul tab dell'aspetto scelto; logo dell'header mai tagliato; filtri che crescono col testo.                 |

## 3. Decisioni di progetto

- **Bersagli di tocco**: almeno 44×44 px solo sotto 768 px (`max-md:`), il punto in cui il menu laterale diventa un pannello a scomparsa. Da 768 px in su l'aspetto non cambia.
    - Pulsanti, campi e Select hanno `max-md:min-h-11`.
    - I link isolati usano la costante `touchTarget` (`resources/js/lib/touch-target.ts`).
    - La X dei pannelli, le caselle e i breadcrumb hanno un'area invisibile (`after:`) attorno all'elemento.
- **Regioni che scorrono di lato** (#144, la fila delle categorie della home): un contenitore `role="region"` con `aria-labelledby` sul titolo visibile della sezione (mai un `aria-label` scritto a mano) e `tabIndex={0}`, così con il focus le frecce lo fanno scorrere; dentro un `<ul>` con `overflow-x-auto` e `snap-x`, e ogni link resta nel Tab, in ordine. Scorre solo la fila, mai la pagina intera; niente `scroll-smooth`; focus visibile anche sul contenitore.
- **Carousel** (#144, la fascia della home): schema APG senza rotazione automatica. Nessun timer (`setInterval`, `setTimeout`, `requestAnimationFrame`) e nessun effetto di scambio; una `<section>` con `aria-roledescription` e nome da `t()`, ogni slide `role="group"` con il suo `aria-roledescription` e `aria-labelledby` sul nome della categoria; nessun titolo nelle slide. Le slide non attive restano nella stessa cella della griglia ma `invisible`: fuori dal Tab e dai lettori di schermo, senza salti di altezza. Precedente e Successiva circolari, mai disattivati; punti come pulsanti con `aria-current` e un nome sr-only (« Vai alla slide N »), 44 px sotto 768 px; il cambio annunciato in `role="status"`. Le foto delle slide sono decorative (`alt=""`) e il testo non sta mai sopra una foto: sta sotto, sul verde della fascia.
- **Fascia verde della home** (`docs/home.md`): token `hero` e `hero-foreground`, misurati in `AccessibilityContrastTest` nei due temi (testo, bordo dei campi e pulsanti contro il verde, anello del focus). Sul verde l'anello del focus è bianco (`focus-visible:ring-hero-foreground`), con un margine di 2 px dai pulsanti chiari. La ricerca è un form HTML con etichette visibili e un `<select>` nativo per la città.
- **Ricerca nella lista dei professionisti** (`docs/search.md`): un `<form role="search">` con etichetta visibile e pulsante « Cerca », Invio dal campo; il numero dei risultati, o lo stato vuoto, in un `role="status"` sempre presente, aggiornato senza smontare la pagina (`preserveState`).
- **Link dentro una frase**: esclusi dai bersagli di tocco (lo standard li esenta). La costante `touchTarget` si usa solo per i link da soli.
- **Movimento ridotto**: una regola globale in `resources/css/app.css` porta animazioni e transizioni a 0.01 ms e toglie lo scroll morbido.
    - 0.01 ms e non `none`: Radix aspetta la fine dell'animazione per chiudere un pannello.
    - Lo spinner di caricamento continua a girare, più lento (1.5 s): fermo sembrerebbe una pagina bloccata.
- **Nomi e testi**: ogni testo, `aria-label` compresi, passa da `t()` con le chiavi in `lang/{it,fr,en}.json`. Nessun `aria-label` scritto a mano nei componenti.
- **Focus visibile**: ogni `outline-none` è accompagnato da `focus-visible:outline-hidden`.
    - Con i colori normali non cambia niente.
    - Nei colori forzati, dove il browser toglie le ombre (e quindi gli anelli di Tailwind), compare un contorno del colore di sistema.
    - Eccezioni, controllate da `ForcedColorsAndSpacingTest`:
        - i due `<main>` dei layout: ricevono il focus solo dal link "Salta al contenuto", mai dal Tab;
        - `ui/toggle` e `ui/navigation-menu`: componenti del kit che l'app non usa;
        - la X di Sheet e Dialog: ha già `focus:outline-hidden`.
    - Restano fuori dalla regola le voci della Select aperta e del menu utente: hanno `outline-hidden` e la voce attiva è indicata dallo sfondo, che nei colori forzati non si vede (debito nella #112, sezione 6).
- **Contrasto**: i colori vengono dai token di `app.css` e dalla palette di Tailwind. Il test li calcola da lì (oklch convertito in sRGB), non da valori copiati.
- **Palette (Sprint 8)**: verde scuro per azioni, link e nome del sito; superfici bianche e azzurre; tre token in più.
    - `highlight` (giallo): solo come sfondo, sempre con il suo testo scuro `highlight-foreground`. Mai testo giallo su bianco.
    - `info` (azzurro): sfondi per le informazioni, con `info-foreground` e `info-border`.
    - `decor` (rosso): solo un filo di 2 px sotto l'header pubblico e sotto il nome del sito. Non ha significato: mai nei badge, accanto ai campi o nei messaggi.
    - Errori, stati, `destructive` e l'avviso "Elimina account" hanno gli stessi colori di prima (`red-600`/`red-400`, `green-700`/`green-600`).
- **Carattere**: Noto Sans variabile, incluso nel progetto (`@fontsource-variable/noto-sans`), nessun servizio esterno né durante la build né nel browser. Una pagina scarica solo il sottoinsieme latino; ɛ ɔ ŋ ṣ (latin-ext) e ẹ ọ (vietnamese) arrivano solo se un nome li contiene.
- **Scheda « Foto del tuo lavoro »** (#143, `docs/gallery.md`): elenco come `<ul>`, « Togli » con il numero della foto in testo `sr-only`, conferma nel Dialog del kit e focus al titolo della scheda dopo la rimozione, attesa del caricamento in `role="status"`, errori collegati ai campi e focus sul primo sbagliato (è nell'elenco di `AccessibleErrorsTest`).
- **Mosaico e ingrandimento delle foto del lavoro** (#143, `docs/gallery.md`): mosaico prima dell'h1, quindi senza titoli, in un gruppo `role="group"` con nome sr-only da `t()`; ogni foto è un `<button>` col testo alternativo più « (apri la foto N di M ingrandita) ». L'ingrandimento è il Dialog del kit: titolo « Foto N di M », precedente e successiva circolari, frecce della tastiera, cambio annunciato in `role="status"`, focus restituito a chi l'ha aperto.
- **Errori dei moduli**: un solo annuncio per errore. Gli errori di un campo si leggono col focus e `aria-describedby`; solo quelli senza campo usano `role="alert"`.

## 4. Test automatici

| File                                           | Cosa controlla                                                                                                                                                                                                                                                                                                      |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Feature/AccessibilityContrastTest.php`  | Rapporti di contrasto calcolati dai token, in chiaro e in scuro: testo, campi, anelli del focus (anche su giallo e azzurro), messaggi verdi, avviso rosso, tab dell'aspetto, link e nome del sito, schede, giallo, azzurro, filo rosso, menu laterale. I vecchi valori devono restare sotto soglia.                 |
| `tests/Feature/AccessibleErrorsTest.php`       | Errori collegati ai campi, focus sul primo campo sbagliato, annunci di orari ed errori senza campo, e le stesse risposte del server per i moduli di prenotazione.                                                                                                                                                   |
| `tests/Feature/KeyboardNavigationTest.php`     | Nessun `tabIndex` positivo, ordine del login, mostra password raggiungibile, link "Salta al contenuto", selettore della lingua, focus dopo un'azione nella lista prenotazioni, toast in una regione `polite`.                                                                                                       |
| `tests/Feature/AccessibleNamesTest.php`        | Link in nuova scheda con avviso, "Prenota" col nome del servizio, avatar, ordine dei titoli, messaggi di stato, `<html lang>`, menu laterale come `nav`, nome del logo, "Orari" e "Contatti" come h2.                                                                                                               |
| `tests/Feature/TouchAndMotionTest.php`         | Regola del movimento ridotto ed eccezione dello spinner, nessuno scroll morbido, 44 px sotto 768 px nei componenti e nei link isolati, aree invisibili.                                                                                                                                                             |
| `tests/Feature/ForcedColorsAndSpacingTest.php` | `focus-visible:outline-hidden` accanto a ogni `outline-none`, bordo dei pulsanti nei colori forzati, `aria-pressed` sui tab dell'aspetto, logo dell'header, larghezza dei filtri.                                                                                                                                   |
| `tests/Feature/VisualIdentityTest.php`         | Carattere incluso nel progetto (niente Bunny), sottoinsiemi con ɛ ɔ ŋ ẹ ọ ṣ, colori di errori e stati invariati, filo rosso solo nell'header e nel nome, nome del sito come testo, segno e favicon di Griot.                                                                                                        |
| `tests/Feature/ErrorPagesTest.php`             | Ogni pagina d'errore nelle tre lingue: stato, titolo, messaggio, link (con `target="_top"` per la finestra di Inertia), un h1, `<main>`, `lang`; nessun dettaglio dell'eccezione; nessuna dipendenza da Vite, sessione o JavaScript; colori uguali ai token e contrasti; 44 px, contorno del focus, colori forzati. |

**Limite**: non c'è un runner JavaScript. Quasi tutti questi test leggono i sorgenti: dicono che una classe o un attributo c'è, non che il risultato sullo schermo misura 44 px o che un lettore di schermo lo annuncia. Le misure vere sono quelle della sezione 5.

**Come eseguirli**

```bash
composer ci:check
```

Solo questi file:

```bash
php artisan test --compact tests/Feature/AccessibilityContrastTest.php tests/Feature/AccessibleErrorsTest.php tests/Feature/KeyboardNavigationTest.php tests/Feature/AccessibleNamesTest.php tests/Feature/TouchAndMotionTest.php tests/Feature/ForcedColorsAndSpacingTest.php tests/Feature/VisualIdentityTest.php tests/Feature/ErrorPagesTest.php
```

Prima di lanciarli, `composer dev` deve essere spento: `public/hot` non deve esistere.

## 5. Verifiche eseguite

Data: novembre 2026, sul codice delle PR #128, #129 e #134. Strumenti: Playwright 1.64 con Chromium, axe-core 4.14, installati in una cartella temporanea fuori dalla repo. Server locale con i dati del seeder; solo navigazione e login dell'utente seed, nessun altro invio di moduli.

| Verifica                       | Come                                                                                                                                                                                                                    | Risultato                                                                                                                                              |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| axe-core                       | Tag `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`, `best-practice`. 228 combinazioni: 19 pagine (le pagine della sezione 1 più il modulo di prenotazione aperto), tema chiaro e scuro, it/fr/en, 375 e 1280 px | Prima: `color-contrast` (24 nodi) e `region` (360 nodi). Dopo #129 e #134: **0 violazioni**. Resta un `aria-hidden-focus` "da controllare" (sezione 6) |
| Albero di accessibilità        | `ariaSnapshot` di pagina del provider, login, modulo di prenotazione, pannello a 375 px con menu chiuso e aperto                                                                                                        | Nomi dei controlli e ordine dei titoli corretti dopo #129                                                                                              |
| Riflusso                       | 320×256 e 320×640, it/fr/en, tutte le pagine                                                                                                                                                                            | Nessuno scorrimento orizzontale; dopo #134 nessun testo tagliato                                                                                       |
| Zoom 200%                      | 640×400 con densità 2                                                                                                                                                                                                   | Nessun problema                                                                                                                                        |
| Spaziatura del testo           | Regole del bookmarklet "Text Spacing" (interlinea 1.5, lettere 0.12em, parole 0.16em, paragrafi 2em), 375 e 1280 px, it/fr/en                                                                                           | Dopo #134 nessun testo tagliato. Resta un taglio di 3 px misurato su « Il mio pannello » nel menu laterale, non visibile nello screenshot              |
| Colori forzati                 | Emulazione di Chromium (`forcedColors: 'active'`)                                                                                                                                                                       | Dopo #134: contorno col focus su tutti i controlli percorsi, bordo dei pulsanti pieni, tab dell'aspetto scelto distinguibile                           |
| Tab nel modulo di prenotazione | 25 Tab e 25 Shift+Tab, poi Esc                                                                                                                                                                                          | Il focus resta nel modulo; Esc lo chiude e il focus torna a "Prenota"                                                                                  |
| Ordine e indicatore del focus  | Tab su pagina del provider, login e pannello, tema chiaro e scuro                                                                                                                                                       | Ordine logico, "Salta al contenuto" per primo (il login non lo ha: c'è solo il modulo), anello visibile su ogni controllo                              |
| Bersagli di tocco              | 375×667 con tocco simulato                                                                                                                                                                                              | Nessun controllo inatteso sotto 44 px; da 768 px in su misure identiche a prima                                                                        |
| Movimento ridotto              | `reducedMotion: 'reduce'`                                                                                                                                                                                               | Nessuna animazione attiva all'apertura di modulo e Select; lo spinner gira a 1500 ms                                                                   |
| Controlli a mano               | Firefox su Ubuntu, dall'autore: pulsante calendario del campo data (anello col focus e apertura), focus dopo un'azione nella lista prenotazioni, cambio lingua                                                          | Nessun problema segnalato                                                                                                                              |

**Lighthouse non è stato usato.** Non era installato e il pacchetto è pesante. Il suo controllo di accessibilità usa comunque axe-core: le 228 analisi coprono le stesse regole su più pagine e combinazioni.

## 6. Limiti e debiti noti

**Non provato**

- Nessuna prova completa con un lettore di schermo: NVDA, VoiceOver, TalkBack, Orca.
- Nessuna prova su un telefono vero, né con "riduci movimento" di iOS o Android.
- Nessuna prova con il tema ad alto contrasto di Windows: solo l'emulazione di Chromium, con una tavolozza chiara.

**Falso positivo noto**

- axe segnala `aria-hidden-focus` da controllare (36 nodi) solo con il modulo di prenotazione aperto. Radix nasconde il resto della pagina con `aria-hidden` senza `inert`. Il Tab dimostra che il focus non esce dal modulo.

**Debiti (issue #112)**

- Ordine dei titoli nelle impostazioni: l'h2 « Impostazioni » viene prima dell'h1, e l'h1 è ripetuto da un h2 con lo stesso testo.
- Voce attiva della Select aperta e del menu utente nei colori forzati: senza lo sfondo non si distingue.
- Errori accessibili nei moduli del provider (servizi, opzioni, disponibilità, profilo), come quelli fatti in #125 per prenotazione e accesso.
- Plurali nei testi tradotti.

**Debito (issue #130)**

- Sessione di 120 minuti: un modulo lasciato aperto più a lungo dà « Pagina scaduta » all'invio, senza avviso, e quello che si era scritto va perso.

**Pagine non analizzate**

- La pagina pubblica di una prenotazione (`/bookings/{token}`): serve il token di un cliente.
- Verifica email: serve un utente non verificato.
- Impostazioni → password: è stata analizzata solo la conferma della password che la precede.

## 7. Come ripetere le verifiche

**Test**: `composer ci:check` (sezione 4).

**Playwright e axe**, sempre fuori dalla repo e mai in `package.json`:

1. Installare in una cartella temporanea:

    ```bash
    mkdir -p /tmp/a11y && cd /tmp/a11y && npm init -y
    npm install playwright @axe-core/playwright
    npx playwright install chromium
    ```

2. Compilare gli asset (`npm run build`), poi avviare un server che non scriva nel database:

    ```bash
    SESSION_DRIVER=file CACHE_STORE=file php artisan serve --port=8123
    ```

3. Per ogni pagina: aprirla con Playwright alla larghezza voluta e con `colorScheme`, `reducedMotion` o `forcedColors`. Poi eseguire `new AxeBuilder({ page }).withTags([...]).analyze()` con i tag della sezione 5.
    - Per l'area provider: login con un provider del seeder di sviluppo, per esempio `trecce.sahel@example.com`. La password è la costante `DEV_PASSWORD` in `database/seeders/ProviderSeeder.php`: un valore solo per l'uso in locale.
    - Per la lingua: il cookie `locale` è cifrato; il modo più semplice è cambiare lingua dal selettore, che però invia un modulo.
4. Spegnere il server e cancellare la cartella temporanea e `~/.cache/ms-playwright`.

**Controlli a mano da rifare a ogni sprint**

1. Tab su pagina del provider, login e modulo di prenotazione: ordine logico, anello sempre visibile, il focus non esce dal modulo e torna su "Prenota" con Esc.
2. Cambio lingua con la tastiera: il selettore si apre con Invio, le frecce non cambiano pagina, `<html lang>` cambia.
3. Focus dopo un'azione: conferma, rifiuto o annullamento nella lista prenotazioni.
4. Zoom 200% e 400% (Ctrl e +): nessuno scorrimento orizzontale, niente tagliato.
5. Riduci animazioni: in Firefox `about:config`, `ui.prefersReducedMotion` = `1`. Modulo e liste si aprono senza movimento, lo spinner gira.
6. Firefox, modalità design reattivo (Ctrl+Shift+M) a 375×667 con simulazione del tocco: controlli toccabili, header intero.
7. Colori forzati (in Chromium: DevTools → Rendering → `forced-colors: active`): contorni dei controlli e del focus.
8. Se possibile, un giro con Orca (Super+Alt+S su Ubuntu) su pagina del provider e modulo di prenotazione.

## 8. Checklist per nuove funzioni

- [ ] Ogni testo, etichetta e `aria-label` con `t()`, chiavi in it, fr ed en.
- [ ] Ogni campo ha un'etichetta; il suo errore è collegato con `aria-describedby` e, dopo un invio rifiutato, il focus va al primo campo sbagliato.
- [ ] Controlli di almeno 44×44 px sotto 768 px; i link isolati usano `touchTarget`.
- [ ] Focus visibile: ogni `outline-none` ha `focus-visible:outline-hidden`; niente `tabIndex` positivi.
- [ ] Un solo h1 per pagina, poi h2 e h3 senza salti.
- [ ] Nessuna animazione nuova senza passare dalla regola di `prefers-reduced-motion` in `app.css`.
- [ ] Colori dai token di `app.css` o dalla palette; i nuovi abbinamenti aggiunti al test di contrasto.
- [ ] Giallo (`highlight`) solo come sfondo con il suo testo scuro; rosso decorativo (`decor`) mai per dare un significato; errori e stati con i loro colori di sempre.
- [ ] Un test Pest per ogni regola nuova, con una controprova: rompere la regola e vedere fallire il test giusto.
