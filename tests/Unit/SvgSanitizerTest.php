<?php

declare(strict_types=1);

use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Ewk\Cms\Media\Tests\Fixtures\UploadFixtures;
use Ewk\Cms\Media\Upload\SvgSanitizer;

it('recognises a vector by its document and not by its type', function (): void {
    $sanitizer = new SvgSanitizer();

    expect($sanitizer->looksLikeSvg(UploadFixtures::contents('clean.svg')))->toBeTrue()
        ->and($sanitizer->looksLikeSvg(UploadFixtures::contents('hostile.svg')))->toBeTrue()
        ->and($sanitizer->looksLikeSvg(UploadFixtures::contents('php-script.txt')))->toBeFalse()
        ->and($sanitizer->looksLikeSvg('<html><body>hello</body></html>'))->toBeFalse()
        ->and($sanitizer->looksLikeSvg(''))->toBeFalse();
});

it('refuses a document that declares entities of its own', function (): void {
    $sanitizer = new SvgSanitizer();

    expect($sanitizer->looksLikeSvg(UploadFixtures::contents('entities.svg')))->toBeFalse()
        ->and($sanitizer->looksLikeSvg(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "b">]><svg xmlns="http://www.w3.org/2000/svg"/>'
        ))->toBeFalse();
});

it('takes the script, the handlers, the html island and the outside references out', function (): void {
    $clean = (new SvgSanitizer())->sanitize(UploadFixtures::contents('hostile.svg'));

    expect($clean)->toContain('<svg')
        ->and($clean)->not->toContain('<script')
        ->and($clean)->not->toContain('foreignObject')
        ->and($clean)->not->toContain('onload')
        ->and($clean)->not->toContain('onclick')
        ->and($clean)->not->toContain('javascript:')
        ->and($clean)->not->toContain('example.com');
});

it('keeps a reference that stays inside the document', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
        .'<defs><rect id="box" width="4" height="4"/></defs><use href="#box"/></svg>';

    expect((new SvgSanitizer())->sanitize($svg))->toContain('#box');
});

it('refuses bytes it cannot read as a document', function (): void {
    expect(fn () => (new SvgSanitizer())->sanitize('not xml at all'))
        ->toThrow(UnsupportedFileException::class);
});

it('reads the size from plain numbers and gives none for anything else', function (): void {
    $sanitizer = new SvgSanitizer();

    expect($sanitizer->dimensions(UploadFixtures::contents('clean.svg')))->toBe([48, 24])
        ->and($sanitizer->dimensions('<svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 32 16"/>'))
        ->toBe([32, 16])
        ->and($sanitizer->dimensions('<svg xmlns="http://www.w3.org/2000/svg" width="10cm" height="4cm"/>'))
        ->toBe([null, null]);
});

/**
 * One case per trick a hostile vector is built out of. They are written here
 * and not kept as fixture files, because what each one tries is the point and
 * a file named `hostile-7.svg` hides it.
 */
it('takes every way a vector carries code or reaches outside', function (string $svg, string $gone): void {
    $clean = (new SvgSanitizer())->sanitize($svg);

    expect($clean)->toContain('<svg')
        ->and(strtolower($clean))->not->toContain(strtolower($gone));
})->with([
    'script wrapped in cdata' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script><![CDATA[alert(1)]]></script></svg>',
        'alert',
    ],
    'an element the sanitiser folds the case of' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><SCRIPT>alert(1)</SCRIPT></svg>',
        'alert',
    ],
    'an html island' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><foreignObject width="5" height="5">'
        .'<body xmlns="http://www.w3.org/1999/xhtml"><b>text</b></body></foreignObject></svg>',
        'foreignObject',
    ],
    'an iframe' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><iframe src="https://evil.test/"></iframe></svg>',
        'iframe',
    ],
    'a handler on an animation' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="5" height="5">'
        .'<animate attributeName="x" from="0" to="1" onbegin="alert(1)"/></rect></svg>',
        'onbegin',
    ],
    'a link whose scheme runs code, however it is spelled' => [
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10">'
        .'<a xlink:href="jAvAsCrIpT:alert(1)"><rect width="5" height="5"/></a></svg>',
        'javascript:',
    ],
    'a reference that leaves the document' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><use href="https://evil.test/x.svg#a"/></svg>',
        'evil.test',
    ],
    'an image pulled from another host' => [
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10">'
        .'<image xlink:href="https://evil.test/tracker.png" width="5" height="5"/></svg>',
        'evil.test',
    ],
    'an image that is really a document' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
        .'<image href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==" width="5" height="5"/></svg>',
        'data:text/html',
    ],
    'a stylesheet fetched from another host' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
        .'<style>@import url("https://evil.test/x.css");</style><rect width="5" height="5"/></svg>',
        'evil.test',
    ],
    'a fill painted from another host' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
        .'<style>rect{fill:url("https://evil.test/p.svg#g")}</style><rect width="5" height="5"/></svg>',
        'evil.test',
    ],
    'a stylesheet the document asks a processor for' => [
        '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="https://evil.test/x.xsl"?>'
        .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>',
        'evil.test',
    ],
    'a doctype pointing at another host' => [
        '<?xml version="1.0"?><!DOCTYPE svg SYSTEM "http://evil.test/x.dtd">'
        .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>',
        'evil.test',
    ],
]);

it('refuses a document built to be expensive to read', function (string $svg): void {
    expect(fn () => (new SvgSanitizer())->sanitize($svg))->toThrow(UnsupportedFileException::class);
})->with([
    'a file reading entity' => [
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text>&xxe;</text></svg>',
    ],
    'entities that multiply each other' => [
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text>&b;</text></svg>',
    ],
    'an entity hidden behind a long comment' => [
        '<?xml version="1.0"?><!-- '.str_repeat('x', 9000).' -->'
        .'<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text>&xxe;</text></svg>',
    ],
    'elements nested past what a reader will follow' => [
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
        .str_repeat('<g>', 3000).str_repeat('</g>', 3000).'</svg>',
    ],
]);
