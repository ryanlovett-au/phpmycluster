<?php

namespace App\Enums;

enum RedisNodeStatus: string
{
    case Unknown = 'unknown';
    case Online = 'online';
    case Syncing = 'syncing';
    case Offline = 'offline';
    case Error = 'error';
    case Unreachable = 'unreachable';
}
