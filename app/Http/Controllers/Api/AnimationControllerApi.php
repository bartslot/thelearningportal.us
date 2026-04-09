<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnimationControllerApi
{
    public function __invoke(Request $request, Avatar $avatar): JsonResponse
    {
        $record = AvatarAnimationController::where('avatar_id', $avatar->id)->first();

        $controller = $record?->controller ?? AvatarAnimationController::defaultControllerData();

        // Collect all clip_ids referenced in any slot
        $allClipIds = collect($controller['slots'] ?? [])
            ->flatMap(fn($slot) => $slot['clips'] ?? [])
            ->unique()
            ->values();

        // Build URL map for clips that are converted and ready
        $clipUrls = AnimationClip::whereIn('clip_id', $allClipIds)
            ->where('status', 'ready')
            ->whereNotNull('glb_path')
            ->get()
            ->mapWithKeys(fn($clip) => [$clip->clip_id => asset($clip->glb_path)])
            ->all();

        return response()->json([
            'controller' => $controller,
            'clip_urls'  => $clipUrls,
        ]);
    }
}
