<?php

namespace App\Modules\Evidence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Evidence\Http\Requests\StoreEvidenceTrialRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EvidenceTrialController extends Controller
{
    // Private disk: trial files are never served publicly.
    private const DISK = 'local';

    public function store(StoreEvidenceTrialRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $userId = $request->user()->id;
        $kind = StoreEvidenceTrialRequest::isPhoto($file) ? 'photo' : 'video';

        $path = $file->store("evidence-trials/{$userId}", self::DISK);

        $result = [
            'kind' => $kind,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
            'device' => $request->validated('device'),
            'received_at' => Carbon::now()->toIso8601String(),
        ];

        // Keeps a server-side record of the trial without a database table.
        Log::info('Evidence trial received', $result + ['user_id' => $userId, 'path' => $path]);

        return response()->json(['data' => $result], 201);
    }
}
