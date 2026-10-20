<?php

namespace App\Enums;

enum SkillSource: string
{
    case Profile = 'profile';
    case Resume = 'resume';

    public function label(): string
    {
        return match ($this) {
            self::Profile => 'Profile',
            self::Resume => 'Resume',
        };
    }
}
