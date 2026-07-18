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
        Schema::create('indicator_observations', function (Blueprint $table) {
            $table->id();
            $table->char('observation_key', 64)->unique();
            $table->foreignId('municipality_id')->constrained()->restrictOnDelete();
            $table->foreignId('indicator_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_release_id')->constrained()->restrictOnDelete();
            $table->foreignId('processing_run_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('reference_year');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('value', 24, 8)->nullable();
            $table->decimal('numerator', 24, 8)->nullable();
            $table->decimal('denominator', 24, 8)->nullable();
            $table->string('availability_status');
            $table->string('quality_status');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['indicator_version_id', 'reference_year', 'availability_status'], 'observations_indicator_year_status_index');
            $table->index(['municipality_id', 'reference_year'], 'observations_municipality_year_index');
        });

        Schema::create('observation_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_observation_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('severity');
            $table->text('message');
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['indicator_observation_id', 'severity']);
        });

        Schema::create('observation_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_observation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('input_indicator_observation_id')->constrained('indicator_observations')->restrictOnDelete();
            $table->string('role');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['indicator_observation_id', 'input_indicator_observation_id'], 'observation_input_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observation_inputs');
        Schema::dropIfExists('observation_flags');
        Schema::dropIfExists('indicator_observations');
    }
};
