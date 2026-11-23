<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartImportRequest;
use App\Http\Resources\ImportRunResource;
use App\Jobs\ImportClinicalTrialsJob;
use App\Models\ImportRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ImportRunController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ImportRunResource::collection(ImportRun::query()->latest('id')->paginate(20));
    }

    /**
     * Queues an import. Only one import runs at a time.
     */
    public function store(StartImportRequest $request): JsonResponse
    {
        ImportClinicalTrialsJob::dispatch($request->options());

        return response()->json(['message' => __('messages.import_queued')], 202);
    }
}
