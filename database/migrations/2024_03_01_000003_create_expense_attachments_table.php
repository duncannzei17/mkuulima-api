<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('expense_id');
            $table->string('filename', 255);
            $table->string('original_filename', 255);
            $table->string('file_path', 500);
            $table->string('file_type', 100);
            $table->bigInteger('file_size');
            $table->enum('attachment_type', ['receipt_photo', 'receipt_document', 'invoice', 'proof_of_payment', 'delivery_note', 'other'])->default('receipt_photo');
            $table->text('description')->nullable();
            $table->integer('image_width')->nullable();
            $table->integer('image_height')->nullable();
            $table->json('exif_data')->nullable();
            $table->enum('processing_status', ['pending', 'processed', 'failed'])->default('pending');
            $table->text('processing_error')->nullable();
            $table->json('extracted_data')->nullable();
            $table->boolean('data_verified')->default(false);
            $table->uuid('uploaded_by');
            $table->boolean('is_public')->default(false);
            $table->string('cloud_url', 500)->nullable();
            $table->string('cloud_provider', 50)->nullable();
            $table->boolean('is_backed_up')->default(false);
            $table->timestamps();

            $table->foreign('expense_id')->references('id')->on('expenses')->cascadeOnDelete();
            $table->index('expense_id');
            $table->index('attachment_type');
            $table->index('uploaded_by');
            $table->index('processing_status');
            $table->index(['expense_id', 'attachment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');
    }
};
