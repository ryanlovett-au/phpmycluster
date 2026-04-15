<?php

namespace App\Models;

use App\Enums\ClusterStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cluster extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'communication_stack',
        'mysql_version',
        'mysql_apt_config_version',
        'ip_allowlist',
        'cluster_admin_user',
        'cluster_admin_password_encrypted',
        'status',
        'last_status_json',
        'last_checked_at',
    ];

    protected $casts = [
        'status' => ClusterStatus::class,
        'last_status_json' => 'array',
        'last_checked_at' => 'datetime',
        'cluster_admin_password_encrypted' => 'encrypted',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    public function dbNodes(): HasMany
    {
        return $this->hasMany(Node::class)->whereIn('role', ['primary', 'secondary', 'pending']);
    }

    public function accessNodes(): HasMany
    {
        return $this->hasMany(Node::class)->where('role', 'access');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function primaryNode(): ?Node
    {
        return $this->nodes()->where('role', 'primary')->first();
    }

    /**
     * Get a reachable DB node for status queries.
     * Tries the primary first, then falls back to online secondaries.
     */
    public function reachableDbNode(): ?Node
    {
        // Try primary first
        $primary = $this->primaryNode();
        if ($primary) {
            return $primary;
        }

        // Fall back to any online secondary
        return $this->nodes()
            ->where('role', 'secondary')
            ->where('status', 'online')
            ->first()
            // Last resort: any DB node that isn't pending
            ?? $this->nodes()
                ->whereIn('role', ['primary', 'secondary'])
                ->first();
    }

    /**
     * Build the IP allowlist dynamically from all DB node IPs.
     */
    public function buildIpAllowlist(): string
    {
        return $this->dbNodes()
            ->pluck('host')
            ->unique()
            ->implode(',');
    }
}
