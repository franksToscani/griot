<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A hidden option is not offered to new customers, but stays in the bookings that already
     * chose it. The existing options stay visible: the default is true.
     */
    public function up(): void
    {
        Schema::table('service_options', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('extra_duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_options', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
