<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    /**
     * Target of the signed link in the verification email; redirects to the web app.
     */
    public function verify(Request $request, User $user, string $hash): RedirectResponse
    {
        $verified = $this->accounts->verifyEmail($user, $hash);
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return redirect()->away($frontend.'/login?verified='.($verified ? '1' : '0'));
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => __('messages.email_already_verified')]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => __('messages.verification_sent')], 202);
    }
}
