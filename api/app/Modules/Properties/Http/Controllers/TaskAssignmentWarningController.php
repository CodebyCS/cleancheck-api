<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Services\TaskAssignmentWarnings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskAssignmentWarningController extends Controller
{
    // Open tasks without provider, with an inactive provider or with a schedule conflict.
    public function index(Request $request, TaskAssignmentWarnings $warnings): JsonResponse
    {
        $validated = $request->validate(
            ['property_id' => ['sometimes', 'integer']],
            ['property_id.integer' => 'Escolha um imóvel válido.'],
        );

        $propertyId = isset($validated['property_id']) ? (int) $validated['property_id'] : null;

        return response()->json(['data' => $warnings->list($propertyId)]);
    }
}
