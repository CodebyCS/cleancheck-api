<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IcalReadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return 0;
    }
}