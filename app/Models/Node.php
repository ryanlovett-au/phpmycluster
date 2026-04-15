<?php

namespace App\Models;

use App\Enums\NodeRole;
use App\Enums\NodeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'role' => NodeRole::class,
        'status' => NodeStatus::class,
        'last_health_json' => 'array',
        'last_checked_at' => 'datetime',
        'mysql_installed' => 'boolean',
        'mysql_shell_installed' => 'boolean',
        'mysql_router_installed' => 'boolean',
        'mysql_configured' => 'boolean',
        'ssh_private_key_encrypted' => 'encrypted',
        'mysql_root_password_encrypted' => 'encrypted',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isDbNode(): bool
    {
        return in_array($this->role, [NodeRole::Primary, NodeRole::Secondary, NodeRole::Pending]);
    }

    public function isAccessNode(): bool
    {
        return $this->role === NodeRole::Access;
    }

    /**
     * Get the MySQL connection URI for mysqlsh commands.
     */
    public function getMysqlshUri(?string $user = null): string
    {
        $user = $user ?? $this->cluster?->cluster_admin_user ?? 'clusteradmin';

        return "{$user}@{$this->host}:{$this->mysql_port}";
    }
}
