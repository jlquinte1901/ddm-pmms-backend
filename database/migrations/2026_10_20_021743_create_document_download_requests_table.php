<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `status` holds one of: pending, approved, denied, cancelled. Whether an
     * approval has expired is derived from `expires_at`, never stored, and a
     * requester may hold at most one pending request per document. The
     * (document_id, status, expires_at) index serves both checks plus the
     * download eligibility lookup.
     */
    public function up(): void
    {
        Schema::create('document_download_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_download_requests');
    }
};
