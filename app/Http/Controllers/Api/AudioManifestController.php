<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AudioManifestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['audio_url' => 'required|url']);

        $vercelUrl = rtrim(config('services.vercel_functions.url'), '/');

        $response = Http::timeout(15)->post("{$vercelUrl}/api/audio-manifest", [
            'audio_url' => $request->input('audio_url'),
        ]);

        if (! $response->successful()) {
            return response()->json(['error' => 'Manifest generation failed'], 502);
        }

        return response()->json($response->json());
    }
}
