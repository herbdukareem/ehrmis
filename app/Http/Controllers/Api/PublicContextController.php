<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DomainContext;
use Illuminate\Http\JsonResponse;

class PublicContextController extends Controller
{
    public function show(DomainContext $context): JsonResponse
    {
        return response()->json(['data' => $context->publicProfile()]);
    }
}
