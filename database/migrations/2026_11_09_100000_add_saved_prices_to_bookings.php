<?php

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The prices saved on the booking (issue #97, docs/bookings.md): the price of the service and the
 * extra price of each chosen option, in whole euro cents, as they were when the booking was made.
 * A later change of the catalog no longer changes the bookings already made.
 *
 * The bookings that exist already are filled with the CURRENT prices of their service and options:
 * the prices of the day they were made were never saved, so for them it is an approximation.
 *
 * Three steps: the columns are added as nullable, filled in blocks of 200 with the query builder,
 * then made required. No raw SQL: the same migration runs on SQLite, MySQL and PostgreSQL.
 *
 * On SQLite, changing or dropping a column of bookings rebuilds the table, and dropping the old one
 * deletes in cascade the chosen options (booking_service_option.booking_id is cascadeOnDelete):
 * Laravel turns the foreign keys off first, but SQLite ignores that inside a transaction, where
 * the migrations and the tests run. keepingOptionLinks() puts those rows back. MySQL and
 * PostgreSQL change the column in place and lose nothing.
 */
return new class extends Migration
{
    /**
     * The bookings read and filled at a time.
     */
    private const CHUNK = 200;

    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('service_price_cents')->nullable()->after('service_id');
        });

        Schema::table('booking_service_option', function (Blueprint $table) {
            $table->unsignedInteger('extra_price_cents')->nullable();
        });

        // The query builder, not the models: a migration must keep working when the models change.
        DB::table('bookings')->orderBy('id')->chunkById(self::CHUNK, function ($bookings) {
            $ids = $bookings->pluck('id')->all();

            // One query for the services of the block, one for its options.
            $servicePrices = DB::table('services')
                ->whereIn('id', $bookings->pluck('service_id')->unique()->all())
                ->pluck('price', 'id');

            foreach ($bookings as $booking) {
                DB::table('bookings')->where('id', $booking->id)->update([
                    'service_price_cents' => Money::centsFromDatabase($servicePrices[$booking->service_id]),
                ]);
            }

            $chosen = DB::table('booking_service_option')
                ->join('service_options', 'service_options.id', '=', 'booking_service_option.service_option_id')
                ->whereIn('booking_service_option.booking_id', $ids)
                ->get(['booking_service_option.booking_id', 'booking_service_option.service_option_id', 'service_options.extra_price']);

            // The pivot has no id: a row is the pair booking_id and service_option_id.
            foreach ($chosen as $row) {
                DB::table('booking_service_option')
                    ->where('booking_id', $row->booking_id)
                    ->where('service_option_id', $row->service_option_id)
                    ->update(['extra_price_cents' => Money::centsFromDatabase($row->extra_price)]);
            }
        });

        Schema::table('booking_service_option', function (Blueprint $table) {
            $table->unsignedInteger('extra_price_cents')->nullable(false)->change();
        });

        $this->keepingOptionLinks(fn () => Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('service_price_cents')->nullable(false)->change();
        }));
    }

    /**
     * Only the saved prices go: the prices of the services and of the options are never touched.
     * Running up() again fills them with the prices of that moment, the same approximation.
     */
    public function down(): void
    {
        Schema::table('booking_service_option', function (Blueprint $table) {
            $table->dropColumn('extra_price_cents');
        });

        $this->keepingOptionLinks(fn () => Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('service_price_cents');
        }));
    }

    /**
     * Change the bookings table without losing the chosen options: the rows of the pivot are read
     * before, and the ones a SQLite rebuild deleted in cascade are written again after, as they
     * were. On MySQL and PostgreSQL none is missing and nothing is written.
     */
    private function keepingOptionLinks(Closure $alterBookings): void
    {
        $links = DB::table('booking_service_option')
            ->orderBy('booking_id')
            ->orderBy('service_option_id')
            ->get()
            ->map(fn (object $row) => (array) $row);

        $alterBookings();

        $kept = DB::table('booking_service_option')
            ->get(['booking_id', 'service_option_id'])
            ->map(fn (object $row) => $row->booking_id.'-'.$row->service_option_id)
            ->flip();

        $missing = $links->reject(fn (array $row) => $kept->has($row['booking_id'].'-'.$row['service_option_id']));

        foreach ($missing->chunk(self::CHUNK) as $rows) {
            DB::table('booking_service_option')->insert($rows->values()->all());
        }
    }
};
