<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Immutable, append-only versions: rows are never updated or deleted and
     * there is no overwrite endpoint. `storage_path` is a random generated
     * private path, never built from user input, and must not be exposed by
     * the API. The (document_id, checksum_sha256) index serves the rule that
     * an upload may not repeat the current version's checksum.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->unsignedInteger('version_number');
            $table->string('original_name');
            $table->string('storage_disk', 50);
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum_sha256', 64);
            $table->string('change_note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'version_number']);
            $table->index(['document_id', 'checksum_sha256']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
