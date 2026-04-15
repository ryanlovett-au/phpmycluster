<?php

namespace App\Enums;

enum MysqlNodeRole: string
{
    case Pending = 'pending';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Access = 'access'; // MySQL Router node
}
