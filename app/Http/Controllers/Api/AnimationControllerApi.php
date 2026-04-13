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
        $record     = AvatarAnimationController::where('avatar_id', $avatar->id)->first();
        $controller = $record?->controller ?? AvatarAnimationController::defaultControllerData();

        // Collect all clip IDs referenced across all categories
        $allClipIds = collect($controller)
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Build FBX URL map: { clip_id => public_url }
        $clipUrls = AnimationClip::whereIn('id', $allClipIds)
            ->get()
            ->mapWithKeys(fn ($clip) => [(string) $clip->id => $clip->fbxUrl()])
            ->all();

        return response()->json([
            'controller' => $controller,
            'clip_urls'  => $clipUrls,
        ]);
    }
}
