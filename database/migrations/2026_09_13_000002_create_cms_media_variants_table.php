<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_media_id')->constrained('cms_media')->cascadeOnDelete();
            $table->string('preset');
            $table->string('disk');
            $table->string('path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('format')->nullable();
            $table->string('state')->default('pending');
            $table->timestamps();

            $table->unique(['cms_media_id', 'preset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media_variants');
    }
};
