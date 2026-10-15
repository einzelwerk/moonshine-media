<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Fields;

use Illuminate\View\ComponentAttributeBag;
use MoonShine\UI\Fields\Field;

/**
 * A field whose value is a small object and whose inputs are therefore several
 * — a link, a picture — drawn as one field with one caption.
 *
 * The one thing such a field has to get right is the name of its inputs inside
 * a repeater. MoonShine renames the fields of a `Json` row on the client: the
 * server renders `content[slides][${index0}][image]` and the reindex pass
 * replaces the placeholder with the number of the row. It only touches
 * elements that carry `data-name` and `data-level`, so every input of a
 * composite field carries them too, each with its own name — otherwise the
 * second slide would write over the first.
 *
 * Outside a repeater there is no placeholder and no `data-level`, and the
 * names are already final.
 */
abstract class CompositeValueField extends Field
{
    /**
     * The attributes one input of this field needs: its name, plus the markers
     * the repeater renames it by when the field sits in a row.
     *
     * @param  string  $suffix  e.g. `[kind]` or `[attributes][url]`
     */
    public function inputAttributes(string $suffix): ComponentAttributeBag
    {
        $name = $this->getNameAttribute().$suffix;
        $level = $this->getAttribute('data-level');

        return new ComponentAttributeBag($level === null ? ['name' => $name] : [
            'name' => $name,
            'data-name' => $name,
            'data-level' => $level,
        ]);
    }
}
