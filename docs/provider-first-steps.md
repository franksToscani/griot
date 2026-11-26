# I primi passi: guida per i professionisti

Una pagina breve da leggere, o da mandare, a un professionista appena creato con `provider:create`. Il funzionamento tecnico è in [accessibility.md](accessibility.md) (« Guida ai primi passi ») e in `App\Profile\SetupSteps`.

## Cosa vedi al primo accesso

Sulla dashboard, finché non hai ancora né un servizio né un orario, la guida ti accoglie con « Benvenuto: quattro passi e la tua pagina è online. »; la frase sparisce da sola appena aggiungi il primo servizio o il primo orario.

Finché la tua pagina non è pubblicata, in cima alla dashboard, al profilo, ai servizi e agli orari trovi **« Prepara la tua pagina »**: quattro cerchi in fila, uno per passo, con sotto il nome e lo stato (« fatto », « da fare »). Un cerchio con la spunta è un passo fatto; il passo da fare adesso è nel colore principale, e il pulsante **« Vai a: … »** ti ci porta. In fondo alla pagina, i pulsanti « Indietro » e « Avanti » ti portano al passo prima e a quello dopo. Sul telefono vedi una riga sola, per esempio « Passo 2 di 4: I tuoi servizi », con una barretta di avanzamento: toccala per vedere tutti i passi.

1. **La tua attività** (facoltativo): nome e città ci sono già. Puoi aggiungere una descrizione, WhatsApp e Instagram, e l'indirizzo della tua pagina.
2. **I tuoi servizi**: almeno un servizio attivo, con prezzo e tempo da tenere libero.
3. **I tuoi orari**: almeno una fascia della settimana in cui lavori.
4. **Controlla e pubblica**: nella dashboard guarda l'anteprima della tua pagina, poi premi « Pubblica il profilo ».

## Cosa serve davvero per pubblicare

Solo un servizio attivo e una fascia oraria (nome e città li hai già). Descrizione, contatti e foto sono consigliati ma non obbligatori. Finché manca qualcosa, il pulsante « Pubblica il profilo » nella dashboard resta spento: i passi in cima dicono cosa manca e dove farlo.

## Buono a sapersi

- Puoi seguire i passi nell'ordine che vuoi: ogni pagina funziona anche da sola.
- Quando la pagina è pubblicata la guida sparisce. Da lì in poi trovi tutto nel menu laterale.
- **Un solo nome**: quello della tua attività, che vedono i clienti. Lo cambi in « Modifica il tuo profilo »; Impostazioni lo mostra, con un link al profilo, ma non lo modifica. Per chi gestisce il sito: il nome dell'account (`users.name`) segue quello dell'attività a una via, a ogni salvataggio del profilo (`App\Profile\SyncAccountName`); per gli account salvati prima c'è `php artisan providers:sync-account-names`, che senza `--force` elenca soltanto (slug o `id-N` e totali, mai nomi né email).
- Per condividere la tua pagina e stampare il QR code: [provider-link.md](provider-link.md).
