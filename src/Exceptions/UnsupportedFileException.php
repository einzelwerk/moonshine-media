<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use Ewk\Cms\Media\Enums\FileKind;

/**
 * The file is not one the library stores: its extension is outside the
 * allowlist, its bytes are not what the extension promised, its SVG could not
 * be made safe, or it is offered as a replacement for a file of another kind.
 *
 * `refusedKind` is filled only in the one case where the file itself is fine
 * and merely belongs elsewhere; a replacement reads it to say «the same type»
 * instead of repeating the whole list of formats.
 */
final class UnsupportedFileException extends UploadRejectedException
{
    public function __construct(string $message, public readonly ?FileKind $refusedKind = null)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 415;
    }

    /**
     * @param  list<string>  $allowedExtensions
     */
    public static function forExtension(array $allowedExtensions, ?FileKind $refusedKind = null): self
    {
        $allowed = self::allowed($allowedExtensions);

        return new self(
            self::translate(
                'unsupported_type',
                ['allowed' => $allowed],
                "This format is not supported. Try one of: {$allowed}.",
            ),
            $refusedKind,
        );
    }

    /**
     * The bytes are not what the name promised. Nothing is guessed from them:
     * a file that says one thing and is another is refused, see the OWASP
     * upload cheat sheet.
     */
    public static function forContent(): self
    {
        return new self(self::translate(
            'type_mismatch',
            [],
            'The contents of the file do not match its extension. Upload a real file of that format.',
        ));
    }

    public static function forSvg(): self
    {
        return new self(self::translate(
            'svg_rejected',
            [],
            'This SVG could not be cleaned up safely. Export it from your editor again.',
        ));
    }

    public static function forReplacement(): self
    {
        return new self(self::translate(
            'replace_kind_mismatch',
            [],
            'A file can only be replaced with a file of the same type.',
        ));
    }

    /**
     * AVIF is in the allowlist, but a PHP build without it in GD cannot open
     * one. The editor is told what to do instead of being shown a decoder
     * error.
     */
    public static function forAvif(): self
    {
        return new self(self::translate(
            'avif_unsupported',
            [],
            'AVIF is not supported here yet. Save the picture as JPG, PNG or WebP.',
        ));
    }

    /**
     * @param  list<string>  $extensions
     */
    private static function allowed(array $extensions): string
    {
        return implode(', ', array_map(strtoupper(...), $extensions));
    }
}
