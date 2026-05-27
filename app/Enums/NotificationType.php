<?php

namespace App\Enums;

enum NotificationType: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
    case InApp = 'in_app';

    public function label(): string
    {
        return match($this) {
            self::Email => 'Email',
            self::Sms   => 'SMS',
            self::Push  => 'Push',
            self::InApp => 'In-App',
        };
    }
}