# Griot: foto del sito

Le foto che il sito mostra di suo (oggi il carousel della home, issue #144). Le foto del lavoro dei provider non sono qui: le caricano loro, con la casella del consenso (`docs/gallery.md`).

## Regole (decisione #142)

- Solo da Unsplash, Pexels o Pixabay, con una licenza che permette l'uso commerciale. Mai immagini prese dal web.
- Preferibilmente senza volti riconoscibili (mani, teste viste da dietro, attrezzi, tessuti, cibo). Niente marchi leggibili, niente bambini.
- Per ogni foto, qui sotto: sito, autore, link alla pagina, licenza e data della scelta, i file generati, il ritaglio, l'esito del controllo dei volti e una nota.
- Gli originali restano fuori dalla repo; nella repo entrano solo le varianti WebP, senza metadati.
- **Prima del lancio ricontrollare la licenza sulla pagina ufficiale di ogni foto.** Per Pexels verificare anche se il credito all'autore è richiesto.

## Fonti

Riprese così come sono dal file `fonti.txt` che accompagna gli originali.

| Slug     | Sito     | Autore          | Pagina                                                                                          | Licenza                                    | Data       |
| -------- | -------- | --------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------ | ---------- |
| barbiere | Unsplash | Hai Phung       | https://unsplash.com/it/foto/persona-che-usa-il-tagliacapelli-bianco-m4Pd_e-4zKs                | Unsplash License (gratuita, non Unsplash+) | 2026-11-07 |
| nails    | Unsplash | Giorgio Trovato | https://unsplash.com/it/foto/una-donna-che-si-fa-le-unghie-in-un-salone-di-bellezza-gb6gtiTZKB8 | Unsplash License (gratuita, non Unsplash+) | 2026-11-07 |
| treccine | Unsplash | Alex Robinson   | https://unsplash.com/it/foto/ornamento-rotondo-nero-e-grigio-3IhcN94zWwk                        | Unsplash License (gratuita, non Unsplash+) | 2026-11-07 |

## Controlli e file

| Slug     | Originale              | File generati                                   | Ritaglio                        | Volti                                                                                                                                           | Nota                                                                                                                                       |
| -------- | ---------------------- | ----------------------------------------------- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| barbiere | 5472×3648, orizzontale | `barbiere-640.webp`, `-1280.webp`, `-1920.webp` | nessuno (3:2, come l'originale) | nessun volto: cliente di spalle; del barbiere si vedono solo le braccia                                                                         | Braccia del barbiere con tatuaggi. Il marchio sull'etichetta della mantella è pixelato nelle varianti.                                     |
| nails    | 6720×4480, orizzontale | `nails-640.webp`, `-1280.webp`, `-1920.webp`    | nessuno (3:2, come l'originale) | compaiono guancia, orecchio e orecchino dell'estetista, non riconoscibili                                                                       | L'adesivo « CND UV LAMP » e la scritta « CND » sulla lampada UV sono pixelati nelle varianti. Le etichette del barattolo sono già sfocate. |
| treccine | 6000×4000, orizzontale | `treccine-640.webp`, `-1280.webp`, `-1920.webp` | nessuno (3:2, come l'originale) | nessun volto: testa vista da dietro. Persona adulta: verificato a occhio da Franks il 2026-11-07, l'età non è indicata nella pagina di Unsplash | Nessuna scritta.                                                                                                                           |

I file stanno in `resources/images/home/` e sono importati da `resources/js/lib/home-photos.ts` con Vite: nel sito hanno un nome con l'hash del contenuto, quindi una foto cambiata non resta mai in cache con lo stesso indirizzo.

## Rigenerare le varianti

Lo script `scripts/home-photos.php` (solo PHP e GD, nessun download):

```bash
php scripts/home-photos.php /percorso/della/cartella/degli/originali
```

- Gli originali si chiamano con lo slug della categoria (`barbiere.jpg`, …).
- Prima pixela le zone di `ZONES` (coordinate in pixel dell'originale, `[x1, y1, x2, y2]`), a blocchi di `BLOCK` = 48 px dell'originale: alla variante da 1920 px un blocco misura ancora circa 15 px, troppo per leggere una lettera.
    - barbiere: `[1250, 2150, 1680, 2720]`, l'etichetta della mantella;
    - nails: `[1990, 3080, 2620, 3660]`, l'adesivo della lampada, e `[3500, 3880, 3950, 4480]`, il logo « CND ».
- Poi ridimensiona a 640, 1280 e 1920 px di larghezza (mai ingrandire: una misura più larga dell'originale non si produce) e scrive WebP a qualità 80. GD scrive solo i pixel: niente EXIF, GPS o commenti.
- Limiti di peso: 640 ≤ 60 KB, 1280 ≤ 150 KB, 1920 ≤ 300 KB. Una variante più pesante viene riscritta con una qualità più bassa, fino a 70; se sfora ancora, lo script si ferma. Oggi `treccine-1280` e `treccine-1920` sono a qualità 78, le altre a 80.
- Dopo averlo lanciato: guardare le zone pixelate di ogni variante (devono essere illeggibili anche a 1920 px), poi `npm run build` e i test (`tests/Feature/HomePhotosTest.php`).

## Aggiungere una foto

1. Sceglierla secondo le regole qui sopra e salvarla fuori dalla repo con il nome dello slug della categoria, insieme alla sua riga in `fonti.txt` (slug | sito | autore | link | licenza | data).
2. Guardarla: volti, scritte, marchi, bambini. Se c'è un marchio, aggiungere la sua zona a `ZONES` nello script.
3. Lanciare lo script; controllare pesi e zone pixelate.
4. Aggiungere le varianti a `HOME_PHOTOS` in `resources/js/lib/home-photos.ts`, con `width` e `height` della più grande.
5. Aggiungere la riga nelle due tabelle di questo file.
6. `npm run build` e `composer ci:check`.
