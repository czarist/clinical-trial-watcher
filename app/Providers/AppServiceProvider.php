<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\CashierSubscriptionGateway;
use App\Services\Billing\SubscriptionGateway;
use App\Services\ClinicalTrials\Import\ClinicalTrialsGovClient;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\ClinicalTrials\Search\Filters\AttributeFilter;
use App\Services\ClinicalTrials\Search\Filters\DateRangeFilter;
use App\Services\ClinicalTrials\Search\Filters\FullTextFilter;
use App\Services\ClinicalTrials\Search\Filters\LocationFilter;
use App\Services\ClinicalTrials\Search\Filters\PhaseFilter;
use App\Services\ClinicalTrials\Search\Filters\RelatedTextFilter;
use App\Services\ClinicalTrials\Search\TrialQuery;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

final class AppServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->bind(StudyRegistryClient::class, fn (Application $app): StudyRegistryClient => new ClinicalTrialsGovClient(
            $app->make(HttpFactory::class),
            (string) config('clinicaltrials.base_url'),
            (int) config('clinicaltrials.timeout'),
            (int) config('clinicaltrials.retry.times'),
            (int) config('clinicaltrials.retry.sleep_ms'),
        ));

        $this->app->singleton(TrialQuery::class, fn (): TrialQuery => new TrialQuery([
            new FullTextFilter,
            new RelatedTextFilter(fn (TrialSearchCriteria $c): ?string => $c->condition, 'conditions', 'condition'),
            new RelatedTextFilter(fn (TrialSearchCriteria $c): ?string => $c->keyword, 'keywords', 'keyword'),
            new RelatedTextFilter(fn (TrialSearchCriteria $c): ?string => $c->meshTerm, 'meshTerms', 'term'),
            new AttributeFilter,
            new PhaseFilter,
            new LocationFilter,
            new DateRangeFilter('start_date', fn (TrialSearchCriteria $c): array => [$c->startDateFrom, $c->startDateTo]),
            new DateRangeFilter('study_first_post_date', fn (TrialSearchCriteria $c): array => [$c->firstPostedFrom, $c->firstPostedTo]),
            new DateRangeFilter('last_update_post_date', fn (TrialSearchCriteria $c): array => [$c->lastUpdateFrom, $c->lastUpdateTo]),
        ]));

        $this->app->bind(SubscriptionGateway::class, fn (): SubscriptionGateway => new CashierSubscriptionGateway(
            (string) config('billing.subscription_type'),
        ));

        $this->app->when(BillingService::class)
            ->needs('$frontendUrl')
            ->give(fn (): string => rtrim((string) config('app.frontend_url'), '/'));
    }

    public function boot(): void
    {
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useCustomerModel(User::class);

        $this->configureRateLimiting();
        $this->configureAuthLinks();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);

        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(6)->by((string) $request->ip()));
    }

    /**
     * Links in authentication emails point to the web app (password reset) or to the signed
     * API route that verifies the address and redirects to the web app.
     */
    private function configureAuthLinks(): void
    {
        $frontend = fn (): string => rtrim((string) config('app.frontend_url'), '/');

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => $frontend().'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        VerifyEmail::createUrlUsing(fn (User $user): string => URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['user' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        ));
    }
}
