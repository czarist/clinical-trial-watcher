<?php

declare(strict_types=1);

namespace App\Services\Registries\Fetching;

/**
 * Rules of one robots.txt file (RFC 9309): the group of the most specific matching user
 * agent applies, otherwise the "*" group; the longest matching rule wins and Allow wins a
 * tie. "*" matches any characters and "$" anchors the end of the path.
 */
final readonly class RobotsTxt
{
    /**
     * @param  array<string, list<array{allow: bool, pattern: string}>>  $groups  rules keyed by lower-case user agent
     * @param  array<string, float>  $crawlDelays  seconds keyed by lower-case user agent
     */
    private function __construct(
        private array $groups,
        private array $crawlDelays,
    ) {}

    public static function allowAll(): self
    {
        return new self([], []);
    }

    public static function disallowAll(): self
    {
        return new self(['*' => [['allow' => false, 'pattern' => '/']]], []);
    }

    public static function parse(string $content): self
    {
        $groups = [];
        $delays = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map(trim(...), explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // A user-agent line after rules starts a new group.
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }

                $agents[] = strtolower($value);
                $groups[strtolower($value)] ??= [];

                continue;
            }

            if ($agents === []) {
                continue;
            }

            $inRules = true;

            foreach ($agents as $agent) {
                match ($field) {
                    'allow', 'disallow' => $value !== '' ? $groups[$agent][] = ['allow' => $field === 'allow', 'pattern' => $value] : null,
                    'crawl-delay' => is_numeric($value) ? $delays[$agent] = (float) $value : null,
                    default => null,
                };
            }
        }

        return new self($groups, $delays);
    }

    public function allows(string $userAgent, string $path): bool
    {
        $best = null;

        foreach ($this->rulesFor($userAgent) as $rule) {
            $length = self::matchLength($rule['pattern'], $path);

            if ($length === null) {
                continue;
            }

            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    public function crawlDelay(string $userAgent): ?float
    {
        $agent = $this->agentFor($userAgent);

        return $agent !== null ? ($this->crawlDelays[$agent] ?? null) : null;
    }

    /**
     * @return list<array{allow: bool, pattern: string}>
     */
    private function rulesFor(string $userAgent): array
    {
        $agent = $this->agentFor($userAgent);

        return $agent !== null ? $this->groups[$agent] : [];
    }

    private function agentFor(string $userAgent): ?string
    {
        $token = strtolower($userAgent);
        $match = null;

        foreach (array_keys($this->groups) as $agent) {
            if ($agent !== '*' && str_contains($token, $agent) && strlen($agent) > strlen((string) $match)) {
                $match = $agent;
            }
        }

        return $match ?? (array_key_exists('*', $this->groups) ? '*' : null);
    }

    /** Length of the pattern when it matches the start of the path, or null. */
    private static function matchLength(string $pattern, string $path): ?int
    {
        $anchored = str_ends_with($pattern, '$');
        $body = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^'.str_replace('\*', '.*', preg_quote($body, '#')).($anchored ? '$' : '').'#';

        return preg_match($regex, $path) === 1 ? strlen($pattern) : null;
    }
}
