<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SpriteStatus;
use PHPUnit\Framework\TestCase;

class SpriteStatusTest extends TestCase
{
    public function test_from_string(): void
    {
        $this->assertSame(SpriteStatus::Ready, SpriteStatus::from('ready'));
        $this->assertSame(SpriteStatus::Failed, SpriteStatus::from('failed'));
    }
}
