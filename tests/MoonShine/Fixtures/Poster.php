<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A record that keeps references to pictures in columns of its own — a
 * banner, a card picture, a slider — standing in for a model of the
 * installation whose form carries {@see \Ewk\Cms\Media\MoonShine\Fields\MediaPicker}.
 *
 * @property int $id
 * @property string|null $title
 * @property array<string, mixed>|null $image
 * @property array<string, mixed>|null $og_image
 * @property list<array<string, mixed>>|null $slides
 */
final class Poster extends Model
{
    public $timestamps = false;

    protected $table = 'test_posters';

    protected $fillable = ['title', 'image', 'og_image', 'slides'];

    /** @var array<string, string> */
    protected $casts = [
        'image' => 'array',
        'og_image' => 'array',
        'slides' => 'array',
    ];
}
