<?php

namespace App\Models;

use App\Enums\MysqlNodeRole;
use App\Enums\MysqlNodeStatus;
use Database\Factories\MysqlNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MysqlNode extends Model
{
    /** @use HasFactory<MysqlNodeFactory> */
    use HasFactory;

    protected static function newFactory(): MysqlNodeFactory
    {
        return MysqlNodeFactory::new();
    }

    protected $table = 'nodes';

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
        'role' => MysqlNodeRole::class,
        'status' => MysqlNodeStatus::class,
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
        return $this->belongsTo(MysqlCluster::class, 'cluster_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'node_id');
    }

    public function isDbNode(): bool
    {
        return in_array($this->role, [MysqlNodeRole::Primary, MysqlNodeRole::Secondary, MysqlNodeRole::Pending]);
    }

    public function isAccessNode(): bool
    {
        return $this->role === MysqlNodeRole::Access;
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
