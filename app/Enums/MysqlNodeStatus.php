<?php

namespace App\Enums;

enum MysqlNodeStatus: string
{
    case Unknown = 'unknown';
    case Online = 'online';
    case Recovering = 'recovering';
    case Offline = 'offline';
    case Error = 'error';
    case Unreachable = 'unreachable';
}
