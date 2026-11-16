# Griot: testi e lingue

Come arrivano i testi dell'interfaccia alle pagine. Le regole per scriverli (chiavi piatte in `lang/{it,fr,en}.json`, `t()` nei componenti, `__()` lato PHP, parità tra le lingue) sono nel `CLAUDE.md`.

## Le traduzioni nelle pagine (#178)

- Ogni pagina riceve le traduzioni della sua lingua come prop condivisa `translations` (`HandleInertiaRequests`). I componenti le leggono con `t()` (`hooks/use-translation.ts`), il codice fuori dai componenti con `pageText()` (`lib/document-language.ts`).
- **Una volta sola:** `translations` è una prop « once » di Inertia (`App\Http\TranslationsProp`). Il browser la riceve alla prima visita e la tiene; alle visite dopo manda la sua chiave in `X-Inertia-Except-Once-Props` e il server la lascia fuori. Prima di `navigate` Inertia la rimette nelle props della pagina nuova, quindi `t()` e `pageText()` funzionano come prima.
- **La chiave** è `translations.{lingua}.{hash}`, con 16 caratteri esadecimali del contenuto di `lang/{lingua}.json`. Un'altra lingua (il selettore, la pagina privata di una prenotazione nella sua lingua) o testi cambiati (un deploy) danno un'altra chiave, e le traduzioni arrivano di nuovo. La chiave si calcola quando la pagina viene disegnata: la lingua può cambiare dopo il middleware (`UseBookingLocale`), e una chiave fissata prima potrebbe nominare la lingua sbagliata.
- **Peso per navigazione** (risposta JSON di una visita Inertia, non compressa): prima circa 56 KB in italiano, 63 KB in francese, 54 KB in inglese a ogni pagina; ora da 0,7 a 1,7 KB sulle pagine misurate (home, lista, pagina di un professionista). Il primo caricamento della pagina intera le contiene ancora, come prima.
- I ricarichi parziali (polling, Indietro e Avanti) non le chiedevano già prima.

## Titoli delle pagine

Tutte le pagine hanno `<Head title={t(...)}>` con una chiave nelle tre lingue. L'eccezione voluta è la pagina di un professionista, che ha come titolo il suo nome: è contenuto suo e non si traduce (#132). Le anteprime dei link (`LinkPreview`) usano `__()` nella lingua della risposta ([link-previews.md](link-previews.md)).

## Test

`tests/Feature/TranslationsOnceTest.php`: la prima visita con la chiave della sua lingua, le visite dopo senza le traduzioni, un'altra lingua e il ritorno alla prima, la pagina privata nella lingua della prenotazione, la chiave con l'hash del file, i ricarichi parziali, i sorgenti di `t()` e `pageText()`.
