<?php

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case Abandoned = 'abandoned';
    case Converted = 'converted';
    case Expired = 'expired';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Active',
            self::Abandoned => 'Abandoned',
            self::Converted => 'Converted',
            self::Expired   => 'Expired',
        };
    }
}