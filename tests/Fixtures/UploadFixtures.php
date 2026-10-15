<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The files the upload suite feeds the pipeline.
 *
 * Every one of them is a real file, because the pipeline reads bytes: the
 * type of an upload is taken from `finfo` on the temporary file, so a fake
 * that only reports a type would prove nothing.
 */
final class UploadFixtures
{
    public static function path(string $name): string
    {
        $path = __DIR__.'/'.$name;

        if (! is_file($path)) {
            throw new RuntimeException("Missing upload fixture [{$name}].");
        }

        return $path;
    }

    public static function contents(string $name): string
    {
        return (string) file_get_contents(self::path($name));
    }

    /**
     * A fixture offered under a name of its own, which is how a client name
     * that lies about its contents is built.
     */
    public static function upload(string $name, ?string $clientName = null): UploadedFile
    {
        return self::bytes(self::contents($name), $clientName ?? $name);
    }

    public static function bytes(string $binary, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cms-media-');

        if ($path === false) {
            throw new RuntimeException('No temporary file for the upload fixture.');
        }

        file_put_contents($path, $binary);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * A generated picture: the factory of the framework writes a real JPEG or
     * PNG, decided by the extension of the name.
     */
    public static function image(string $clientName, int $width = 40, int $height = 30): UploadedFile
    {
        return UploadedFile::fake()->image($clientName, $width, $height);
    }
}
