<?php

namespace App\Models;

use App\Contracts\SshConnectable;
use App\Enums\RedisNodeRole;
use App\Enums\RedisNodeStatus;
use Database\Factories\RedisNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RedisNode extends Model implements SshConnectable
{
    /** @use HasFactory<RedisNodeFactory> */
    use HasFactory;

    protected static function newFactory(): RedisNodeFactory
    {
        return RedisNodeFactory::new();
    }

    protected $table = 'redis_nodes';

    protected $fillable = [
        'server_id',
        'redis_cluster_id',
        'name',
        'redis_port',
        'sentinel_port',
        'role',
        'status',
        'redis_installed',
        'sentinel_installed',
        'redis_configured',
        'redis_version',
        'last_health_json',
        'last_checked_at',
    ];

    protected $casts = [
        'role' => RedisNodeRole::class,
        'status' => RedisNodeStatus::class,
        'last_health_json' => 'array',
        'last_checked_at' => 'datetime',
        'redis_installed' => 'boolean',
        'sentinel_installed' => 'boolean',
        'redis_configured' => 'boolean',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(RedisCluster::class, 'redis_cluster_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'redis_node_id');
    }

    public function isMaster(): bool
    {
        return $this->role === RedisNodeRole::Master;
    }

    public function isReplica(): bool
    {
        return $this->role === RedisNodeRole::Replica;
    }

    /**
     * Get the redis-cli connection string.
     */
    public function getRedisCliUri(): string
    {
        return "-h {$this->server->host} -p {$this->redis_port}";
    }

    /**
     * Get the server that holds SSH credentials.
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    /**
     * Get audit log context for SSH operations.
     */
    public function getAuditContext(): array
    {
        return [
            'redis_cluster_id' => $this->redis_cluster_id,
            'redis_node_id' => $this->id,
        ];
    }
}
