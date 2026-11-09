<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\LinkPreview;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy page, public. The texts are in lang/ (privacy.*); what changes with the installation
 * comes from the config: the controller and the contact (config/privacy.php, from the environment),
 * the retention of the customers' data and the cancellation notice (config/booking.php), the session
 * cookie, and the "remember me" cookie of the login as Laravel names it.
 */
class PrivacyController extends Controller
{
    /**
     * Handle the incoming request. A missing or blank name or email is sent as null: the page shows
     * a placeholder, never an address of its own.
     */
    public function __invoke(LinkPreview $preview): Response
    {
        $preview->publicPage(__('privacy.title'), __('link_preview.privacy.description'));

        return Inertia::render('privacy', [
            'controller' => [
                'name' => $this->filled(config('privacy.controller_name')),
                'email' => $this->filled(config('privacy.contact_email')),
            ],
            'reviewed' => config()->boolean('privacy.reviewed'),
            'updatedOn' => config()->string('privacy.updated_on'),
            'retentionMonths' => config()->integer('booking.anonymize_after_months'),
            'cancellationHours' => intdiv(config()->integer('booking.cancellation_notice_minutes'), 60),
            'cookies' => [
                'session' => config()->string('session.cookie'),
                'sessionMinutes' => config()->integer('session.lifetime'),
                'locale' => SetLocale::COOKIE,
                // "remember_web_" and a hash: the check box "Ricordami" of the login (providers only).
                'remember' => Auth::guard('web')->getRecallerName(),
                'rememberDays' => intdiv(config()->integer('auth.guards.web.remember'), 60 * 24),
            ],
        ]);
    }

    /**
     * The value without spaces around it, or null when there is none.
     */
    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
