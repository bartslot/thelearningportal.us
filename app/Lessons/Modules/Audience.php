<?php

declare(strict_types=1);

namespace App\Lessons\Modules;

/** Who a slide is rendered for. Student hides answers + teacher notes (K-8). */
enum Audience: string
{
    case Teacher = 'teacher';
    case Student = 'student';

    public function isTeacher(): bool
    {
        return $this === self::Teacher;
    }
}
