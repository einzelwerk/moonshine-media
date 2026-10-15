<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Usages;

/**
 * One record an editor goes to, and every place inside it the file appears.
 *
 * The grouping is by the owner the registry named and not by the row: a page
 * that shows the same picture in two of its blocks is one page to open, and
 * reading its name twice tells nobody anything new. Which of its blocks they
 * are is kept in {@see UsageSource}.
 */
final readonly class UsageGroup
{
    /**
     * @param  list<UsageSource>  $sources
     */
    public function __construct(
        public UsageOwnerLabel $owner,
        public array $sources,
    ) {
    }

    /**
     * Every path of every source, flattened and deduplicated, for a consumer
     * that only wants to print «cover, items.0.image» and does not care which
     * block each one came from.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->sources as $source) {
            foreach ($source->fields as $field) {
                if (! in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }
}
