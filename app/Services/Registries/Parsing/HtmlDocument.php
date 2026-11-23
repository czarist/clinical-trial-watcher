<?php

declare(strict_types=1);

namespace App\Services\Registries\Parsing;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Read-only XPath access to an HTML page. Parsers of scraped registries keep their selectors
 * in their own class and are tested against recorded pages; fetching is done elsewhere.
 */
final readonly class HtmlDocument
{
    private DOMXPath $xpath;

    public function __construct(string $html, ?string $encoding = null)
    {
        $html = self::toUtf8($html, $encoding);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Non-ASCII characters become entities, so a charset declared in the page cannot make
        // libxml decode the converted text a second time.
        $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($document);
    }

    /** Text of the first node matching the expression, with whitespace collapsed, or null. */
    public function text(string $expression, ?DOMNode $context = null): ?string
    {
        $node = $this->xpath->query($expression, $context)?->item(0);

        return $node !== null ? self::clean($node->textContent) : null;
    }

    /**
     * @return list<string> text of every matching node, empty ones left out
     */
    public function texts(string $expression, ?DOMNode $context = null): array
    {
        $texts = [];

        foreach ($this->nodes($expression, $context) as $node) {
            $text = self::clean($node->textContent);

            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @return list<DOMNode>
     */
    public function nodes(string $expression, ?DOMNode $context = null): array
    {
        $result = $this->xpath->query($expression, $context);

        return $result === false ? [] : iterator_to_array($result, false);
    }

    /**
     * Values of a two-column layout (a th or dt label followed by its value), keyed by label.
     *
     * @return array<string, string>
     */
    public function labelledValues(string $rowExpression = '//tr[th and td] | //dl/dt'): array
    {
        $values = [];

        foreach ($this->nodes($rowExpression) as $row) {
            $isTerm = $row->nodeName === 'dt';
            $label = $isTerm ? self::clean($row->textContent) : $this->text('./th', $row);
            $value = $isTerm ? $this->text('following-sibling::dd[1]', $row) : $this->text('./td', $row);

            if ($label !== null && $value !== null) {
                $values[rtrim($label, ': ')] ??= $value;
            }
        }

        return $values;
    }

    private static function clean(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : $text;
    }

    private static function toUtf8(string $html, ?string $encoding): string
    {
        $encoding ??= preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $match) === 1 ? $match[1] : 'UTF-8';

        return strtoupper($encoding) === 'UTF-8' ? $html : (string) mb_convert_encoding($html, 'UTF-8', $encoding);
    }
}
