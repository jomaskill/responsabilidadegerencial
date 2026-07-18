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
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('publisher');
            $table->string('acquisition_method');
            $table->text('homepage_url');
            $table->json('configuration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('source_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('reference_year');
            $table->string('version')->default('initial');
            $table->string('status');
            $table->date('published_at')->nullable();
            $table->date('collected_at');
            $table->text('source_url')->nullable();
            $table->string('artifact_disk')->nullable();
            $table->text('artifact_path')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('source_releases')->nullOnDelete();
            $table->timestamps();

            $table->unique(['data_source_id', 'reference_year', 'version']);
            $table->index(['reference_year', 'status']);
        });

        Schema::create('processing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_release_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('input_rows')->default(0);
            $table->unsignedBigInteger('accepted_rows')->default(0);
            $table->unsignedBigInteger('rejected_rows')->default(0);
            $table->json('parameters')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['data_source_id', 'status', 'created_at']);
        });

        Schema::create('processing_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('row_number')->nullable();
            $table->string('municipality_code')->nullable();
            $table->string('indicator_slug')->nullable();
            $table->string('code');
            $table->text('message');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['processing_run_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processing_errors');
        Schema::dropIfExists('processing_runs');
        Schema::dropIfExists('source_releases');
        Schema::dropIfExists('data_sources');
    }
};
