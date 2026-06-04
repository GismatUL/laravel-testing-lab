<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Unread = 'unread';
    case Read = 'read';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match($this) {
            self::Unread    => 'Unread',
            self::Read      => 'Read',
            self::Dismissed => 'Dismissed',
        };
    }
}