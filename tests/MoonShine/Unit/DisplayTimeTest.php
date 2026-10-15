<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Ewk\Cms\Media\MoonShine\Support\DisplayTime;

it('reads an instant on the zone of the application unless the panel names one', function (): void {
    $instant = CarbonImmutable::parse('2026-07-14 22:30:00', 'UTC');

    config()->set('app.timezone', 'UTC');
    config()->set('moonshine-media.display_timezone', null);

    expect(DisplayTime::local($instant)->format('Y-m-d H:i'))->toBe('2026-07-14 22:30');

    config()->set('moonshine-media.display_timezone', 'Europe/Berlin');

    expect(DisplayTime::local($instant)->format('Y-m-d H:i'))->toBe('2026-07-15 00:30')
        ->and(DisplayTime::local($instant)->equalTo($instant))->toBeTrue();
});
