<?php

namespace App\Http\Controllers;

use App\Support\LinkPreview;
use App\Support\SiteContact;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The terms of use page, public, a draft like the privacy page (issue #131, docs/legal.md). The
 * texts are in lang/ (terms.*); what changes with the installation comes from the config: the
 * publisher (config/terms.php, from the environment), the site's contact (config/contact.php) and
 * the cancellation notice of the bookings (config/booking.php).
 */
class TermsController extends Controller
{
    /**
     * Handle the incoming request. A missing or blank value is sent as null: the page shows a
     * placeholder, never a value of its own.
     */
    public function __invoke(LinkPreview $preview): Response
    {
        $preview->publicPage(__('terms.title'), __('link_preview.terms.description'));

        return Inertia::render('terms', [
            'publisher' => [
                'name' => $this->filled(config('terms.publisher_name')),
                'address' => $this->filled(config('terms.publisher_address')),
                'vatNumber' => $this->filled(config('terms.publisher_vat_number')),
                // The same address as the footer, validated there: null when missing or invalid.
                'email' => SiteContact::shared()['email'],
            ],
            'reviewed' => config()->boolean('terms.reviewed'),
            'updatedOn' => config()->string('terms.updated_on'),
            'cancellationHours' => intdiv(config()->integer('booking.cancellation_notice_minutes'), 60),
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
