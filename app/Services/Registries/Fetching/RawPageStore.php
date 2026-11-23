<?php

declare(strict_types=1);

namespace App\Services\Registries\Fetching;

use App\Models\RegistryPage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Stores the body of every registry response with its validators, so the next request can
 * be conditional and a parser fix can run again on stored pages without fetching.
 */
final readonly class RawPageStore
{
    public function __construct(
        private Filesystem $disk,
        private string $directory,
    ) {}

    public function find(string $source, string $url): ?RegistryPage
    {
        return RegistryPage::query()->where('source', $source)->where('url_hash', self::hash($url))->first();
    }

    public function body(RegistryPage $page): ?string
    {
        return $this->disk->get($page->storage_path);
    }

    /**
     * @param  array{etag: ?string, last_modified: ?string, content_type: ?string, status: int}  $meta
     */
    public function put(string $source, string $url, string $body, array $meta): RegistryPage
    {
        $hash = self::hash($url);
        $path = "{$this->directory}/{$source}/".substr($hash, 0, 2)."/{$hash}";
        $this->disk->put($path, $body);

        return RegistryPage::query()->updateOrCreate(['source' => $source, 'url_hash' => $hash], [
            'url' => $url,
            'status' => $meta['status'],
            'etag' => $meta['etag'],
            'last_modified' => $meta['last_modified'],
            'content_type' => $meta['content_type'],
            'content_hash' => hash('sha256', $body),
            'size' => strlen($body),
            'storage_path' => $path,
            'fetched_at' => CarbonImmutable::now(),
        ]);
    }

    public function touch(RegistryPage $page): void
    {
        $page->forceFill(['fetched_at' => CarbonImmutable::now()])->save();
    }

    private static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
