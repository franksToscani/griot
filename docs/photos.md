# Griot: foto del sito

Le foto che il sito mostra di suo: il carousel della home (issue #144) e le schede della fila « Categorie », una foto per categoria. Le foto del lavoro dei provider non sono qui: le caricano loro, con la casella del consenso (`docs/gallery.md`).

**Nome della foto e slug della categoria.** La colonna « Slug » delle tabelle qui sotto è il nome della foto: quello dei file (`barbiere-640.webp`) e dell'originale per `scripts/home-photos.php`, che erano lo slug della categoria. Dalle nove categorie lo slug può essere diverso, e `resources/js/lib/home-photos.ts` collega ogni categoria ai suoi file: `taglio-uomo` usa la foto `barbiere`, `unghie` la foto `nails`, `treccine` la sua. I file di `barbiere` e `nails` non sono stati rinominati né rigenerati; quella di Treccine è stata sostituita il 18 novembre 2026 (foto bakirkoy al posto di Alex Robinson). Le sei foto aggiunte dopo (18 novembre 2026) hanno il nome della loro categoria (`taglio-donna`, `locs`, `make-up`, `ciglia-sopracciglia`, `moda-afro`, `food`).

**Le categorie senza foto** mostrano un riquadro vuoto, uguale per tutte: fondo sabbia con un piccolo segno neutro (`components/empty-picture.tsx`, [home.md](home.md)). Serve solo finché mancano le foto: la vera soluzione è aggiungere quella della categoria (sezione « Aggiungere una foto »). Dal 18 novembre 2026 tutte e nove le categorie hanno la loro foto, quindi il riquadro vuoto resta solo per una categoria futura. Una categoria « In arrivo » mostra la sua foto come le altre: « In arrivo » è calcolato dai servizi (`Category::withOffered()`), la foto non lo cambia. Nel carousel in alto entrano solo le categorie offerte, quindi le foto nuove ci compaiono quando la categoria ha un servizio prenotabile. Per una categoria nuova: l'originale con il nome della foto, `php scripts/home-photos.php`, la voce con lo **slug** della categoria in `home-photos.ts` e le righe nelle tabelle qui sotto.

## Regole (decisione #142)

- Solo da Unsplash, Pexels o Pixabay, con una licenza che permette l'uso commerciale. Mai immagini prese dal web.
- Preferibilmente senza volti riconoscibili (mani, teste viste da dietro, attrezzi, tessuti, cibo). Niente marchi leggibili, niente bambini.
- Per ogni foto, qui sotto: sito, autore, link alla pagina, licenza e data della scelta, i file generati, il ritaglio, l'esito del controllo dei volti e una nota.
- Gli originali restano fuori dalla repo; nella repo entrano solo le varianti WebP, senza metadati.
- **Prima del lancio ricontrollare la licenza sulla pagina ufficiale di ogni foto.** Per Pexels verificare anche se il credito all'autore è richiesto.

## Fonti

Riprese così come sono dal file `fonti.txt` che accompagna gli originali.

| Slug                | Sito     | Autore             | Pagina                                                                                                 | Licenza                                    | Data       |
| ------------------- | -------- | ------------------ | ------------------------------------------------------------------------------------------------------ | ------------------------------------------ | ---------- |
| barbiere            | Unsplash | Hai Phung          | https://unsplash.com/it/foto/persona-che-usa-il-tagliacapelli-bianco-m4Pd_e-4zKs                       | Unsplash License (gratuita, non Unsplash+) | 2026-11-07 |
| nails               | Unsplash | Giorgio Trovato    | https://unsplash.com/it/foto/una-donna-che-si-fa-le-unghie-in-un-salone-di-bellezza-gb6gtiTZKB8        | Unsplash License (gratuita, non Unsplash+) | 2026-11-07 |
| treccine            | Unsplash | foto bakirkoy      | https://unsplash.com/it/foto/una-donna-con-le-trecce-in-piedi-nel-buio-SYN2I837gmY                     | Unsplash License (gratuita, non Unsplash+) | 2026-11-18 |
| taglio-donna        | Pexels   | Farhad Ibrahimzade | https://www.pexels.com/fr-fr/photo/mode-homme-personne-individu-19239103/                              | Pexels License                             | 2026-11-18 |
| locs                | Unsplash | Omar               | https://unsplash.com/it/foto/donna-in-camicia-bianca-con-i-capelli-neri-E8B6soDmIRk                    | Unsplash License                           | 2026-11-18 |
| make-up             | Unsplash | Amari Shutters     | https://unsplash.com/it/foto/un-truccatore-applica-lombretto-sulla-palpebra-di-una-donna-W2c6XeKUDL4   | Unsplash License                           | 2026-11-18 |
| ciglia-sopracciglia | Unsplash | Victor Sirbu       | https://unsplash.com/it/foto/un-primo-piano-di-una-persona-che-taglia-un-occhio-di-persona-kA74I2XMiSQ | Unsplash License                           | 2026-11-18 |
| moda-afro           | Pexels   | Ali Drabo          | https://www.pexels.com/fr-fr/photo/femme-etre-assis-modele-colore-13670802/                            | Pexels License                             | 2026-11-18 |
| food                | Unsplash | Keesha's Kitchen   | https://unsplash.com/it/foto/riso-jollof-con-cena-al-pesce-alla-griglia-PqG32DYCTM8                    | Unsplash License                           | 2026-11-18 |

## Controlli e file

| Slug                | Originale                              | File generati                                                                | Ritaglio                                                                 | Volti                                                                                                                                               | Nota                                                                                                                                                                                                                                               |
| ------------------- | -------------------------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| barbiere            | 5472×3648, orizzontale                 | `barbiere-640.webp`, `-1280.webp`, `-1920.webp`                              | nessuno (3:2, come l'originale)                                          | nessun volto: cliente di spalle; del barbiere si vedono solo le braccia                                                                             | Braccia del barbiere con tatuaggi. Il marchio sull'etichetta della mantella è pixelato nelle varianti.                                                                                                                                             |
| nails               | 6720×4480, orizzontale                 | `nails-640.webp`, `-1280.webp`, `-1920.webp`                                 | nessuno (3:2, come l'originale)                                          | compaiono guancia, orecchio e orecchino dell'estetista, non riconoscibili                                                                           | L'adesivo « CND UV LAMP » e la scritta « CND » sulla lampada UV sono pixelati nelle varianti. Le etichette del barattolo sono già sfocate.                                                                                                         |
| treccine            | 1920×2880, verticale (2:3), fondo nero | `treccine-640.webp`, `-1280.webp` (niente 1920: il ritaglio è largo 1440 px) | `[187, 360, 1440, 960]`: testa, orecchio e trecce alte, fino alle spalle | nessun volto riconoscibile: persona di spalle, si vedono l'orecchio e il bordo della guancia                                                        | Nessuna scritta né marchio: maglia turchese tinta unita. Ritaglio scelto tra tre alternative (largo a tutta larghezza, medio, stretto sulle trecce): il medio lascia meno fondo nero ai lati.                                                      |
| taglio-donna        | 1920×2400, verticale                   | `taglio-donna-640.webp`, `-1280.webp`, `-1920.webp`                          | `[0, 920, 1920, 1280]`: tutta la larghezza, forbici, pettine e mani      | nessun volto: testa vista da dietro, si vede l'orecchio; della parrucchiera solo le mani                                                            | Nessuna scritta: forbici e pettine senza incisioni leggibili.                                                                                                                                                                                      |
| locs                | 1920×1278, orizzontale                 | `locs-640.webp`, `-1280.webp` (niente 1920: il ritaglio è largo 1917 px)     | `[0, 0, 1917, 1278]`: tutta la foto, 3:2 al pixel                        | **volto di profilo, riconoscibile**: scelta provvisoria, in attesa della decisione tra foto intera e ritaglio a destra (locs e maglia, volto fuori) | Nessuna scritta. Il ritaglio a destra sarebbe largo 939 px: solo la variante da 640.                                                                                                                                                               |
| make-up             | 1920×1080, 16:9                        | `make-up-640.webp`, `-1280.webp` (niente 1920: il ritaglio è largo 1620 px)  | `[0, 0, 1620, 1080]`: pennello, mano e palpebra                          | volto di profilo, occhi chiusi: naso, labbra e palpebra, in parte riconoscibile                                                                     | Nessuna scritta. Gli orecchini (uno a quadrifoglio, che ricorda un modello di gioielleria noto) restano fuori dal ritaglio.                                                                                                                        |
| ciglia-sopracciglia | 1920×1280, orizzontale                 | `ciglia-sopracciglia-640.webp`, `-1280.webp`, `-1920.webp`                   | nessuno (3:2, come l'originale)                                          | un occhio chiuso con il cerotto sotto le ciglia e le dita dell'estetista: non riconoscibile                                                         | Nessuna scritta.                                                                                                                                                                                                                                   |
| moda-afro           | 1920×1536, circa 5:4                   | `moda-afro-640.webp`, `-1280.webp`, `-1920.webp`                             | `[0, 40, 1920, 1280]`: turbante, volto e tessuti, tutta la larghezza     | **volto di fronte, riconoscibile**: una modella in posa, scelta voluta per la categoria                                                             | Le scritte piccole della radio a destra non si leggono neanche ingrandite al doppio: non pixelate (i blocchi si vedrebbero più delle scritte). Sull'orologio solo i numeri. Qualità 66 (640 e 1280) e 76 (1920): i tessuti sono pieni di dettagli. |
| food                | 1920×1280, orizzontale                 | `food-640.webp`, `-1280.webp`, `-1920.webp`                                  | nessuno (3:2, come l'originale)                                          | nessun volto: solo cibo                                                                                                                             | Nessuna scritta; il pentolino arancione non ha un marchio visibile.                                                                                                                                                                                |

I file stanno in `resources/images/home/` e sono importati da `resources/js/lib/home-photos.ts` con Vite: nel sito hanno un nome con l'hash del contenuto, quindi una foto cambiata non resta mai in cache con lo stesso indirizzo.

## Rigenerare le varianti

Lo script `scripts/home-photos.php` (solo PHP e GD, nessun download):

```bash
php scripts/home-photos.php /percorso/della/cartella/degli/originali
```

- Gli originali si chiamano con il nome della foto (`barbiere.jpg`, `taglio-donna.jpg`, …): lo script tratta ogni immagine della cartella, quindi per aggiungere una foto basta una cartella con il solo originale nuovo.
- Prima pixela le zone di `ZONES` (coordinate in pixel dell'originale, `[x1, y1, x2, y2]`), a blocchi di `BLOCK` = 48 px dell'originale: alla variante da 1920 px un blocco misura ancora circa 15 px, troppo per leggere una lettera.
    - barbiere: `[1250, 2150, 1680, 2720]`, l'etichetta della mantella;
    - nails: `[1990, 3080, 2620, 3660]`, l'adesivo della lampada, e `[3500, 3880, 3950, 4480]`, il logo « CND ».
- Poi ritaglia a 3:2 le foto di `CROPS` (`[x, y, larghezza, altezza]` in pixel dell'originale, colonna « Ritaglio » qui sopra); una foto che non è 3:2 e non è in `CROPS` ferma lo script.
- Poi ridimensiona a 640, 1280 e 1920 px di larghezza (mai ingrandire: una misura più larga del ritaglio non si produce, così `treccine`, `locs` e `make-up` non hanno la 1920) e scrive WebP a qualità 80. GD scrive solo i pixel: niente EXIF, GPS o commenti.
- Limiti di peso: 640 ≤ 60 KB, 1280 ≤ 150 KB, 1920 ≤ 300 KB. Una variante più pesante viene riscritta con una qualità più bassa, fino a 64 (era 70 fino alla foto di Moda afro, che a 70 pesava 62,5 KB a 640 px); se sfora ancora, lo script si ferma. Oggi `moda-afro-640` e `moda-afro-1280` sono a qualità 66, `moda-afro-1920` a 76, le altre a 80.
- Dopo averlo lanciato: guardare le zone pixelate di ogni variante (devono essere illeggibili anche a 1920 px), poi `npm run build` e i test (`tests/Feature/HomePhotosTest.php`).

## Aggiungere una foto

1. Sceglierla secondo le regole qui sopra e salvarla fuori dalla repo con il nome dello slug della categoria, insieme alla sua riga in `fonti.txt` (slug | sito | autore | link | licenza | data). Se non è 3:2, aggiungere il ritaglio a `CROPS`.
2. Guardarla: volti, scritte, marchi, bambini. Se c'è un marchio, aggiungere la sua zona a `ZONES` nello script.
3. Lanciare lo script; controllare pesi e zone pixelate.
4. Aggiungere le varianti a `HOME_PHOTOS` in `resources/js/lib/home-photos.ts`, con `width` e `height` della più grande.
5. Aggiungere la riga nelle due tabelle di questo file.
6. `npm run build` e `composer ci:check`.
