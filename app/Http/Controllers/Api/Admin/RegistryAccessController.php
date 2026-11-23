<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Registries\RegistryAccessGuide;
use Illuminate\Http\JsonResponse;

/**
 * What each registry needs before its data can be used: keys, written permissions and
 * contacts, for operators who manage imports.
 */
final class RegistryAccessController extends Controller
{
    public function __invoke(RegistryAccessGuide $guide): JsonResponse
    {
        return response()->json([
            'data' => $guide->sources(),
            'unavailable' => $guide->unavailable(),
            'drafts' => RegistryAccessGuide::DRAFTS,
        ]);
    }
}
