<?php

namespace App\Models;

use App\Contracts\SshConnectable;
use App\Enums\MysqlNodeRole;
use App\Enums\MysqlNodeStatus;
use Database\Factories\MysqlNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MysqlNode extends Model implements SshConnectable
{
    /** @use HasFactory<MysqlNodeFactory> */
    use HasFactory;

    protected static function newFactory(): MysqlNodeFactory
    {
        return MysqlNodeFactory::new();
    }

    protected $table = 'nodes';

    protected $fillable = [
        'server_id',
        'cluster_id',
        'name',
        'mysql_port',
        'mysql_x_port',
        'mysql_root_password_encrypted',
        'role',
        'status',
        'mysql_server_id',
        'mysql_installed',
        'mysql_shell_installed',
        'mysql_router_installed',
        'mysql_configured',
        'mysql_version',
        'last_health_json',
        'last_checked_at',
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
        'mysql_root_password_encrypted' => 'encrypted',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

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

        return "{$user}@{$this->server->host}:{$this->mysql_port}";
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
            'cluster_id' => $this->cluster_id,
            'node_id' => $this->id,
        ];
    }
}
