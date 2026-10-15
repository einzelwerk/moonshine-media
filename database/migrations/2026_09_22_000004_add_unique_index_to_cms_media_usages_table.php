<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per place a file stands in. A block reference is unique by its
 * dotted path, an attachment by its role, its language and the file, so the
 * same file cannot be attached twice by two concurrent saves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_media_usages', function (Blueprint $table): void {
            $table->unique(
                ['usable_type', 'usable_id', 'field', 'path', 'cms_media_id'],
                'cms_media_usages_place_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cms_media_usages', function (Blueprint $table): void {
            $table->dropUnique('cms_media_usages_place_unique');
        });
    }
};
