<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublishRouteTest extends TestCase
{
    public function test_removes_the_legacy_publish_patch_route(): void
    {
        $this->assertFalse(Route::has('teacher.lessons.publish'));
    }
}
