<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Exceptions\RegistryRequestFailed;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

/**
 * Client for the ClinicalTrials.gov API v2 studies endpoint.
 */
final readonly class ClinicalTrialsGovClient implements StudyRegistryClient
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private int $timeoutSeconds,
        private int $retryTimes,
        private int $retrySleepMilliseconds,
    ) {}

    #[\Override]
    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $query = array_filter([
            'format' => 'json',
            'pageSize' => $pageSize,
            'pageToken' => $pageToken,
            'filter.advanced' => $updatedSince !== null
                ? sprintf('AREA[LastUpdatePostDate]RANGE[%s,MAX]', $updatedSince->toDateString())
                : null,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->retry(
                    $this->retryTimes,
                    fn (int $attempt): int => $this->retrySleepMilliseconds * (2 ** ($attempt - 1)),
                    static fn (\Throwable $exception): bool => self::isRetryable($exception),
                )
                ->get('/studies', $query)
                ->throw();
        } catch (RequestException $exception) {
            throw RegistryRequestFailed::fromStatus($exception->response->status(), $exception);
        } catch (ConnectionException $exception) {
            throw RegistryRequestFailed::fromConnection($exception);
        }

        $studies = $response->json('studies');

        if (! is_array($studies)) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $nextPageToken = $response->json('nextPageToken');

        return new StudyPage(array_values($studies), is_string($nextPageToken) ? $nextPageToken : null);
    }

    private static function isRetryable(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
