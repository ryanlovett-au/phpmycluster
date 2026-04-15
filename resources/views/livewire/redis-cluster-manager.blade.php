    <div class="flex flex-col gap-6">
        {{-- Poll for refresh completion --}}
        @if($refreshing)
            <div wire:poll.5s="pollRefresh"></div>
        @endif

        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <flux:icon.server-stack class="size-6 text-red-500" />
                    <flux:heading size="xl">{{ $cluster->name }}</flux:heading>
                    <flux:badge :color="match($cluster->status->value) {
                        'online' => 'green',
                        'error', 'offline' => 'red',
                        'syncing' => 'yellow',
                        default => 'zinc',
                    }">{{ ucfirst($cluster->status->value) }}</flux:badge>
                </div>
                <flux:text class="mt-1">{{ __('Last checked:') }} {{ $cluster->last_checked_at?->diffForHumans() ?? 'Never' }}</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button wire:click="refreshStatus" :disabled="$refreshing" variant="primary" icon="arrow-path">
                    @if($refreshing)
                        {{ __('Refreshing...') }}
                    @else
                        {{ __('Refresh Status') }}
                    @endif
                </flux:button>
            </div>
        </div>

        {{-- Action message --}}
        @if($actionMessage)
            <div x-data="{ show: true }" x-init="setTimeout(() => { show = false; $wire.set('actionMessage', '') }, 4000)" x-show="show" x-transition.opacity.duration.500ms>
                <flux:callout :variant="match($actionStatus ?? 'info') { 'success' => 'success', 'error' => 'danger', default => 'info' }">
                    <flux:callout.text>{{ $actionMessage }}</flux:callout.text>
                </flux:callout>
            </div>
        @endif

        {{-- Failed provisioning detection --}}
        @if(in_array($cluster->status->value, ['pending', 'error']) && $cluster->nodes->every(fn($n) => $n->role->value === 'pending'))
            <flux:callout variant="danger">
                <flux:callout.heading>{{ __('Provisioning Incomplete') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('This cluster has not been fully provisioned. No master node has been established. You can re-provision the master node or delete this cluster and start again.') }}
                </flux:callout.text>
                <div class="mt-3 flex gap-2">
                    <flux:button variant="primary" href="{{ route('redis.reprovision', $cluster) }}" wire:navigate icon="arrow-path">
                        {{ __('Re-provision Master Node') }}
                    </flux:button>
                    <flux:button variant="danger"
                        wire:click="deleteCluster"
                        wire:confirm="{{ __('Are you sure you want to delete this Redis cluster and all its nodes? This cannot be undone.') }}">
                        {{ __('Delete Cluster') }}
                    </flux:button>
                </div>
            </flux:callout>
        @endif

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Redis Nodes --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Redis Nodes') }}</flux:heading>
            <div class="grid gap-4">
                @foreach($cluster->nodes as $node)
                    <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div @class([
                                    'size-3 rounded-full',
                                    'bg-green-500' => $node->status->value === 'online',
                                    'bg-yellow-500 animate-pulse' => $node->status->value === 'syncing',
                                    'bg-red-500' => in_array($node->status->value, ['offline', 'error']),
                                    'bg-zinc-400' => $node->status->value === 'unknown',
                                ])></div>
                                <div>
                                    @if($renamingNodeId === $node->id)
                                        <div class="flex items-center gap-2">
                                            <flux:input wire:model="renameValue" wire:keydown.enter="saveRename" wire:keydown.escape="cancelRename" size="sm" class="!py-0.5" autofocus />
                                            <flux:button size="xs" variant="primary" wire:click="saveRename" icon="check">{{ __('Save') }}</flux:button>
                                            <flux:button size="xs" wire:click="cancelRename" icon="x-mark">{{ __('Cancel') }}</flux:button>
                                        </div>
                                    @else
                                        <flux:heading class="group cursor-pointer" wire:click="startRename({{ $node->id }})">
                                            {{ $node->name }}
                                            <flux:icon.pencil-square variant="mini" class="ml-1 inline size-3.5 text-zinc-400 opacity-0 group-hover:opacity-100" />
                                        </flux:heading>
                                    @endif
                                    <flux:text class="text-xs">{{ $node->server->host }}</flux:text>
                                </div>
                                <flux:badge size="sm" :color="match($node->role->value) {
                                    'master' => 'red',
                                    'replica' => 'orange',
                                    default => 'zinc',
                                }">{{ ucfirst($node->role->value) }}</flux:badge>
                                <flux:text class="text-xs">{{ ucfirst($node->status->value) }}</flux:text>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:button size="sm" wire:click="toggleFirewall({{ $node->id }})" icon="shield-check">
                                    {{ __('Firewall') }}
                                </flux:button>
                                <flux:button size="sm" href="{{ route('redis.node.logs', $node) }}" wire:navigate icon="document-text">
                                    {{ __('Logs') }}
                                </flux:button>

                                @if($node->role->value === 'pending')
                                    {{-- Pending node: never joined, offer retry or delete --}}
                                    <flux:button size="sm" variant="primary" wire:click="retryAddNode({{ $node->id }})" icon="arrow-path">
                                        {{ __('Retry Provision') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="danger"
                                        wire:click="removeNode({{ $node->id }})"
                                        wire:confirm="{{ __('Are you sure you want to delete :name? This cannot be undone.', ['name' => $node->name]) }}">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @elseif($node->role->value !== 'master')
                                    {{-- Replica node --}}
                                    @if(in_array($node->status->value, ['offline', 'error']))
                                        <flux:button size="sm" variant="filled"
                                            wire:click="forceResync({{ $node->id }})"
                                            wire:confirm="{{ __('This will force :name to do a full resync with the master. Continue?', ['name' => $node->name]) }}"
                                            icon="arrow-uturn-left">
                                            {{ __('Resync') }}
                                        </flux:button>
                                    @endif

                                    <flux:button size="sm" variant="danger"
                                        wire:click="removeNode({{ $node->id }})"
                                        wire:confirm="{{ __('Are you sure you want to remove :name from the cluster?', ['name' => $node->name]) }}">
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>

                        {{-- Node health details --}}
                        @if($node->last_health_json)
                            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                                <div>
                                    <flux:text class="text-xs">{{ __('Redis Version') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->last_health_json['redis_version'] ?? '-' }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Uptime') }}</flux:text>
                                    @php
                                        $uptimeSec = (int) ($node->last_health_json['uptime_in_seconds'] ?? 0);
                                        $uptimeStr = $uptimeSec > 86400
                                            ? round($uptimeSec / 86400, 1) . 'd'
                                            : ($uptimeSec > 3600 ? round($uptimeSec / 3600, 1) . 'h' : round($uptimeSec / 60) . 'm');
                                    @endphp
                                    <p class="font-mono text-xs">{{ $uptimeSec ? $uptimeStr : '-' }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Connected Clients') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->last_health_json['connected_clients'] ?? '-' }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Memory Usage') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->last_health_json['used_memory_human'] ?? '-' }}</p>
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4">
                                @if($node->role->value === 'master')
                                    <div>
                                        <flux:text class="text-xs">{{ __('Connected Replicas') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $node->last_health_json['connected_slaves'] ?? '0' }}</p>
                                    </div>
                                @else
                                    <div>
                                        <flux:text class="text-xs">{{ __('Master Link') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $node->last_health_json['master_link_status'] ?? '-' }}</p>
                                    </div>
                                @endif
                                <div>
                                    <flux:text class="text-xs">{{ __('Replication Offset') }}</flux:text>
                                    <p class="font-mono text-xs">{{ isset($node->last_health_json['master_repl_offset']) ? number_format((int) $node->last_health_json['master_repl_offset']) : (isset($node->last_health_json['slave_repl_offset']) ? number_format((int) $node->last_health_json['slave_repl_offset']) : '-') }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Redis Port') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->redis_port ?? 6379 }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Sentinel Port') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->sentinel_port ?? 26379 }}</p>
                                </div>
                            </div>
                        @else
                            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                                <div>
                                    <flux:text class="text-xs">{{ __('Redis Port') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->redis_port ?? 6379 }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Sentinel Port') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->sentinel_port ?? 26379 }}</p>
                                </div>
                            </div>
                        @endif

                        {{-- Provision progress for this node --}}
                        @if($addingNodeId === $node->id && count($addNodeSteps) > 0)
                            <div class="mt-4 border-t border-neutral-200 pb-2 dark:border-neutral-700" style="padding-top: 1.25rem;">
                                @if($addingNode)
                                    <div wire:poll.2s="pollAddNode">
                                @endif

                                <div class="space-y-2">
                                    @foreach($addNodeSteps as $pStep)
                                        <div @class([
                                            'flex items-start gap-2 text-sm',
                                            'text-green-500' => $pStep['status'] === 'success',
                                            'text-red-500' => $pStep['status'] === 'error',
                                            'text-blue-500' => $pStep['status'] === 'running',
                                        ])>
                                            <span class="w-14 shrink-0 font-mono text-xs text-zinc-400">{{ $pStep['time'] }}</span>
                                            @if($pStep['status'] === 'success')
                                                <flux:icon.check-circle variant="mini" class="mt-0.5 size-4 shrink-0 text-green-500" />
                                            @elseif($pStep['status'] === 'error')
                                                <flux:icon.x-circle variant="mini" class="mt-0.5 size-4 shrink-0" />
                                            @else
                                                <flux:icon.arrow-path variant="mini" class="mt-0.5 size-4 shrink-0 animate-spin" />
                                            @endif
                                            <span>{{ $pStep['message'] }}</span>
                                        </div>
                                    @endforeach
                                </div>

                                @if($addingNode)
                                    </div>
                                @endif

                                @if($addNodeComplete)
                                    <flux:callout variant="success" icon="check-circle" class="mt-4">
                                        <flux:callout.heading>{{ __('Node Added Successfully!') }}</flux:callout.heading>
                                    </flux:callout>
                                    <div class="mt-3">
                                        <flux:button wire:click="dismissAddNodeProgress" size="sm" variant="filled">{{ __('Dismiss') }}</flux:button>
                                    </div>
                                @elseif(!$addingNode)
                                    {{-- Job failed --}}
                                    <div class="mt-3 flex gap-2">
                                        <flux:button wire:click="retryAddNode({{ $addingNodeId }})" size="sm" variant="primary" icon="arrow-path">
                                            {{ __('Retry') }}
                                        </flux:button>
                                        <flux:button wire:click="dismissAddNodeProgress" size="sm">{{ __('Dismiss') }}</flux:button>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Firewall management --}}
                        @if($firewallNodeId === $node->id)
                            <div class="mt-4 border-t border-neutral-200 dark:border-neutral-700" style="padding-top: 1.25rem;">
                                <div class="flex items-center justify-between mb-3">
                                    <flux:heading size="sm">{{ __('Firewall Rules (Redis / Sentinel)') }}</flux:heading>
                                    <flux:button wire:click="toggleFirewall({{ $node->id }})" size="xs">
                                        {{ __('Close') }}
                                    </flux:button>
                                </div>

                                {{-- Current rules --}}
                                @if(count($firewallRules) > 0)
                                    <div class="mb-4 space-y-1">
                                        @foreach($firewallRules as $rule)
                                            <div class="flex items-center justify-between rounded bg-zinc-50 px-3 py-1.5 dark:bg-zinc-800">
                                                <code class="font-mono text-xs">{{ $rule['rule'] }}</code>
                                                <flux:button size="xs" variant="danger"
                                                    wire:click="removeFirewallRule({{ $rule['number'] }})"
                                                    wire:confirm="{{ __('Remove this firewall rule?') }}"
                                                    icon="trash" />
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <flux:text class="mb-4 text-xs">{{ __('No firewall rules found for Redis/Sentinel ports.') }}</flux:text>
                                @endif

                                {{-- Add new rule --}}
                                <flux:text class="mb-2 text-xs">{{ __('Allow a new IP or CIDR range to connect on Redis and Sentinel ports.') }}</flux:text>
                                <div class="flex items-center gap-2">
                                    <div class="w-64">
                                        <flux:input wire:model="firewallNewIp" wire:keydown.enter="addFirewallRule" placeholder="e.g. 10.0.0.50 or 10.0.0.0/24" size="sm" />
                                    </div>
                                    <flux:button wire:click="addFirewallRule" size="sm" variant="primary" icon="plus">
                                        {{ __('Add Rule') }}
                                    </flux:button>
                                    <flux:button wire:click="configureAllFirewallRules" size="sm" variant="filled" icon="shield-check">
                                        {{ __('Configure All') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Add Node --}}
            <div class="mt-4 flex justify-end gap-2">
                <flux:button wire:click="$set('showAddNodeModal', true)" variant="primary" icon="plus">
                    {{ __('Add Node') }}
                </flux:button>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Recovery Actions --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <flux:card class="border-red-200 dark:border-red-900/50">
            <flux:heading size="lg" class="!text-red-500">{{ __('Recovery Actions') }}</flux:heading>
            <flux:text class="mb-4">{{ __('Use these actions when nodes are in a degraded or failed state.') }}</flux:text>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Sentinel Failover') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Promote a replica to master. Sentinel coordinates the topology change across all nodes.') }}</flux:text>
                    @if($cluster->nodes->where('role.value', 'master')->isNotEmpty() && $cluster->nodes->where('role.value', 'replica')->isNotEmpty())
                        <flux:button size="xs" variant="danger"
                            wire:click="failover"
                            wire:confirm="{{ __('This will trigger a Sentinel failover, promoting a replica to master. Continue?') }}"
                            icon="bolt">
                            {{ __('Trigger Failover') }}
                        </flux:button>
                    @else
                        <flux:text class="text-xs text-zinc-400">{{ __('Requires at least one master and one replica.') }}</flux:text>
                    @endif
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Force Resync Replica') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Force a replica to re-establish replication with the master. Triggers a full data transfer.') }}</flux:text>
                    @php $replicas = $cluster->nodes->where('role.value', 'replica'); @endphp
                    @if($replicas->isNotEmpty())
                        @foreach($replicas as $replica)
                            <flux:button size="xs" variant="danger"
                                wire:click="forceResync({{ $replica->id }})"
                                wire:confirm="{{ __('This will force :name to do a full resync with the master. Continue?', ['name' => $replica->name]) }}"
                                class="mb-1 mr-1">
                                {{ $replica->name }}
                            </flux:button>
                        @endforeach
                    @else
                        <flux:text class="text-xs text-zinc-400">{{ __('No replica nodes available.') }}</flux:text>
                    @endif
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Reset Sentinel State') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Clear stale Sentinel state. Useful after nodes have been added or removed and Sentinel has ghost entries.') }}</flux:text>
                    <flux:button size="xs" variant="danger"
                        wire:click="resetSentinel"
                        wire:confirm="{{ __('This will reset Sentinel state for this cluster. Stale entries will be removed. Continue?') }}">
                        {{ __('Reset Sentinel') }}
                    </flux:button>
                </div>
            </div>

            {{-- Restart services --}}
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Restart Redis Service') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Restart the redis-server systemd service on a specific node.') }}</flux:text>
                    <div class="flex flex-wrap gap-1">
                        @foreach($cluster->nodes as $node)
                            <flux:button size="xs" variant="danger"
                                wire:click="restartRedis({{ $node->id }})"
                                wire:confirm="{{ __('Restart Redis on :name? Clients will be disconnected briefly.', ['name' => $node->name]) }}"
                                class="mb-1">
                                {{ $node->name }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Restart Sentinel Service') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Restart the redis-sentinel systemd service on a specific node.') }}</flux:text>
                    <div class="flex flex-wrap gap-1">
                        @foreach($cluster->nodes as $node)
                            <flux:button size="xs" variant="danger"
                                wire:click="restartSentinel({{ $node->id }})"
                                wire:confirm="{{ __('Restart Sentinel on :name? Monitoring will be briefly interrupted.', ['name' => $node->name]) }}"
                                class="mb-1">
                                {{ $node->name }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>
            </div>
        </flux:card>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Maintenance Actions --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <flux:card>
            <flux:heading size="lg">{{ __('Maintenance') }}</flux:heading>
            <flux:text class="mb-4">{{ __('Routine maintenance operations for your Redis cluster.') }}</flux:text>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('RDB Snapshot') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Trigger a background RDB save on a node.') }}</flux:text>
                    <div class="flex flex-wrap gap-1">
                        @foreach($cluster->nodes as $node)
                            <flux:button size="xs" variant="filled"
                                wire:click="triggerBgsave({{ $node->id }})"
                                class="mb-1">
                                {{ $node->name }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('AOF Rewrite') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Compact the append-only file to reclaim disk space.') }}</flux:text>
                    <div class="flex flex-wrap gap-1">
                        @foreach($cluster->nodes as $node)
                            <flux:button size="xs" variant="filled"
                                wire:click="triggerAofRewrite({{ $node->id }})"
                                class="mb-1">
                                {{ $node->name }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Memory Purge') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Release unused memory back to the operating system.') }}</flux:text>
                    <div class="flex flex-wrap gap-1">
                        @foreach($cluster->nodes as $node)
                            <flux:button size="xs" variant="filled"
                                wire:click="memoryPurge({{ $node->id }})"
                                class="mb-1">
                                {{ $node->name }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Flush Sentinel Config') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Rewrite Sentinel configuration files to disk on all nodes.') }}</flux:text>
                    <flux:button size="xs" variant="filled" wire:click="flushSentinelConfig">
                        {{ __('Flush Config') }}
                    </flux:button>
                </div>
            </div>
        </flux:card>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Add Node Modal --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <flux:modal wire:model="showAddNodeModal">
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('Add Redis Node') }}</flux:heading>

                {{-- Server selection --}}
                @if($availableServers->isNotEmpty())
                    <flux:radio.group wire:model.live="addNodeServerMode" label="{{ __('Server') }}">
                        <flux:radio value="existing" label="{{ __('Use an existing server') }}" />
                        <flux:radio value="new" label="{{ __('Configure a new server') }}" />
                    </flux:radio.group>
                @endif

                @if($addNodeServerMode === 'existing' && $availableServers->isNotEmpty())
                    <div class="space-y-2">
                        @foreach($availableServers as $server)
                            <label wire:click="$set('addNodeSelectedServerId', {{ $server->id }})" @class([
                                'flex cursor-pointer items-center justify-between rounded-lg border p-4 transition',
                                'border-red-500 bg-red-50 dark:bg-red-900/10' => $addNodeSelectedServerId === $server->id,
                                'border-neutral-200 hover:border-neutral-300 dark:border-neutral-700 dark:hover:border-neutral-600' => $addNodeSelectedServerId !== $server->id,
                            ])>
                                <div class="flex items-center gap-3">
                                    <flux:icon.server variant="mini" @class([
                                        'size-5',
                                        'text-red-500' => $addNodeSelectedServerId === $server->id,
                                        'text-zinc-400' => $addNodeSelectedServerId !== $server->id,
                                    ]) />
                                    <div>
                                        <div class="font-medium">{{ $server->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $server->ssh_user . '@' . $server->host . ':' . $server->ssh_port }}</div>
                                    </div>
                                </div>
                                @if($addNodeSelectedServerId === $server->id)
                                    <flux:icon.check-circle variant="mini" class="size-5 text-red-500" />
                                @endif
                            </label>
                        @endforeach
                        @error('addNodeSelectedServerId') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror
                    </div>

                    <flux:separator />

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input wire:model="addNodeName" label="{{ __('Node Name (optional)') }}" placeholder="e.g. redis-replica-2" />
                        <flux:input wire:model.number="addNodeRedisPort" type="number" label="{{ __('Redis Port') }}" />
                        <flux:input wire:model.number="addNodeSentinelPort" type="number" label="{{ __('Sentinel Port') }}" />
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input wire:model="addNodeHost" label="{{ __('Host IP / Hostname') }}" placeholder="e.g. 192.168.1.12" />
                        <flux:input wire:model="addNodeName" label="{{ __('Node Name (optional)') }}" placeholder="e.g. redis-replica-2" />
                        <flux:input wire:model="addNodeSshUser" label="{{ __('SSH User') }}" />
                        <flux:input wire:model.number="addNodeSshPort" type="number" label="{{ __('SSH Port') }}" />
                        <flux:input wire:model.number="addNodeRedisPort" type="number" label="{{ __('Redis Port') }}" />
                        <flux:input wire:model.number="addNodeSentinelPort" type="number" label="{{ __('Sentinel Port') }}" />
                    </div>

                    {{-- SSH Key --}}
                    <div>
                        <flux:radio.group wire:model="addNodeSshKeyMode" label="{{ __('SSH Key') }}">
                            <flux:radio value="generate" label="{{ __('Generate new key') }}" />
                            <flux:radio value="existing" label="{{ __('Paste existing key') }}" />
                        </flux:radio.group>

                        @if($addNodeSshKeyMode === 'generate')
                            @if(!$addNodeKeyPair)
                                <flux:button wire:click="generateAddNodeKey" size="sm" class="mt-2" icon="key">
                                    {{ __('Generate Keypair') }}
                                </flux:button>
                            @else
                                <flux:callout variant="filled" class="mt-2">
                                    <flux:callout.heading>{{ __('Add this public key to the new node') }}</flux:callout.heading>
                                    <flux:callout.text>
                                        SSH into <code class="font-mono font-bold">{{ $addNodeHost ?: 'your server' }}</code> and run:
                                    </flux:callout.text>
                                    <div x-data="{ copied: false, cmd: @js('mkdir -p ~/.ssh && echo "' . $addNodeKeyPair['public'] . '" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys') }">
                                        <code class="mt-2 block rounded bg-zinc-900 p-2 text-xs text-green-400 break-all">mkdir -p ~/.ssh && echo "{{ $addNodeKeyPair['public'] }}" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys</code>
                                        <flux:button size="xs" variant="subtle" class="mt-1"
                                            x-on:click="navigator.clipboard.writeText(cmd); copied = true; setTimeout(() => copied = false, 2000)"
                                            icon="clipboard-document">
                                            <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy Command') }}'"></span>
                                        </flux:button>
                                    </div>
                                </flux:callout>
                            @endif
                        @else
                            <flux:textarea wire:model="addNodePrivateKey" rows="4" placeholder="Paste private key..." class="mt-2 font-mono text-xs" />
                        @endif
                    </div>

                    {{-- Test SSH --}}
                    <div class="flex items-center gap-2">
                        <flux:button wire:click="testSsh" size="sm" variant="filled" icon="signal">
                            {{ __('Test SSH Connection') }}
                        </flux:button>
                        @if($sshTestResult)
                            <flux:badge :color="$sshTestResult === 'success' ? 'green' : 'red'">
                                {{ $sshTestResult === 'success' ? __('Connected') : __('Failed') }}
                            </flux:badge>
                        @endif
                    </div>
                @endif

                {{-- Actions --}}
                <div class="flex gap-2">
                    <flux:button wire:click="addNode" variant="primary">{{ __('Add Node') }}</flux:button>
                    <flux:button wire:click="$set('showAddNodeModal', false)">{{ __('Cancel') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </div>
