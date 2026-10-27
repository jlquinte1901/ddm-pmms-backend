<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One table shared by both modules: a document with no `project_id` is a
     * DDM document, a document with a `project_id` is a PMMS supporting
     * document. The current version is the row where `version_number` equals
     * `latest_version_number`, which avoids a circular foreign key with
     * document_versions. `access_level` is the per-document override of the
     * category default and holds one of: open, restricted, confidential.
     * Archiving (`is_archived`) is not deletion; `deleted_at` is soft delete.
     * FULLTEXT search indexing is added later with the search feature.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('document_categories');
            $table->foreignId('project_id')->nullable()->constrained('projects');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('archived_by')->nullable()->constrained('users');
            $table->string('title');
            $table->string('reference_no', 100)->nullable();
            $table->date('document_date')->nullable();
            $table->text('description')->nullable();
            $table->text('remarks')->nullable();
            $table->string('access_level', 20)->nullable();
            $table->unsignedInteger('latest_version_number')->default(1);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('reference_no');
            $table->index('document_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
