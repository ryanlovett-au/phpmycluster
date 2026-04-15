<?php

namespace App\Contracts;

use App\Models\Server;

interface SshConnectable
{
    /**
     * Get the server that holds SSH credentials for this entity.
     */
    public function getServer(): Server;

    /**
     * Get audit log context (FK columns) for this entity.
     */
    public function getAuditContext(): array;
}
