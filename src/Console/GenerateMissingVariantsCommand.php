<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Console;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Upload\VariantQueue;
use Illuminate\Console\Command;

/**
 * Puts in line the variants the library lacks: the ones of a preset declared
 * after its files were uploaded, the frames of the videos uploaded before
 * ffmpeg was installed, and the ones that failed. Running it twice puts
 * nothing in line the second time.
 */
final class GenerateMissingVariantsCommand extends Command
{
    protected $signature = 'cms-media:variants {--kind= : only files of this kind, e.g. video}';

    protected $description = 'Generate the missing variants of the media library';

    public function handle(VariantQueue $variants): int
    {
        $option = $this->option('kind');
        $kind = is_string($option) && $option !== '' ? FileKind::tryFrom($option) : null;

        if (is_string($option) && $option !== '' && ! $kind instanceof FileKind) {
            $this->error("Unknown kind [{$option}].");

            return self::FAILURE;
        }

        $query = CmsMedia::query();

        if ($kind instanceof FileKind) {
            $query->where('kind', $kind->value);
        }

        $count = 0;

        $query->chunkById(100, function ($records) use ($variants, &$count): void {
            foreach ($records as $media) {
                $count += $variants->createMissing($media);
            }
        });

        $this->info("Variants put in line: {$count}.");

        return self::SUCCESS;
    }
}
