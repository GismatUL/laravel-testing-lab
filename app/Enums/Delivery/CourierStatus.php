<?php

namespace App\Enums\Delivery;

enum CourierStatus: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Offline = 'offline';
}
