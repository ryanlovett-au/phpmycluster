<?php

namespace App\Enums;

enum RedisNodeRole: string
{
    case Pending = 'pending';
    case Master = 'master';
    case Replica = 'replica';
}
