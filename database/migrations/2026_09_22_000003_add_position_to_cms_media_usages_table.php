<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_media_usages', function (Blueprint $table): void {
            // Ordered attachments of the second stage (a gallery of a venue).
            // Blocks leave it empty: their order is the path inside the
            // content, not a number of their own.
            $table->unsignedInteger('position')->nullable();

            // «Where is this file used» reads by the file. MariaDB creates an
            // index for the foreign key on its own and sqlite does not, so the
            // panel gets the same plan on both only when it is declared.
            $table->index('cms_media_id');
        });
    }

    public function down(): void
    {
        // MariaDB folds the index it created for the foreign key into the
        // explicit one declared above, so that index is the one the constraint
        // now relies on and refuses to be dropped. The key is taken off and put
        // back around it; MariaDB then builds its own index again and the table
        // is the one that was there before.
        Schema::table('cms_media_usages', function (Blueprint $table): void {
            $table->dropForeign(['cms_media_id']);
        });

        Schema::table('cms_media_usages', function (Blueprint $table): void {
            $table->dropIndex(['cms_media_id']);
            $table->dropColumn('position');
        });

        Schema::table('cms_media_usages', function (Blueprint $table): void {
            $table->foreign('cms_media_id')->references('id')->on('cms_media')->cascadeOnDelete();
        });
    }
};
