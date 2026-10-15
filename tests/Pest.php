<?php

declare(strict_types=1);

use Ewk\Cms\Media\MoonShine\Library\PickerModal;
use Ewk\Cms\Media\Tests\MoonShine\TestCase as MoonShineTestCase;
use Ewk\Cms\Media\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(MoonShineTestCase::class)->in('MoonShine');

/**
 * The rows of an index page: the panel loads its table as a component, so
 * the initial document carries the shell and a skeleton only.
 */
function indexRows(\MoonShine\Laravel\Resources\ModelResource $resource): \Illuminate\Testing\TestResponse
{
    $index = $resource->getIndexPage();

    if ($index === null) {
        throw new RuntimeException('The resource registers no index page.');
    }

    $url = $resource->getCore()->getRouter()->getEndpoints()->component(
        name: $index->getListComponentName(),
        additionally: [
            '_fragment-load' => 'crud-list',
            'pageUri' => $index->getUriKey(),
            'resourceUri' => $resource->getUriKey(),
        ],
    );

    return test()->get($url);
}

/**
 * Submit a form of the panel the way the panel does it: over ajax, so that a
 * refusal comes back as 422 with the errors by field.
 *
 * @param  array<string, mixed>  $data
 */
function submitForm(string $url, array $data, string $method = 'POST'): \Illuminate\Testing\TestResponse
{
    return test()->call($method, $url, $data, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]);
}

/**
 * The addresses the fields hand to the client travel inside json, so their
 * slashes arrive escaped, once or twice over depending on how deep the json
 * sits. A test asks about the address and not about the escaping.
 */
function plainUrls(string $html): string
{
    return str_replace('\\', '', $html);
}

/**
 * How many times the page carries the dialog of the media picker.
 */
function pickerDialogCount(string $html): int
{
    return substr_count($html, 'modal_toggled:'.PickerModal::NAME);
}
