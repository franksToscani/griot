# Griot

**Griot** è una web app che fa da ponte tra le persone interessate alla cultura afro e i professionisti che ne custodiscono il savoir-faire: chi fa treccine, barbieri, sarti, cuochi e molti altri.

Per i clienti, è un posto dove trovare facilmente un prestatore e prenotare senza scambi infiniti di messaggi. Per i prestatori, è uno strumento per presentare il proprio lavoro e gestire le prenotazioni, anche con durate variabili e senza sovrapposizioni.

Il nome viene dai _griot_, i narratori e custodi della memoria nell'Africa occidentale: l'idea è dare visibilità a chi tramanda un sapere, un servizio alla volta.

## Che cosa fa oggi

- Scoperta dei professionisti (lista, ricerca, filtri, pagina pubblica) e prenotazione.
- I clienti non hanno un account: prenotano con nome e contatto, e ricevono un link privato alla loro prenotazione.
- I professionisti hanno un account: vetrina, servizi e opzioni, orari settimanali, prenotazioni da confermare, rifiutare o annullare.
- Nessun pagamento online.
- Interfaccia in italiano, francese e inglese.

## Stack

- Laravel 13 (PHP 8.4 o più recente)
- Inertia.js 3 con React e TypeScript
- Tailwind CSS 4, componenti di shadcn/ui
- Database: PostgreSQL in produzione e nella CI, SQLite in memoria nei test locali, MySQL in sviluppo
- Test con Pest; stile PHP con Pint; analisi statica con PHPStan; il frontend si controlla con `vp`
- Hosting su Render, con un'immagine Docker

## Stato del progetto

In sviluppo, online in fase privata (il sito non è indicizzato). Gli sprint, quello che è fatto e quello che manca sono in [docs/roadmap.md](docs/roadmap.md).

## Avvio in locale

Servono PHP 8.4, Composer, Node e un database MySQL vuoto (il nome è in `.env`, `DB_DATABASE`).

```bash
composer setup   # dipendenze, .env, chiave, migrazioni, build del frontend
composer dev     # server, coda, log e Vite insieme
```

Le categorie e i dati di prova si caricano con i seeder (`php artisan db:seed`), solo in sviluppo. In produzione si caricano solo le categorie (`CategorySeeder`, vedi [docs/deploy.md](docs/deploy.md)).

## Controlli

| Cosa                                          | Comando               |
| --------------------------------------------- | --------------------- |
| Tutto, prima di ogni Pull Request             | `composer ci:check`   |
| Test PHP (con Pint in sola lettura e PHPStan) | `composer test`       |
| Correggere lo stile PHP                       | `composer lint`       |
| Frontend: formattazione e lint                | `npm run check`       |
| Frontend: correggere                          | `npm run check:fix`   |
| Tipi TypeScript                               | `npm run types:check` |

I test e `composer ci:check` vanno lanciati con `composer dev` spento.

## Documentazione

Tutto il resto è nella cartella [docs/](docs/): per esempio il deploy ([deploy.md](docs/deploy.md)), la produzione ([production.md](docs/production.md)), i controlli automatici ([ci.md](docs/ci.md)), le prenotazioni ([bookings.md](docs/bookings.md)), l'accessibilità ([accessibility.md](docs/accessibility.md)) e le lingue ([translations.md](docs/translations.md)).
