<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Upload;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;
use enshrined\svgSanitize\Sanitizer;
use Ewk\Cms\Media\Exceptions\UnsupportedFileException;
use Throwable;

/**
 * A vector is text, and text is the one upload that a browser will happily
 * execute. So it is not stored as it arrived: it is parsed, run through
 * `enshrined/svg-sanitize`, and then swept a second time by hand for the few
 * things a picture never needs and an attack always does.
 *
 * The second pass is not distrust of the library. It is the part of the
 * refusal that belongs to us: a vector in the media library is decoration, so
 * it may point at nothing outside itself, and `foreignObject`, which carries
 * arbitrary HTML into an image, has no place in one.
 *
 * Nothing here ever resolves an external entity: no parse is given
 * `LIBXML_NOENT`, `LIBXML_NONET` is on everywhere, and a document that
 * declares entities at all is refused before a parser is handed it.
 */
final class SvgSanitizer
{
    /** Elements that are dropped whole, whatever namespace they came in. */
    private const array FORBIDDEN_ELEMENTS = ['script', 'foreignobject'];

    /** Elements whose reference has to stay inside the document. */
    private const array REFERENCING_ELEMENTS = ['use', 'image'];

    /** XPath 1.0 has no `lower-case()`, so a case fold is a `translate()`. */
    private const string UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const string LOWER = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * Whether these bytes really are a vector. `finfo` cannot say so: it
     * reports an SVG as XML, as plain text or even as HTML depending on how
     * the file opens, so the document itself is asked instead.
     */
    public function looksLikeSvg(string $contents): bool
    {
        $document = self::parse($contents);

        if (! $document instanceof DOMDocument) {
            return false;
        }

        $root = $document->documentElement;

        return $root instanceof DOMElement && strtolower((string) $root->localName) === 'svg';
    }

    /**
     * The bytes that are going to be stored, or a refusal.
     */
    public function sanitize(string $contents): string
    {
        // Read as a document before the library is handed anything. The
        // library parses with the plain flags of libxml, so the refusal of a
        // document that declares entities has to happen on this side of it,
        // and it must not depend on a caller having asked `looksLikeSvg()`
        // first.
        if (! self::parse($contents) instanceof DOMDocument) {
            throw UnsupportedFileException::forSvg();
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(false);

        try {
            $clean = $sanitizer->sanitize($contents);
        } catch (Throwable) {
            throw UnsupportedFileException::forSvg();
        }

        if (! is_string($clean) || trim($clean) === '') {
            throw UnsupportedFileException::forSvg();
        }

        return $this->sweep($clean);
    }

    /**
     * The size of the drawing, when it states one in plain numbers. An SVG
     * sized in percent or in centimetres has no pixel size to record, and a
     * made up one would be worse than none.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public function dimensions(string $contents): array
    {
        $document = self::parse($contents);
        $root = $document?->documentElement;

        if (! $root instanceof DOMElement) {
            return [null, null];
        }

        $width = self::number($root->getAttribute('width'));
        $height = self::number($root->getAttribute('height'));

        if ($width !== null && $height !== null) {
            return [$width, $height];
        }

        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')), -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($viewBox) && count($viewBox) === 4) {
            return [self::number($viewBox[2]), self::number($viewBox[3])];
        }

        return [null, null];
    }

    /**
     * The hand sweep after the library: a script the sanitiser was configured
     * to keep, an event handler, an HTML island, a reference that leaves the
     * document and a link that runs code.
     */
    private function sweep(string $clean): string
    {
        $document = self::parse($clean);

        if (! $document instanceof DOMDocument) {
            throw UnsupportedFileException::forSvg();
        }

        $xpath = new DOMXPath($document);

        foreach (self::FORBIDDEN_ELEMENTS as $name) {
            self::remove($xpath, sprintf('//*[translate(local-name(), %s, %s) = "%s"]', "'".self::UPPER."'", "'".self::LOWER."'", $name));
        }

        foreach (self::elements($xpath, '//*') as $element) {
            self::sweepAttributes($element);
        }

        $document->formatOutput = false;
        $saved = $document->saveXML();

        if (! is_string($saved) || trim($saved) === '') {
            throw UnsupportedFileException::forSvg();
        }

        return $saved;
    }

    private static function sweepAttributes(DOMElement $element): void
    {
        $name = strtolower((string) $element->localName);

        $map = $element->attributes;

        // Collected first: removing an attribute while walking the live map
        // shifts the indexes under the walk.
        $attributes = [];

        for ($index = 0; $index < $map->length; $index++) {
            $attribute = $map->item($index);

            if ($attribute instanceof DOMAttr) {
                $attributes[] = $attribute;
            }
        }

        foreach ($attributes as $attribute) {
            $attributeName = strtolower((string) $attribute->localName);
            $value = trim($attribute->value);

            // Any handler, whatever it is called: a picture has no events.
            if (str_starts_with($attributeName, 'on')) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if ($attributeName !== 'href') {
                continue;
            }

            // A reference of `use` or `image` stays inside the document; a
            // link never carries a scheme that runs code.
            if (in_array($name, self::REFERENCING_ELEMENTS, true) && ! str_starts_with($value, '#')) {
                $element->parentNode?->removeChild($element);

                return;
            }

            if (str_starts_with(strtolower(preg_replace('/\s+/', '', $value) ?? $value), 'javascript:')) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $expression): array
    {
        $found = $xpath->query($expression);
        $elements = [];

        if ($found === false) {
            return [];
        }

        foreach ($found as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function remove(DOMXPath $xpath, string $expression): void
    {
        foreach (self::elements($xpath, $expression) as $element) {
            $element->parentNode?->removeChild($element);
        }
    }

    /**
     * Parsed with the network off and entity substitution never asked for. A
     * document that declares entities of its own is not parsed at all: the
     * billion laughs and the file reading entity both live in that subset,
     * and a drawing has no use for one.
     */
    private static function parse(string $contents): ?DOMDocument
    {
        if (self::declaresEntities($contents)) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || ! $document->documentElement instanceof DOMElement) {
            return null;
        }

        if ($document->doctype !== null && str_contains(strtoupper((string) $document->doctype->internalSubset), '<!ENTITY')) {
            return null;
        }

        return $document;
    }

    private static function declaresEntities(string $contents): bool
    {
        $head = substr($contents, 0, 8192);

        return str_contains(strtoupper($head), '<!ENTITY');
    }

    private static function number(string $value): ?int
    {
        $value = trim($value);

        return is_numeric($value) && (float) $value > 0 ? (int) round((float) $value) : null;
    }
}
