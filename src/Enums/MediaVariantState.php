<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Enums;

enum MediaVariantState: string
{
    case PENDING = 'pending';
    case READY = 'ready';
    case FAILED = 'failed';
}
