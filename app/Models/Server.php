<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected static function newFactory(): ServerFactory
    {
        return ServerFactory::new();
    }

    protected $fillable = [
        'name',
        'host',
        'ssh_port',
        'ssh_user',
        'ssh_private_key_encrypted',
        'ssh_public_key',
        'ssh_key_fingerprint',
        'ram_mb',
        'cpu_cores',
        'os_name',
    ];

    protected $hidden = [
        'ssh_private_key_encrypted',
    ];

    protected $casts = [
        'ssh_private_key_encrypted' => 'encrypted',
    ];

    public function mysqlNodes(): HasMany
    {
        return $this->hasMany(MysqlNode::class);
    }

    public function redisNodes(): HasMany
    {
        return $this->hasMany(RedisNode::class);
    }
}
