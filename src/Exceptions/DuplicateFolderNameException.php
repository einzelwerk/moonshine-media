<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Two folders of one parent may not share a name. The unique index says so
 * everywhere but the root, where both MariaDB and sqlite count NULL parents
 * as distinct, so the refusal is owed by the action, see the test
 * `lets two folders of the root carry the same name`.
 *
 * It is also raised the other way round, out of a violation of that index,
 * see {@see \Ewk\Cms\Media\Support\FolderSiblings::guardUnique()}: the
 * collation folds more than PHP does, and the editor gets one sentence
 * whichever of the two caught it. The query error is kept as the previous
 * exception, because that is what a developer needs in a log.
 */
final class DuplicateFolderNameException extends RuntimeException
{
    public static function forName(string $name, ?Throwable $previous = null): self
    {
        return new self(
            MediaMessage::translate(
                'duplicate_folder',
                'A folder named :name already exists here.',
                ['name' => $name],
            ),
            0,
            $previous,
        );
    }

    public function status(): int
    {
        return 409;
    }
}
