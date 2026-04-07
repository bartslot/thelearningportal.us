<?php

declare(strict_types=1);

namespace App\Enums;

enum SpriteStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Ready      = 'ready';
    case Failed     = 'failed';
}
