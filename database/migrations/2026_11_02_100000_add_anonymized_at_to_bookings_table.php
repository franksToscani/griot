<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The retention of the customers' data (App\Booking\AnonymizeOldBookings): an old booking keeps
     * its row, without the customer's name and phone, so the two columns can be null. anonymized_at
     * says when that happened; the bookings made before this column were never made anonymous: null.
     * The index follows the command's query: anonymized_at is null, ends_at before the threshold.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('customer_name', 150)->nullable()->change();
            $table->string('customer_phone', 30)->nullable()->change();
            $table->timestamp('anonymized_at')->nullable()->after('reminder_sent_at');

            $table->index(['anonymized_at', 'ends_at'], 'bookings_anonymize_index');
        });
    }

    /**
     * Reverse the migrations. The anonymous bookings get an empty name and phone first: the columns
     * cannot become NOT NULL again while a row holds null (MySQL refuses the change), and the data
     * are gone anyway. The rows stay, with the provider's history.
     */
    public function down(): void
    {
        // The query builder, not the model: a migration must keep working when the model changes.
        DB::table('bookings')->whereNull('customer_name')->update(['customer_name' => '']);
        DB::table('bookings')->whereNull('customer_phone')->update(['customer_phone' => '']);

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_anonymize_index');
            $table->dropColumn('anonymized_at');
            $table->string('customer_name', 150)->nullable(false)->change();
            $table->string('customer_phone', 30)->nullable(false)->change();
        });
    }
};
