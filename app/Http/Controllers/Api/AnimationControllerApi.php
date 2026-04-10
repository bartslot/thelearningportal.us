<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

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

        return response()->json([
            'controller' => $controller,
            'clip_urls'  => [],
        ]);
    }
}
