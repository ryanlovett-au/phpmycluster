<?php

namespace App\Enums;

enum RedisClusterStatus: string
{
    case Pending = 'pending';
    case Online = 'online';
    case Degraded = 'degraded';
    case Offline = 'offline';
    case Error = 'error';
}
