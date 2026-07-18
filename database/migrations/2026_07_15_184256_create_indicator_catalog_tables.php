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
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->string('theme');
            $table->string('unit');
            $table->string('direction');
            $table->string('periodicity');
            $table->string('aggregation_method')->default('value');
            $table->boolean('is_derived')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['theme', 'is_active']);
        });

        Schema::create('indicator_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('formula')->nullable();
            $table->text('methodology_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['indicator_id', 'version']);
        });

        Schema::create('indicator_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_indicator_version_id')->constrained('indicator_versions')->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['indicator_version_id', 'depends_on_indicator_version_id'], 'indicator_dependency_unique');
        });

        Schema::create('reference_series', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('unit');
            $table->text('source_url')->nullable();
            $table->timestamps();
        });

        Schema::create('reference_series_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_series_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');
            $table->decimal('value', 24, 8);
            $table->string('release_version')->default('initial');
            $table->timestamps();

            $table->unique(['reference_series_id', 'reference_date', 'release_version'], 'reference_series_value_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_series_values');
        Schema::dropIfExists('reference_series');
        Schema::dropIfExists('indicator_dependencies');
        Schema::dropIfExists('indicator_versions');
        Schema::dropIfExists('indicators');
    }
};
