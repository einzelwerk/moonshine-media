<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Fields\Concerns;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\MoonShine\Library\LibraryView;
use Illuminate\Container\Container;
use Throwable;

/**
 * What one place of a form takes, shared by every field that opens the
 * library: one picture and the pictures a record owns ask the very same
 * question and have to get the very same answer.
 *
 * A declaration only ever narrows the allowlist of `cms-media.kinds`, see
 * {@see \Ewk\Cms\Media\Upload\UploadOptions}: the grid of the dialog shows
 * these, the file dialog offers their extensions, and an upload of anything
 * else is refused by the server with 415. Nothing here is a rule — the rule
 * is the configuration, and the browser is told about it as a courtesy.
 */
trait NarrowsToKinds
{
    /**
     * What a field takes when it says nothing: pictures and the icons that
     * stand in for them. A video or a document is a deliberate choice of the
     * schema and never a default.
     *
     * @var list<string>
     */
    private const array DEFAULT_KINDS = ['image', 'vector'];

    /** @var list<string> */
    protected array $kinds = self::DEFAULT_KINDS;

    /**
     * @param  array<array-key, string>  $kinds
     */
    public function kinds(array $kinds): static
    {
        $clean = [];

        foreach ($kinds as $kind) {
            $known = FileKind::tryFrom(trim($kind));

            if ($known instanceof FileKind && ! in_array($known->value, $clean, true)) {
                $clean[] = $known->value;
            }
        }

        // A narrowing that names nothing the library knows narrows to nothing,
        // and a field that takes nothing at all is a field nobody can fill.
        $this->kinds = $clean === [] ? self::DEFAULT_KINDS : $clean;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getKinds(): array
    {
        return $this->kinds;
    }

    /**
     * The extensions of those kinds, out of the very allowlist the upload is
     * checked against, so a configuration that narrows a kind narrows this
     * too.
     */
    protected function acceptAttribute(): string
    {
        try {
            /** @var LibraryView $library */
            $library = Container::getInstance()->make(LibraryView::class);

            return $library->accept(array_values(array_filter(
                array_map(static fn (string $kind): ?FileKind => FileKind::tryFrom($kind), $this->kinds),
            )));
        } catch (Throwable) {
            // No configuration of the library to read — the dialog of the
            // system then offers everything and the server still refuses it.
            return '';
        }
    }
}
