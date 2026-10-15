<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Http\Resources;

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public API shape for a media reference.
 *
 * The record says what the file is; the place that uses it may say something
 * else about it. `alt` is the one property where the two meet: the value
 * stored beside the reference wins, an empty one inherits the default of the
 * record, and a language that neither of them filled falls back to the default
 * language before it gives up. Any other reading would put a description in
 * the wrong language next to a picture, which is a defect of accessibility and
 * not a fallback.
 *
 * The disk, the path and the checksum are never handed out: a reader gets an
 * address, not the layout of a storage.
 *
 * @property CmsMedia $resource
 */
final class MediaResource extends JsonResource
{
    private ?string $alt = null;

    /** @var array<array-key, mixed>|null */
    private ?array $override = null;

    private string $locale = '';

    private string $fallbackLocale = '';

    /**
     * A description that somebody else has already resolved, for a caller
     * that holds the merged value and not the two dictionaries it came from.
     */
    public function withAlt(string $alt): self
    {
        $this->alt = $alt;

        return $this;
    }

    /**
     * The metadata stored beside the reference, as a block or a metadata row
     * keeps it: `{"alt": {"ru": "…"}, "caption": {…}}`.
     *
     * @param  array<array-key, mixed>|null  $metadata
     */
    public function withOverride(?array $metadata, string $locale, string $fallbackLocale): self
    {
        $this->override = $metadata;
        $this->locale = $locale;
        $this->fallbackLocale = $fallbackLocale;
        $this->alt = null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource;

        return [
            'id' => $media->uuid,
            'url' => $media->url(),
            'name' => $media->name,
            'kind' => $media->kind->value,
            'width' => $media->width,
            'height' => $media->height,
            'mime' => $media->mime,
            'size' => $media->size,
            'alt' => $this->resolvedAlt($media),
            'placeholder' => $media->placeholder,
            'variants' => $this->variantPayloads($media),
            'pending' => $media->isPending(),
        ];
    }

    /**
     * Four places are looked at in one order and the first filled one wins:
     * the override in the requested language, the override in the default
     * language, the record in the requested language, the record in the
     * default language.
     */
    private function resolvedAlt(CmsMedia $media): ?string
    {
        if ($this->alt !== null) {
            return $this->alt;
        }

        $locales = $this->locales();

        foreach ([$this->override, $media->metadata] as $source) {
            foreach ($locales as $locale) {
                $value = self::altOf($source, $locale);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The two languages the description is read in. A caller that hydrates a
     * reference names them, because only it knows which language the answer
     * is being built for; a caller that just wraps a record gets the ones the
     * application is answering in, so that a resource made without
     * {@see self::withOverride()} still hands out the description of the
     * record instead of silently handing out none.
     *
     * @return list<string>
     */
    private function locales(): array
    {
        if ($this->locale !== '') {
            return [$this->locale, $this->fallbackLocale];
        }

        $container = Container::getInstance();

        if (! $container->bound(Repository::class)) {
            return [];
        }

        $config = $container->make(Repository::class);

        return [
            self::configString($config, 'app.locale'),
            self::configString($config, 'app.fallback_locale'),
        ];
    }

    /**
     * A missing key, a null and an empty string all mean «not filled in», so
     * an override that was left blank inherits instead of blanking the
     * default of the record.
     *
     * @param  array<array-key, mixed>|null  $source
     */
    private static function altOf(?array $source, string $locale): ?string
    {
        if ($source === null || $locale === '') {
            return null;
        }

        $alt = $source['alt'] ?? null;

        if (! is_array($alt)) {
            return null;
        }

        $value = $alt[$locale] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function variantPayloads(CmsMedia $media): array
    {
        $payloads = [];

        foreach ($media->variants as $variant) {
            $payloads[$variant->preset] ??= $this->variantPayload($media, $variant->preset);
        }

        return $payloads;
    }

    private static function configString(Repository $config, string $key): string
    {
        $value = $config->get($key, '');

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function variantPayload(CmsMedia $media, string $preset): array
    {
        $variant = $media->variants->firstWhere('preset', $preset);

        if ($variant instanceof CmsMediaVariant && $variant->path !== null) {
            return [
                'url' => $media->resolvedVariantUrl($preset),
                'width' => $variant->width,
                'height' => $variant->height,
            ];
        }

        return [
            'url' => $media->resolvedVariantUrl($preset),
            'width' => null,
            'height' => null,
        ];
    }
}
