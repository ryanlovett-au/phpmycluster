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

    protected $fillable = [
        'cluster_id',
        'name',
        'host',
        'ssh_port',
        'ssh_user',
        'ssh_private_key_encrypted',
        'ssh_public_key',
        'ssh_key_fingerprint',
        'mysql_port',
        'mysql_x_port',
        'mysql_root_password_encrypted',
        'role',
        'status',
        'server_id',
        'mysql_installed',
        'mysql_shell_installed',
        'mysql_router_installed',
        'mysql_configured',
        'mysql_version',
        'last_health_json',
        'last_checked_at',
        'ram_mb',
        'cpu_cores',
        'os_name',
    ];

    protected $hidden = [
        'ssh_private_key_encrypted',
    ];

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
