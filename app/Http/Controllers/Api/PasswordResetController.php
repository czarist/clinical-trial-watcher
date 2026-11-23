<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

final class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwords) {}

    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink($request->string('email')->toString());

        return response()->json([
            'message' => __('messages.reset_link_sent'),
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string, token: string} $data */
        $data = $request->safe()->only(['email', 'password', 'token']);
        $this->passwords->reset($data);

        return response()->json(['message' => __('passwords.reset')]);
    }
}
