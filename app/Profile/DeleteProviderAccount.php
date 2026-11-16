<?php

namespace App\Profile;

use App\Models\ProviderPhoto;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\SiteContact;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a user's account (Settings\ProfileController::destroy), with its provider, services,
 * options, availabilities and gallery by cascade in the database, then its files on the media disk.
 *
 * An account whose provider has bookings, in any status and anonymous ones too, is never deleted
 * (issue #133, docs/media-storage.md): bookings.provider_id and bookings.service_id are
 * restrictOnDelete, the customers' private links and the provider's history depend on those rows.
 * ProfileDeleteRequest refuses it before anything happens; here the same refusal covers a booking
 * that arrives between the validation and the delete.
 */
final class DeleteProviderAccount
{
    /**
     * Whether the user's provider has at least one booking, whatever its status.
     */
    public static function hasBookings(User $user): bool
    {
        // The relation, read once per request: the sidebar (ShareProviderNavigation) uses it too.
        return $user->provider?->bookings()->exists() ?? false;
    }

    /**
     * The message of a refusal, with the site's contact (config/contact.php) when there is one.
     */
    public static function refusalMessage(): string
    {
        $email = SiteContact::shared()['email'];

        $contact = $email !== null
            ? __('settings.delete.has_bookings_email', ['email' => $email])
            : __('settings.delete.has_bookings_no_contact');

        return __('settings.delete.has_bookings').' '.$contact;
    }

    /**
     * @throws AccountHasBookings when the provider has bookings
     */
    public function delete(User $user): void
    {
        // Read before: the provider's row and its gallery go with the user (cascade in the
        // database), the files stay on the disk until removed below.
        $provider = $user->provider()->first();
        $photo = $provider?->avatar_path;
        $gallery = $provider?->photos->flatMap(fn (ProviderPhoto $galleryPhoto) => $galleryPhoto->paths())->all() ?? [];

        try {
            DB::transaction(function () use ($user): void {
                if (self::hasBookings($user)) {
                    throw AccountHasBookings::forUser($user->id);
                }

                $user->delete();
            });
        } catch (QueryException $exception) {
            // The safety net, outside the transaction, which is already rolled back: a booking
            // can still arrive between the check and the delete. Only that error is translated;
            // anything else goes on.
            if (ForeignKeyViolation::from($exception)) {
                throw AccountHasBookings::forUser($user->id);
            }

            throw $exception;
        }

        // Only now, with the account gone. A failure does not undo the deletion of the account
        // nor reach the user: a warning in the log, with no personal data.
        if ($photo !== null && $photo !== '') {
            MediaDisk::deleteQuietly($photo, 'The photo of a deleted provider account could not be removed from the media disk.');
        }

        foreach ($gallery as $path) {
            DeleteProviderPhoto::removeFile($path);
        }
    }
}
