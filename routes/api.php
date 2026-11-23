<?php

declare(strict_types=1);

use App\Enums\PermissionName;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Admin\ImportRunController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ClinicalTrialController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\PasswordResetController;
use Illuminate\Support\Facades\Route;

$permission = static fn (PermissionName $name): string => 'permission:'.$name->value;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:auth');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth');
    Route::get('verify-email/{user}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:auth'])
        ->name('verification.verify');

    Route::middleware('auth:api')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:auth');
    });
});

Route::get('plans', [BillingController::class, 'plans']);

Route::middleware('auth:api')->group(function () use ($permission): void {
    Route::put('account/profile', [AccountController::class, 'updateProfile']);
    Route::put('account/password', [AccountController::class, 'updatePassword']);

    Route::middleware($permission(PermissionName::ViewClinicalTrials))->group(function (): void {
        Route::get('trials', [ClinicalTrialController::class, 'index']);
        Route::get('trials/{nctId}', [ClinicalTrialController::class, 'show'])->where('nctId', '[Nn][Cc][Tt]\d{8}');
    });

    Route::middleware(['verified', $permission(PermissionName::CreateAlerts)])->group(function (): void {
        Route::get('alerts', [AlertController::class, 'index']);
        Route::post('alerts', [AlertController::class, 'store']);
        Route::get('alerts/{alert}', [AlertController::class, 'show'])->can('view', 'alert');
        Route::patch('alerts/{alert}', [AlertController::class, 'update']);
        Route::delete('alerts/{alert}', [AlertController::class, 'destroy'])->can('delete', 'alert');
        Route::get('alerts/{alert}/matches', [AlertController::class, 'matches'])->can('view', 'alert');
    });

    Route::middleware('verified')->prefix('billing')->group(function (): void {
        Route::get('/', [BillingController::class, 'show']);
        Route::post('plan', [BillingController::class, 'changePlan']);
        Route::post('cancel', [BillingController::class, 'cancel']);
        Route::post('resume', [BillingController::class, 'resume']);
        Route::get('portal', [BillingController::class, 'portal']);
    });

    Route::prefix('admin')->group(function () use ($permission): void {
        Route::middleware($permission(PermissionName::ManageUsers))->group(function (): void {
            Route::get('users', [UserController::class, 'index']);
            Route::patch('users/{user}', [UserController::class, 'update']);
        });

        Route::middleware($permission(PermissionName::ManagePlans))->group(function (): void {
            Route::post('plans', [AdminPlanController::class, 'store']);
            Route::patch('plans/{plan}', [AdminPlanController::class, 'update']);
            Route::delete('plans/{plan}', [AdminPlanController::class, 'destroy']);
        });

        Route::middleware($permission(PermissionName::ManageRoles))->group(function (): void {
            Route::get('roles', [RoleController::class, 'index']);
            Route::get('permissions', [RoleController::class, 'permissions']);
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
        });

        Route::middleware($permission(PermissionName::ManageImports))->group(function (): void {
            Route::get('imports', [ImportRunController::class, 'index']);
            Route::post('imports', [ImportRunController::class, 'store']);
        });
    });
});
