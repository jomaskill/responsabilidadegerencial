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
        Schema::create('federative_units', function (Blueprint $table) {
            $table->id();
            $table->char('ibge_code', 2)->unique();
            $table->char('acronym', 2)->unique();
            $table->string('name');
            $table->string('region');
            $table->timestamps();
        });

        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federative_unit_id')->constrained()->restrictOnDelete();
            $table->char('ibge_code', 7)->unique();
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_active')->default(true);
            $table->date('installed_at')->nullable();
            $table->date('extinct_at')->nullable();
            $table->timestamps();

            $table->index(['federative_unit_id', 'normalized_name']);
        });

        Schema::create('municipality_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('scheme');
            $table->string('value');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->unique(['scheme', 'value']);
        });

        Schema::create('administrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('election_year');
            $table->date('term_start');
            $table->date('term_end');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['municipality_id', 'term_start', 'term_end']);
        });

        Schema::create('administration_office_holders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administration_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role');
            $table->string('party_acronym', 20)->nullable();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->text('source_url')->nullable();
            $table->timestamps();

            $table->index(['administration_id', 'role', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('administration_office_holders');
        Schema::dropIfExists('administrations');
        Schema::dropIfExists('municipality_identifiers');
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('federative_units');
    }
};
