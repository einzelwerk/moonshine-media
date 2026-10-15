<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_media_id')->constrained('cms_media')->cascadeOnDelete();
            $table->string('usable_type');
            $table->unsignedBigInteger('usable_id');
            $table->string('field');
            $table->string('path');

            $table->index(['usable_type', 'usable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media_usages');
    }
};
