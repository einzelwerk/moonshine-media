<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Enums\FileKind;

/**
 * What one kind of file is allowed to be.
 *
 * Extensions are kept lower case so that a comparison never has to remember
 * to fold the case of a client name; MIME types are the ones `finfo` may
 * report for those bytes.
 */
final readonly class KindRule
{
    /**
     * @param  list<string>  $extensions
     * @param  list<string>  $mimes
     * @param  int  $maxSize  bytes
     */
    public function __construct(
        public FileKind $kind,
        public array $extensions,
        public array $mimes,
        public int $maxSize,
    ) {
    }

    public function allowsExtension(string $extension): bool
    {
        return in_array(strtolower($extension), $this->extensions, true);
    }

    public function allowsMime(string $mime): bool
    {
        return in_array(KindRegistry::normalizeMime($mime), $this->mimes, true);
    }
}
