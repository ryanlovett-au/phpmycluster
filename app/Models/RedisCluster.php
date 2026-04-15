<?php

namespace App\Models;

use App\Enums\RedisClusterStatus;
use Database\Factories\RedisClusterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RedisCluster extends Model
{
    /** @use HasFactory<RedisClusterFactory> */
    use HasFactory;

    protected static function newFactory(): RedisClusterFactory
    {
        return RedisClusterFactory::new();
    }

    protected $table = 'redis_clusters';

    protected $fillable = [
        'name',
        'redis_version',
        'auth_password_encrypted',
        'sentinel_password_encrypted',
        'quorum',
        'down_after_milliseconds',
        'failover_timeout',
        'status',
        'last_status_json',
        'last_checked_at',
    ];

    protected $casts = [
        'status' => RedisClusterStatus::class,
        'last_status_json' => 'array',
        'last_checked_at' => 'datetime',
        'auth_password_encrypted' => 'encrypted',
        'sentinel_password_encrypted' => 'encrypted',
        'quorum' => 'integer',
        'down_after_milliseconds' => 'integer',
        'failover_timeout' => 'integer',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(RedisNode::class, 'redis_cluster_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'redis_cluster_id');
    }

    /**
     * Get the current master node.
     */
    public function masterNode(): ?RedisNode
    {
        return $this->nodes()->where('role', 'master')->first();
    }

    /**
     * Get all replica nodes.
     */
    public function replicaNodes(): HasMany
    {
        return $this->hasMany(RedisNode::class, 'redis_cluster_id')->where('role', 'replica');
    }

    /**
     * Get a reachable node for status queries (master preferred).
     */
    public function reachableNode(): ?RedisNode
    {
        return $this->masterNode()
            ?? $this->nodes()->where('role', 'replica')->where('status', 'online')->first()
            ?? $this->nodes()->first();
    }
}
