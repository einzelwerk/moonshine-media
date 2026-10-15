<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media_folders', function (Blueprint $table): void {
            $table->id();

            // The cascade never fires in practice: deleting a folder that
            // still holds anything is refused by the action. The constraint is
            // here so that a row orphaned by hand cannot survive the parent.
            $table->foreignId('parent_id')->nullable()->constrained('cms_media_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            // Two folders with the same name under the same parent would be
            // indistinguishable in the tree. The index does not cover the root
            // level, because both MariaDB and sqlite treat NULL parents as
            // distinct; the folder actions check that case themselves.
            $table->unique(['parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media_folders');
    }
};
