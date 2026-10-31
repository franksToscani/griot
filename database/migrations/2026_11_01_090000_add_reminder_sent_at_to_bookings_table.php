<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When the provider was reminded of a request still pending (App\Booking\RemindPendingBookings):
     * one reminder per booking, never repeated. The bookings made before this column were never
     * reminded: null. The index follows the reminder command's query.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('created_by_provider');

            $table->index(['status', 'reminder_sent_at', 'created_at'], 'bookings_reminder_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_reminder_index');
            $table->dropColumn('reminder_sent_at');
        });
    }
};
