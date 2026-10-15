<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Exceptions\DuplicateFolderNameException;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * The refusal of two folders with one name under one parent.
 *
 * The unique index of the schema says the same thing, but it says nothing at
 * the root: both MariaDB and sqlite count NULL parents as distinct, so the
 * top of the tree would accept the duplicate the rest of it refuses. The
 * check therefore lives here, where both levels look the same.
 *
 * Names are compared folded, because MariaDB folds them in its index and
 * sqlite does not: without the folding, «Афиши» and «афиши» would be one
 * folder in production and two in a test.
 *
 * The folding here is case only, while the index carries `utf8mb4_unicode_ci`,
 * which also folds accents. «Cafe» beside «Café» therefore passes this check
 * and is refused by the index instead. Widening the comparison would mean
 * reimplementing a collation in PHP, so the write itself runs inside
 * {@see self::guardUnique()}, which turns that violation into the very same
 * sentence: an editor is told the name is taken either way, and never shown a
 * query error.
 */
final class FolderSiblings
{
    private function __construct()
    {
    }

    /**
     * Run a write and answer a unique violation with the refusal an editor
     * reads.
     *
     * The pre-check above cannot be the only guard: the index folds more than
     * PHP does, and two people can also pass the check at the same moment and
     * write afterwards. Only a violation of uniqueness is translated; anything
     * else the database says is a fault and is left to travel.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    public static function guardUnique(string $name, callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $exception) {
            if (self::isUniqueViolation($exception)) {
                throw DuplicateFolderNameException::forName($name, $exception);
            }

            throw $exception;
        }
    }

    /**
     * MariaDB and MySQL answer SQLSTATE 23000 with 1062; sqlite answers 19,
     * which is every constraint it has, so the sentence it carries is what
     * tells a unique index from a missing column.
     */
    private static function isUniqueViolation(Throwable $exception): bool
    {
        $errorInfo = $exception instanceof QueryException ? $exception->errorInfo : null;
        $state = $errorInfo[0] ?? null;
        $code = $errorInfo[1] ?? null;
        $sqlState = is_scalar($state) ? (string) $state : (string) $exception->getCode();
        $driverCode = is_numeric($code) ? (int) $code : 0;

        return match (true) {
            $sqlState === '23000' && $driverCode === 1062 => true,
            $driverCode === 19 && str_contains($exception->getMessage(), 'UNIQUE constraint failed') => true,
            default => false,
        };
    }

    public static function assertNameIsFree(?CmsMediaFolder $parent, string $name, ?int $exceptId = null): void
    {
        $folded = mb_strtolower($name);

        $taken = CmsMediaFolder::query()
            ->when(
                $parent instanceof CmsMediaFolder,
                static fn ($query) => $query->where('parent_id', $parent?->id),
                static fn ($query) => $query->whereNull('parent_id'),
            )
            ->when($exceptId !== null, static fn ($query) => $query->whereKeyNot($exceptId))
            ->get(['name']);

        foreach ($taken as $sibling) {
            if (mb_strtolower($sibling->name) === $folded) {
                throw DuplicateFolderNameException::forName($name);
            }
        }
    }
}
