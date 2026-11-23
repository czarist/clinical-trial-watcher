<?php

declare(strict_types=1);

namespace App\Services\Registries\Fetching;

use Carbon\CarbonInterface;

/**
 * A registry response body, fresh or read from the stored copy.
 */
final readonly class FetchedDocument
{
    /**
     * @param  bool  $notModified  the registry answered 304 and the stored copy was returned
     * @param  bool  $fromCache  the body was read from storage without a request (--from-cache)
     */
    public function __construct(
        public string $url,
        public string $body,
        public ?string $contentType,
        public CarbonInterface $fetchedAt,
        public bool $notModified = false,
        public bool $fromCache = false,
    ) {}

    /** Path of a temporary file holding the body, for readers that need a file (ZIP, PDF). */
    public function toTemporaryFile(string $extension = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'registry-').($extension !== '' ? '.'.$extension : '');
        file_put_contents($path, $this->body);

        return $path;
    }
}
