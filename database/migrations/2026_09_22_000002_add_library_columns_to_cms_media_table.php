<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_media', function (Blueprint $table): void {
            // Everything stored before the library existed was uploaded by the
            // block editor and went through the image pipeline, so the default
            // is also the backfill: no data migration is needed.
            $table->string('kind')->default('image')->index();

            // Part of the path of the file. Replacing a file keeps the record,
            // the uuid and the usages and writes the new bytes under the next
            // version, so an address never has to be invalidated in a cache.
            $table->unsignedSmallInteger('version')->default(1);

            // The panel user who uploaded the file. No foreign key: the family
            // of packages does not know the user model of the installation.
            $table->unsignedBigInteger('uploaded_by')->nullable();

            // Empty for files of administrators. When organisers start
            // uploading through the cabinet API, reading and deleting is
            // filtered by the owner: someone else's file does not exist.
            $table->nullableMorphs('owner');
        });

        // A separate statement: on sqlite a foreign key added to an existing
        // table is a rebuild of that table, and the rebuild is easier to
        // reason about when it carries nothing else.
        Schema::table('cms_media', function (Blueprint $table): void {
            $table->foreignId('folder_id')->nullable()->constrained('cms_media_folders')->nullOnDelete();
        });

        Schema::table('cms_media', function (Blueprint $table): void {
            // What the grid of one folder reads: the files of a folder, newest
            // first.
            $table->index(['folder_id', 'created_at']);
        });

        Schema::table('cms_media', function (Blueprint $table): void {
            // Folders became rows, so the string column that held a name has
            // no meaning any more. The index goes first: sqlite refuses to
            // drop a column an index still mentions.
            $table->dropIndex(['folder']);
            $table->dropColumn('folder');
        });
    }

    public function down(): void
    {
        Schema::table('cms_media', function (Blueprint $table): void {
            $table->string('folder')->nullable()->index();
        });

        // The foreign key goes first. MariaDB creates an index of its own for
        // every key and then folds it into an explicit index declared over the
        // same leading column, so `(folder_id, created_at)` is the index the
        // constraint ends up relying on and cannot be dropped while it stands.
        Schema::table('cms_media', function (Blueprint $table): void {
            $table->dropForeign(['folder_id']);
        });

        Schema::table('cms_media', function (Blueprint $table): void {
            $table->dropIndex(['folder_id', 'created_at']);
            $table->dropIndex(['kind']);
            $table->dropIndex(['owner_type', 'owner_id']);
        });

        Schema::table('cms_media', function (Blueprint $table): void {
            $table->dropColumn(['folder_id', 'kind', 'version', 'uploaded_by', 'owner_type', 'owner_id']);
        });
    }
};
