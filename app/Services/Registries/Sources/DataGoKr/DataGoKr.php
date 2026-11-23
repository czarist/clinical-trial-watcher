<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\DataGoKr;

use App\Exceptions\RegistryRequestFailed;
use DOMDocument;
use DOMXPath;

/**
 * Helpers for APIs served by the Korean Public Data Portal gateway (apis.data.go.kr).
 *
 * The gateway answers many errors with HTTP 200 and an error document, in XML or JSON
 * whatever result type was asked for: either an `OpenAPI_ServiceResponse` envelope
 * (`cmmMsgHeader.returnReasonCode`) or the service's own `header.resultCode`. An HTTP 200 is
 * therefore not proof of data: every body is checked here. "No data" (code 03) is an empty
 * result; a refused or unregistered key and an exceeded quota end the run with a message
 * that names the environment variable holding the key, never the key itself.
 */
final class DataGoKr
{
    public const string NO_DATA = '03';

    /** Access denied, key not registered, key expired, unregistered IP. */
    public const array KEY_ERRORS = ['20', '30', '31', '32'];

    /** Daily quota or per-second limit exceeded. */
    public const array QUOTA_ERRORS = ['22', '23'];

    /**
     * The service key to send. data.go.kr shows an "encoding" (URL-encoded) and a "decoding"
     * key; the request encodes the key itself, so an encoded key is decoded first.
     *
     * @throws RegistryRequestFailed when no key is configured
     */
    public static function serviceKey(?string $key, string $registry, string $variable): string
    {
        $key = trim((string) $key);

        if ($key === '') {
            throw new RegistryRequestFailed("The {$registry} importer needs a data.go.kr service key: set {$variable}.");
        }

        return str_contains($key, '%') ? rawurldecode($key) : $key;
    }

    /**
     * The items of a data.go.kr response, its total count and its body object.
     *
     * Accepted shapes: `{header, body}`, `{response: {header, body}}` and a flat object with
     * `resultCode` beside the paging fields; `items` may be a list, `{item: list|object}` or
     * empty.
     *
     * @return array{items: list<array<string, mixed>>, totalCount: int, body: array<string, mixed>}
     *
     * @throws RegistryRequestFailed when the body is an error or not a data.go.kr result
     */
    public static function parse(string $body, string $registry, string $variable): array
    {
        $body = trim($body);

        if (str_starts_with($body, '<')) {
            self::failFromXml($body, $registry, $variable);
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw RegistryRequestFailed::invalidPayload();
        }

        if (! is_array($data)) {
            throw RegistryRequestFailed::invalidPayload();
        }

        if (is_array($data['OpenAPI_ServiceResponse'] ?? null)) {
            $header = is_array($data['OpenAPI_ServiceResponse']['cmmMsgHeader'] ?? null) ? $data['OpenAPI_ServiceResponse']['cmmMsgHeader'] : [];

            throw self::failure(
                self::code($header['returnReasonCode'] ?? null) ?? '99',
                $header['returnAuthMsg'] ?? $header['errMsg'] ?? null,
                $registry,
                $variable,
            );
        }

        $root = is_array($data['response'] ?? null) ? $data['response'] : $data;
        $header = is_array($root['header'] ?? null) ? $root['header'] : $root;
        $code = self::code($header['resultCode'] ?? null);

        if ($code === null) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $content = is_array($root['body'] ?? null) ? $root['body'] : $root;

        if ($code === self::NO_DATA) {
            return ['items' => [], 'totalCount' => 0, 'body' => $content];
        }

        if ($code !== '00') {
            throw self::failure($code, $header['resultMsg'] ?? null, $registry, $variable);
        }

        $items = self::items($content['items'] ?? ($content['item'] ?? null));
        $total = $content['totalCount'] ?? null;

        if ($total !== null && ! is_int($total) && ! (is_string($total) && ctype_digit($total))) {
            throw RegistryRequestFailed::invalidPayload();
        }

        return ['items' => $items, 'totalCount' => $total === null ? count($items) : (int) $total, 'body' => $content];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        if (array_key_exists('item', $items)) {
            return self::items(is_array($items['item']) && ! array_is_list($items['item']) ? [$items['item']] : $items['item']);
        }

        if (! array_is_list($items)) {
            return [$items];
        }

        return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item) && ! array_is_list($item)));
    }

    /**
     * An XML body: the gateway's error envelope or the service's error header. JSON is always
     * asked for, so an XML body that is not an error is not a valid result either.
     *
     * @throws RegistryRequestFailed
     */
    private static function failFromXml(string $xml, string $registry, string $variable): never
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $xpath = new DOMXPath($document);
        $text = static fn (string $name): ?string => ($node = $xpath->query("//*[local-name() = '{$name}']")->item(0)) !== null
            ? trim($node->textContent)
            : null;

        $code = self::code($text('returnReasonCode') ?? $text('resultCode'));

        if ($code === null || $code === '00') {
            throw RegistryRequestFailed::invalidPayload();
        }

        if ($code === self::NO_DATA) {
            // Only reached for XML; callers asked for JSON, so treat it as an unexpected format.
            throw RegistryRequestFailed::invalidPayload();
        }

        throw self::failure($code, $text('returnAuthMsg') ?? $text('errMsg') ?? $text('resultMsg'), $registry, $variable);
    }

    private static function failure(string $code, mixed $message, string $registry, string $variable): RegistryRequestFailed
    {
        $message = is_scalar($message) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $message))), 0, 120) : '';
        $detail = $message === '' ? "code {$code}" : "code {$code}: {$message}";

        return match (true) {
            in_array($code, self::KEY_ERRORS, true) => new RegistryRequestFailed(
                "data.go.kr refused the {$registry} service key ({$detail}); check {$variable} and that the key is approved for this API.",
            ),
            in_array($code, self::QUOTA_ERRORS, true) => new RegistryRequestFailed(
                "data.go.kr request quota for {$registry} exceeded ({$detail}); try again later or ask data.go.kr for more traffic.",
            ),
            default => new RegistryRequestFailed("data.go.kr returned an error for {$registry} ({$detail})."),
        };
    }

    private static function code(mixed $code): ?string
    {
        if (is_int($code)) {
            $code = (string) $code;
        }

        if (! is_string($code) || ! ctype_digit(trim($code))) {
            return null;
        }

        return str_pad((string) (int) trim($code), 2, '0', STR_PAD_LEFT);
    }
}
