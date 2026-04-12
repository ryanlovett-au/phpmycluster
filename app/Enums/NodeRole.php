<?php

namespace App\Enums;

enum NodeRole: string
{
    case Pending = 'pending';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Access = 'access'; // MySQL Router node
}
