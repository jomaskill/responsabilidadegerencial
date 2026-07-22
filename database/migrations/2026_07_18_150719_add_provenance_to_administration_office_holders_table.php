<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('administration_office_holders', function (Blueprint $table) {
            $table->foreignId('source_release_id')->nullable()->after('administration_id')->constrained()->nullOnDelete();
            $table->string('external_identifier')->nullable()->unique()->after('source_release_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('administration_office_holders', function (Blueprint $table) {
            $table->dropUnique(['external_identifier']);
            $table->dropConstrainedForeignId('source_release_id');
            $table->dropColumn('external_identifier');
        });
    }
};
