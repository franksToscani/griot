<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

// The texts of the Laravel starter kit, of Laravel and of Fortify, in Italian (issue #54, phase 1b).
// The pages have no JavaScript test runner: the tests on the .tsx files read the source.

beforeEach(function () {
    app()->setLocale('it');
    // The local .env may still say "Laravel": the emails are checked with the real name.
    config()->set('app.name', 'Griot');
});

/**
 * Every React source of the application, generated folders left out, as one string per file.
 *
 * @return array<string, string>
 */
function kitSources(): array
{
    $sources = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) !== 1 && preg_match('/\.tsx?$/', $path) === 1) {
            $sources[$path] = (string) file_get_contents($path);
        }
    }

    return $sources;
}

/**
 * The HTML of a mail message, rendered with Laravel's own email layout.
 */
function kitRender(MailMessage $mail): string
{
    return (string) $mail->render();
}

// ---------------------------------------------------------------------------------------------
// Home
// ---------------------------------------------------------------------------------------------

test('the home page sends to the providers list, for now', function () {
    $this->get('/')->assertRedirect(route('providers.index'));
    expect(route('home'))->toBe(url('/'));
    $this->get(route('providers.index'))->assertOk();
});

// ---------------------------------------------------------------------------------------------
// Laravel's and Fortify's texts
// ---------------------------------------------------------------------------------------------

test('the texts of Laravel and Fortify are in Italian, under their English keys', function () {
    $texts = [
        // Fortify, a wrong password on the confirmation page.
        'The provided password was incorrect.' => 'La password non è corretta.',
        // Laravel, the email verification.
        'Verify your email address' => 'Verifica il tuo indirizzo email',
        'Please click the button below to verify your email address.' => 'Clicca il pulsante qui sotto per verificare il tuo indirizzo email.',
        'Verify Email Address' => "Verifica l'indirizzo email",
        'If you did not create an account, no further action is required.' => 'Se non hai creato tu un account, puoi ignorare questa email.',
        // Laravel, the password reset.
        'Reset your password' => 'Reimposta la password',
        'You are receiving this email because we received a password reset request for your account.' => 'Ricevi questa email perché è stato chiesto di reimpostare la password del tuo account.',
        'Reset Password' => 'Reimposta la password',
        'This password reset link will expire in :count minutes.' => 'Il link per reimpostare la password scade tra :count minuti.',
        'If you did not request a password reset, no further action is required.' => 'Se non hai chiesto tu di reimpostare la password, puoi ignorare questa email.',
        // Laravel, the layout of the emails.
        'Hello!' => 'Ciao!',
        'Whoops!' => 'Ops!',
        'Regards,' => 'Un saluto,',
        "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:" => 'Se il pulsante ":actionText" non funziona, copia e incolla questo indirizzo nel browser:',
        'All rights reserved.' => 'Tutti i diritti riservati.',
        // Laravel, the error pages.
        'Not Found' => 'Pagina non trovata',
        'Forbidden' => 'Accesso negato',
        'Unauthorized' => 'Accesso non autorizzato',
        'Page Expired' => 'Pagina scaduta',
        'Too Many Requests' => 'Troppe richieste',
        'Server Error' => 'Errore del server',
        'Service Unavailable' => 'Servizio non disponibile',
        'Payment Required' => 'Pagamento richiesto',
    ];

    $italian = json_decode((string) file_get_contents(lang_path('it.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($texts)->toHaveCount(23);

    foreach ($texts as $key => $text) {
        expect($italian)->toHaveKey($key)
            ->and($italian[$key])->toBe($text);
    }
});

test('the email verification is in Italian, with the name of Griot and without the Laravel logo', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
        $mail = $notification->toMail($user);
        $html = kitRender($mail);

        expect($mail->subject)->toBe('Verifica il tuo indirizzo email')
            ->and($mail->actionText)->toBe("Verifica l'indirizzo email")
            ->and($mail->introLines)->toBe(['Clicca il pulsante qui sotto per verificare il tuo indirizzo email.'])
            ->and($mail->outroLines)->toBe(['Se non hai creato tu un account, puoi ignorare questa email.']);
        expect($html)
            ->toContain('Ciao!')
            ->toContain('Un saluto,')
            ->toContain('Se il pulsante "Verifica l\'indirizzo email" non funziona')
            ->toContain('Tutti i diritti riservati.')
            ->toContain('Griot')
            ->not->toContain('notification-logo')
            ->not->toContain('Hello!');

        return true;
    });
});

test('the password reset email is in Italian, with the expiry from the config', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('Reimposta la password')
            ->and($mail->actionText)->toBe('Reimposta la password')
            ->and($mail->introLines)->toBe(['Ricevi questa email perché è stato chiesto di reimpostare la password del tuo account.'])
            ->and($mail->outroLines)->toBe([
                'Il link per reimpostare la password scade tra 60 minuti.',
                'Se non hai chiesto tu di reimpostare la password, puoi ignorare questa email.',
            ]);
        expect(kitRender($mail))->toContain('Ciao!')->not->toContain('Regards,');

        return true;
    });
});

test('a wrong password on the confirmation page gets the Italian message of Fortify', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('password.confirm'))
        ->post(route('password.confirm.store'), ['password' => 'non-la-password'])
        ->assertSessionHasErrors(['password' => 'La password non è corretta.']);
});

test('the error page of a missing provider is in Italian', function () {
    $this->get('/providers/non-esiste')
        ->assertNotFound()
        ->assertSee('Pagina non trovata')
        ->assertDontSee('Not Found');
});

// ---------------------------------------------------------------------------------------------
// The name of the application and the kit's leftovers
// ---------------------------------------------------------------------------------------------

test('the application is called Griot in the config, the example environment, the page and React', function () {
    expect((string) file_get_contents(config_path('app.php')))->toContain("'name' => env('APP_NAME', 'Griot'),");
    expect((string) file_get_contents(base_path('.env.example')))->toContain("APP_NAME=Griot\n");
    expect((string) file_get_contents(resource_path('views/app.blade.php')))->toContain("config('app.name', 'Griot')");
    expect((string) file_get_contents(resource_path('js/app.tsx')))->toContain("import.meta.env.VITE_APP_NAME || 'Griot'");
});

test('the example command of the kit is gone, the reminder is still scheduled', function () {
    expect(Artisan::all())->not->toHaveKey('inspire')
        ->and(Artisan::all())->toHaveKey('bookings:remind-pending');
});

test('the kit\'s English texts are no longer written in the React sources', function () {
    $english = [
        'Log in to your account', 'Forgot your password?', 'Remember me', "Don't have an account?", 'Sign up',
        'Create an account', 'Already have an account?', 'Email password reset link', 'Or, return to',
        'Please enter your new password below', 'This is a secure area of the application', 'Resend verification email',
        'Profile settings', 'Security settings', 'Appearance settings', 'Update password', 'Delete account',
        'Please proceed with caution', 'Manage your profile and account settings', 'Toggle sidebar',
        'Displays the mobile sidebar.', 'Navigation menu', 'Platform', 'Repository', 'Documentation',
        'Hide password', 'Show password', "title: 'Dashboard'", "title: 'Bookings'", 'Log out',
        "label: 'Light'", 'aria-label="Loading"', '<span className="sr-only">Close</span>', '<span className="sr-only">More</span>',
        'react-starter-kit', 'laravel.com',
    ];

    foreach (kitSources() as $path => $source) {
        foreach ($english as $text) {
            expect(str_contains($source, $text))->toBeFalse("\"{$text}\" is still in {$path}");
        }
    }
});

test('breadcrumbs and menus carry keys, translated by the components that show them', function () {
    $types = (string) file_get_contents(resource_path('js/types/navigation.ts'));
    $breadcrumbs = (string) file_get_contents(resource_path('js/components/breadcrumbs.tsx'));
    $main = (string) file_get_contents(resource_path('js/components/nav-main.tsx'));
    $footer = (string) file_get_contents(resource_path('js/components/nav-footer.tsx'));
    $settings = (string) file_get_contents(resource_path('js/layouts/settings/layout.tsx'));
    $auth = (string) file_get_contents(resource_path('js/layouts/auth-layout.tsx'));

    expect(substr_count($types, 'titleKey: string;'))->toBe(2)
        ->and($types)->not->toMatch('/^\s+title: string;/m');
    expect(substr_count($breadcrumbs, '{t(item.titleKey)}'))->toBe(2);
    expect($main)->toContain("{t('nav.menu')}")->toContain('<span>{t(item.titleKey)}</span>');
    expect($footer)->toContain('<span>{t(item.titleKey)}</span>');
    expect($settings)->toContain('{t(item.titleKey)}');
    expect($auth)->toContain('t(titleKey)')->toContain('t(descriptionKey)');
});

test('the sidebar footer is kept, empty, and shows nothing while it has no links', function () {
    $sidebar = (string) file_get_contents(resource_path('js/components/app-sidebar.tsx'));
    $footer = (string) file_get_contents(resource_path('js/components/nav-footer.tsx'));

    expect($sidebar)->toContain('const footerNavItems: NavItem[] = [];')
        ->toContain('<NavFooter items={footerNavItems}');
    expect($footer)->toMatch('/if \(items\.length === 0\) \{\s*return null;\s*\}/');
});

test('every page of the private area gives its breadcrumbs as keys', function () {
    foreach (kitSources() as $path => $source) {
        if (str_contains($source, 'breadcrumbs: [')) {
            expect($source)->toContain('titleKey:')
                ->and(preg_match('/breadcrumbs: \[.*?\]/s', $source, $block))->toBe(1);
            expect($block[0])->not->toMatch('/\btitle:/');
        }
    }
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the Italian texts of the kit are exactly the expected ones', function (string $key, string $text) {
    expect(__($key))->toBe($text);
})->with([
    ['common.email_address', 'Indirizzo email'],
    ['common.email_placeholder', 'nome@esempio.it'],
    ['common.password', 'Password'],
    ['common.confirm_password', 'Conferma la password'],
    ['common.new_password', 'Nuova password'],
    ['common.current_password', 'Password attuale'],
    ['common.name', 'Nome'],
    ['common.full_name', 'Nome e cognome'],
    ['common.save', 'Salva'],
    ['common.cancel', 'Annulla'],
    ['common.close', 'Chiudi'],
    ['common.more', 'Altro'],
    ['common.show_password', 'Mostra la password'],
    ['common.hide_password', 'Nascondi la password'],
    ['nav.dashboard', 'Il mio pannello'],
    ['nav.register', 'Registrati'],
    ['nav.logout', 'Esci'],
    ['nav.settings', 'Impostazioni'],
    ['nav.menu', 'Menu'],
    ['nav.sidebar', 'Menu laterale'],
    ['nav.sidebar_description', 'Mostra il menu laterale sul telefono.'],
    ['nav.toggle_sidebar', 'Apri o chiudi il menu laterale'],
    ['account.login.title', 'Accedi al tuo account'],
    ['account.login.description', 'Scrivi email e password per accedere'],
    ['account.login.forgot', 'Password dimenticata?'],
    ['account.login.remember', 'Ricordami'],
    ['account.login.no_account', 'Non hai un account?'],
    ['account.register.title', 'Crea un account'],
    ['account.register.description', "Scrivi i tuoi dati per creare l'account"],
    ['account.register.submit', "Crea l'account"],
    ['account.register.have_account', 'Hai già un account?'],
    ['account.forgot.title', 'Password dimenticata'],
    ['account.forgot.description', 'Scrivi la tua email: ti mandiamo un link per reimpostare la password'],
    ['account.forgot.submit', 'Mandami il link'],
    ['account.forgot.return', "Oppure torna all'"],
    ['account.forgot.return_link', 'accesso'],
    ['account.reset.title', 'Reimposta la password'],
    ['account.reset.description', 'Scegli la nuova password'],
    ['account.confirm.description', "Questa è un'area protetta: conferma la password per continuare."],
    ['account.verify.title', "Verifica l'indirizzo email"],
    ['account.verify.description', 'Conferma il tuo indirizzo cliccando sul link che ti abbiamo appena mandato per email.'],
    ['account.verify.sent', "Ti abbiamo mandato un nuovo link di verifica all'indirizzo che hai usato per registrarti."],
    ['account.verify.resend', "Mandami di nuovo l'email di verifica"],
    ['settings.description', "Gestisci il profilo e l'account"],
    ['settings.nav.profile', 'Profilo'],
    ['settings.nav.security', 'Sicurezza'],
    ['settings.nav.appearance', 'Aspetto'],
    ['settings.profile.head', 'Impostazioni del profilo'],
    ['settings.profile.description', "Aggiorna il nome e l'indirizzo email"],
    ['settings.profile.unverified', 'Il tuo indirizzo email non è verificato.'],
    ['settings.profile.resend', "Clicca qui per ricevere di nuovo l'email di verifica."],
    ['settings.profile.link_sent', 'Ti abbiamo mandato un nuovo link di verifica.'],
    ['settings.security.head', 'Impostazioni di sicurezza'],
    ['settings.security.title', 'Cambia la password'],
    ['settings.security.description', "Usa una password lunga e difficile da indovinare per proteggere l'account"],
    ['settings.appearance.head', "Impostazioni dell'aspetto"],
    ['settings.appearance.description', 'Scegli come appare il sito per il tuo account'],
    ['settings.appearance.light', 'Chiaro'],
    ['settings.appearance.dark', 'Scuro'],
    ['settings.appearance.system', 'Come il sistema'],
    ['settings.delete.title', "Elimina l'account"],
    ['settings.delete.description', "Elimina l'account e tutti i suoi dati"],
    ['settings.delete.warning', 'Attenzione'],
    ['settings.delete.warning_text', 'Procedi con cautela: non si può tornare indietro.'],
    ['settings.delete.confirm_title', "Vuoi davvero eliminare l'account?"],
    ['settings.delete.confirm_text', "Eliminando l'account, anche tutti i suoi dati saranno cancellati per sempre. Scrivi la password per confermare."],
    ['provider.preview.back', 'Torna al mio pannello'],
]);
