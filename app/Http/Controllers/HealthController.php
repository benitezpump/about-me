<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Para el balanceador y el healthcheck de Docker: responde 200 solo si la base contesta. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');

            return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            return response()->json(['status' => 'error'], 503)->header('Cache-Control', 'no-store');
        }
    }
}
