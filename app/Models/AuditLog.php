<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'cluster_id',
        'node_id',
        'redis_cluster_id',
        'redis_node_id',
        'action',
        'status',
        'command',
        'output',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(MysqlCluster::class, 'cluster_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(MysqlNode::class, 'node_id');
    }

    public function redisCluster(): BelongsTo
    {
        return $this->belongsTo(RedisCluster::class, 'redis_cluster_id');
    }

    public function redisNode(): BelongsTo
    {
        return $this->belongsTo(RedisNode::class, 'redis_node_id');
    }
}
