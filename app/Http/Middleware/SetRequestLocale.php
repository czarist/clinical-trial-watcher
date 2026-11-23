<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers each API request in the language of its Accept-Language header (the web app sends
 * the language the user chose), falling back to English.
 */
final class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasHeader('Accept-Language')
            ? Locale::negotiate($request->getLanguages())
            : Locale::default();

        App::setLocale($locale->value);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale->tag());

        return $response;
    }
}
