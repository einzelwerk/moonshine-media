<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * How a refusal of the core of the library reaches the browser.
 *
 * Every refusal an editor can trigger carries two things: a sentence written
 * for a person and a `status()`. Reading the status off the exception instead
 * of listing the classes is the whole point of that convention: a refusal the
 * library adds later answers the browser correctly without a line changing
 * here.
 *
 * Anything without a status is not a refusal but a fault, and a fault belongs
 * in the log and in the handler of the application, so it is handed back and
 * bubbles.
 */
final class MediaRefusal
{
    private function __construct()
    {
    }

    public static function answer(Throwable $refusal): ?JsonResponse
    {
        if (! method_exists($refusal, 'status')) {
            return null;
        }

        $status = $refusal->status();

        return new JsonResponse(
            ['message' => $refusal->getMessage()],
            is_int($status) && $status >= 400 && $status < 600 ? $status : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['message' => (string) __('moonshine-media::library.messages.not_found')],
            Response::HTTP_NOT_FOUND,
        );
    }
}
