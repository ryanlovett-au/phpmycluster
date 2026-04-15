<?php

namespace App\Services;

use App\Models\MysqlCluster;
use App\Models\MysqlNode;

class FirewallService
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Check if UFW is installed and active on a node.
     */
    public function getStatus(MysqlNode $node): array
    {
        $result = $this->ssh->exec($node, 'which ufw && ufw status verbose 2>&1', 'firewall.status', sudo: true);

        return [
            'installed' => str_contains($result['output'], '/ufw'),
            'active' => str_contains($result['output'], 'Status: active'),
            'output' => $result['output'],
        ];
    }

    /**
     * Install UFW if not present.
     */
    public function installUfw(MysqlNode $node): array
    {
        return $this->ssh->exec(
            $node,
            'apt-get update -qq && apt-get install -y -qq ufw',
            'firewall.install',
            sudo: true
        );
    }

    /**
     * Configure UFW rules for a DB node in the cluster.
     * Opens only the required ports from the required sources.
     */
    public function configureDbNode(MysqlNode $node, MysqlCluster $cluster): array
    {
        $results = [];

        // Always allow SSH (don't lock ourselves out!)
        $results[] = $this->ssh->exec($node, 'ufw allow 22/tcp comment "SSH"', 'firewall.rule', sudo: true);

        // Allow MySQL from each other DB node in the cluster
        foreach ($cluster->dbNodes as $peer) {
            if ($peer->id === $node->id) {
                continue;
            }

            // MySQL protocol port
            $results[] = $this->ssh->exec(
                $node,
                "ufw allow from {$peer->server->host} to any port {$node->mysql_port} proto tcp comment 'MySQL from {$peer->name}'",
                'firewall.rule',
                sudo: true
            );

            // MySQL X protocol port
            $results[] = $this->ssh->exec(
                $node,
                "ufw allow from {$peer->server->host} to any port {$node->mysql_x_port} proto tcp comment 'MySQL X from {$peer->name}'",
                'firewall.rule',
                sudo: true
            );

            // Group Replication communication port (33061)
            $results[] = $this->ssh->exec(
                $node,
                "ufw allow from {$peer->server->host} to any port 33061 proto tcp comment 'GR comm from {$peer->name}'",
                'firewall.rule',
                sudo: true
            );
        }

        // Allow MySQL from access (router) nodes
        foreach ($cluster->accessNodes as $accessNode) {
            $results[] = $this->ssh->exec(
                $node,
                "ufw allow from {$accessNode->server->host} to any port {$node->mysql_port} proto tcp comment 'MySQL from router {$accessNode->name}'",
                'firewall.rule',
                sudo: true
            );
        }

        // Set default deny incoming, allow outgoing
        $results[] = $this->ssh->exec($node, 'ufw default deny incoming', 'firewall.rule', sudo: true);
        $results[] = $this->ssh->exec($node, 'ufw default allow outgoing', 'firewall.rule', sudo: true);

        // Enable UFW (--force to avoid interactive prompt)
        $results[] = $this->ssh->exec($node, 'ufw --force enable', 'firewall.enable', sudo: true);

        return $results;
    }

    /**
     * Configure UFW rules for an access (router) node.
     * Opens MySQL Router ports for application traffic.
     */
    public function configureAccessNode(MysqlNode $node, MysqlCluster $cluster, string $allowFrom = '127.0.0.1'): array
    {
        $results = [];

        // SSH
        $results[] = $this->ssh->exec($node, 'ufw allow 22/tcp comment "SSH"', 'firewall.rule', sudo: true);

        // MySQL Router R/W port (6446)
        $results[] = $this->ssh->exec(
            $node,
            "ufw allow from {$allowFrom} to any port 6446 proto tcp comment 'MySQL Router RW'",
            'firewall.rule',
            sudo: true
        );

        // MySQL Router R/O port (6447)
        $results[] = $this->ssh->exec(
            $node,
            "ufw allow from {$allowFrom} to any port 6447 proto tcp comment 'MySQL Router RO'",
            'firewall.rule',
            sudo: true
        );

        // Allow outbound to all DB nodes (for router to connect to cluster)
        // UFW allows outgoing by default, but we ensure it
        $results[] = $this->ssh->exec($node, 'ufw default deny incoming', 'firewall.rule', sudo: true);
        $results[] = $this->ssh->exec($node, 'ufw default allow outgoing', 'firewall.rule', sudo: true);
        $results[] = $this->ssh->exec($node, 'ufw --force enable', 'firewall.enable', sudo: true);

        return $results;
    }

    /**
     * Add a firewall rule to allow a new node IP on all existing cluster nodes.
     * Called when adding a new node to the cluster.
     */
    public function allowNewNodeOnCluster(MysqlCluster $cluster, MysqlNode $newNode): array
    {
        $results = [];

        foreach ($cluster->dbNodes as $existingNode) {
            if ($existingNode->id === $newNode->id) {
                continue;
            }

            // Allow the new node to connect to existing nodes
            $results[] = $this->ssh->exec(
                $existingNode,
                "ufw allow from {$newNode->server->host} to any port {$existingNode->mysql_port} proto tcp comment 'MySQL from {$newNode->name}'",
                'firewall.rule',
                sudo: true
            );

            $results[] = $this->ssh->exec(
                $existingNode,
                "ufw allow from {$newNode->server->host} to any port 33061 proto tcp comment 'GR comm from {$newNode->name}'",
                'firewall.rule',
                sudo: true
            );
        }

        return $results;
    }
}
