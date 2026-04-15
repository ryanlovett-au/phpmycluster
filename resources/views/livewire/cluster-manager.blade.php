    <div class="flex flex-col gap-6">
        {{-- Poll for refresh batch completion --}}
        @if($refreshing)
            <div wire:poll.1s="pollRefresh"></div>
        @endif

        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $cluster->name }}</flux:heading>
                    <flux:badge :color="match($cluster->status->value) {
                        'online' => 'green',
                        'degraded' => 'yellow',
                        'offline', 'error' => 'red',
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
                <flux:callout :variant="match($actionStatus) { 'success' => 'success', 'error' => 'danger', default => 'info' }">
                    <flux:callout.text>{{ $actionMessage }}</flux:callout.text>
                </flux:callout>
            </div>
        @endif

        {{-- Failed provisioning detection --}}
        @if(in_array($cluster->status->value, ['pending', 'error']) && $nodes->every(fn($n) => $n->role->value === 'pending'))
            <flux:callout variant="danger">
                <flux:callout.heading>{{ __('Provisioning Incomplete') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('This cluster has not been fully provisioned. No primary node has been established. You can re-provision the primary node or delete this cluster and start again.') }}
                </flux:callout.text>
                <div class="mt-3 flex gap-2">
                    <flux:button variant="primary" wire:click="reprovision" icon="arrow-path">
                        {{ __('Re-provision Primary Node') }}
                    </flux:button>
                    <flux:button variant="danger"
                        wire:click="deleteCluster"
                        wire:confirm="{{ __('Are you sure you want to delete this cluster and all its nodes? This cannot be undone.') }}">
                        {{ __('Delete Cluster') }}
                    </flux:button>
                </div>
            </flux:callout>
        @endif

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- DB Nodes --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Database Nodes') }}</flux:heading>
            <div class="grid gap-4">
                @foreach($nodes as $node)
                    <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div @class([
                                    'size-3 rounded-full',
                                    'bg-green-500' => $node->status->value === 'online',
                                    'bg-yellow-500 animate-pulse' => $node->status->value === 'recovering',
                                    'bg-red-500' => in_array($node->status->value, ['offline', 'error', 'unreachable']),
                                    'bg-zinc-400' => $node->status->value === 'unknown',
                                ])></div>
                                <div>
                                    @if($renamingNodeId === $node->id)
                                        <div class="flex items-center gap-2">
                                            <flux:input wire:model="renameNodeValue" wire:keydown.enter="saveRename" wire:keydown.escape="cancelRename" size="sm" class="!py-0.5" autofocus />
                                            <flux:button size="xs" variant="primary" wire:click="saveRename" icon="check">{{ __('Save') }}</flux:button>
                                            <flux:button size="xs" wire:click="cancelRename" icon="x-mark">{{ __('Cancel') }}</flux:button>
                                        </div>
                                    @else
                                        <flux:heading class="group cursor-pointer" wire:click="startRename({{ $node->id }})">
                                            {{ $node->name }}
                                            <flux:icon.pencil-square variant="mini" class="ml-1 inline size-3.5 text-zinc-400 opacity-0 group-hover:opacity-100" />
                                        </flux:heading>
                                    @endif
                                    <flux:text class="text-xs">{{ $node->server->host }}:{{ $node->mysql_port }}</flux:text>
                                </div>
                                <flux:badge size="sm" :color="match($node->role->value) {
                                    'primary' => 'purple',
                                    'secondary' => 'blue',
                                    default => 'zinc',
                                }">{{ ucfirst($node->role->value) }}</flux:badge>
                                <flux:text class="text-xs">{{ ucfirst($node->status->value) }}</flux:text>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:button size="sm" href="{{ route('node.logs', $node) }}" wire:navigate icon="document-text">
                                    {{ __('Logs') }}
                                </flux:button>

                                @if($node->role->value === 'pending')
                                    {{-- Pending node: never joined the cluster, offer retry or delete --}}
                                    <flux:button size="sm" variant="primary" wire:click="retryAddNode({{ $node->id }})" icon="arrow-path">
                                        {{ __('Retry Provision') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="danger"
                                        wire:click="deleteNode({{ $node->id }})"
                                        wire:confirm="{{ __('Are you sure you want to delete :name? This cannot be undone.', ['name' => $node->name]) }}">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @elseif($node->role->value !== 'primary')
                                    {{-- Secondary node: part of the cluster --}}
                                    @if(in_array($node->status->value, ['offline', 'error']))
                                        <flux:button size="sm" variant="filled" wire:click="rejoinNode({{ $node->id }})" icon="arrow-uturn-left">
                                            {{ __('Rejoin') }}
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
                                    <flux:text class="text-xs">{{ __('Member State') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->last_health_json['memberState'] ?? '-' }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Mode') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->last_health_json['mode'] ?? '-' }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Version') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->last_health_json['version'] ?? $node->mysql_version ?? '-' }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Replication Lag') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $node->last_health_json['replicationLag'] ?? 'n/a' }}</p>
                                </div>
                            </div>
                            @if(isset($node->last_health_json['transactions']))
                                @php
                                    $gtidSet = $node->last_health_json['transactions']['committedAllMembers'] ?? '';
                                    // Extract the highest sequence number from GTID set (e.g. "uuid:1-1441,uuid:1-4" → 1441)
                                    $lastSeq = 0;
                                    if ($gtidSet && preg_match_all('/:(\d+)(?:-(\d+))?/', $gtidSet, $matches)) {
                                        foreach ($matches[0] as $i => $m) {
                                            $val = !empty($matches[2][$i]) ? (int) $matches[2][$i] : (int) $matches[1][$i];
                                            if ($val > $lastSeq) $lastSeq = $val;
                                        }
                                    }
                                @endphp
                                <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4">
                                    <div>
                                        <flux:text class="text-xs">{{ __('Last Synced Transaction') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $lastSeq ? number_format($lastSeq) : '-' }}</p>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs">{{ __('In Queue') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $node->last_health_json['transactions']['inQueueCount'] ?? '0' }}</p>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs">{{ __('Conflicts') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $node->last_health_json['transactions']['conflictsDetectedCount'] ?? '0' }}</p>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs">{{ __('Rollbacks') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $node->last_health_json['transactions']['rollbackCount'] ?? '0' }}</p>
                                    </div>
                                </div>
                            @endif
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
                    </div>
                @endforeach
            </div>

            {{-- Add DB Node --}}
            @if(!$showAddNode && !$addingNode)
                <div class="mt-4 flex justify-end">
                    <flux:button wire:click="$set('showAddNode', true)" variant="primary" icon="plus">
                        {{ __('Add DB Node') }}
                    </flux:button>
                </div>
            @elseif($showAddNode)
                <flux:card class="mt-4">
                    <flux:heading size="lg" class="mb-4">{{ __('Add New DB Node') }}</flux:heading>

                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input wire:model="newNodeHost" label="{{ __('Host IP / Hostname') }}" placeholder="e.g. 192.168.1.12" />
                        <flux:input wire:model="newNodeName" label="{{ __('Node Name (optional)') }}" placeholder="e.g. db-secondary-2" />
                        <flux:input wire:model="newNodeSshUser" label="{{ __('SSH User') }}" />
                        <flux:input wire:model.number="newNodeSshPort" type="number" label="{{ __('SSH Port') }}" />
                    </div>

                    {{-- SSH Key --}}
                    <div class="mb-4">
                        <flux:radio.group wire:model="newNodeSshKeyMode" label="{{ __('SSH Key') }}">
                            <flux:radio value="generate" label="{{ __('Generate new key') }}" />
                            <flux:radio value="existing" label="{{ __('Paste existing key') }}" />
                        </flux:radio.group>

                        @if($newNodeSshKeyMode === 'generate')
                            @if(!$newNodeKeyPair)
                                <flux:button wire:click="generateNewNodeKey" size="sm" class="mt-2" icon="key">
                                    {{ __('Generate Keypair') }}
                                </flux:button>
                            @else
                                <flux:callout variant="filled" class="mt-2">
                                    <flux:callout.heading>{{ __('Add this public key to the new node') }}</flux:callout.heading>
                                    <flux:callout.text>
                                        SSH into <code class="font-mono font-bold">{{ $newNodeHost ?: 'your server' }}</code> and run:
                                    </flux:callout.text>
                                    <div x-data="{ copied: false, cmd: @js('mkdir -p ~/.ssh && echo "' . $newNodeKeyPair['public'] . '" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys') }">
                                        <code class="mt-2 block rounded bg-zinc-900 p-2 text-xs text-green-400 break-all">mkdir -p ~/.ssh && echo "{{ $newNodeKeyPair['public'] }}" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys</code>
                                        <flux:button size="xs" variant="subtle" class="mt-1"
                                            x-on:click="navigator.clipboard.writeText(cmd); copied = true; setTimeout(() => copied = false, 2000)"
                                            icon="clipboard-document">
                                            <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy Command') }}'"></span>
                                        </flux:button>
                                    </div>
                                </flux:callout>
                            @endif
                        @else
                            <flux:textarea wire:model="newNodePrivateKey" rows="4" placeholder="Paste private key..." class="mt-2 font-mono text-xs" />
                        @endif
                    </div>

                    <div class="flex gap-2">
                        <flux:button wire:click="addNode" variant="primary">{{ __('Add Node') }}</flux:button>
                        <flux:button wire:click="$set('showAddNode', false)">{{ __('Cancel') }}</flux:button>
                    </div>
                </flux:card>
            @endif
        </div>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Router Nodes --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('MySQL Routers') }}</flux:heading>

            @if($routerNodes->isNotEmpty())
                <div class="grid gap-4">
                    @foreach($routerNodes as $routerNode)
                        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div @class([
                                        'size-3 rounded-full',
                                        'bg-green-500' => $routerNode->status->value === 'online',
                                        'bg-red-500' => in_array($routerNode->status->value, ['offline', 'error']),
                                        'bg-zinc-400' => $routerNode->status->value === 'unknown',
                                    ])></div>
                                    <div>
                                        @if($renamingNodeId === $routerNode->id)
                                            <div class="flex items-center gap-2">
                                                <flux:input wire:model="renameNodeValue" wire:keydown.enter="saveRename" wire:keydown.escape="cancelRename" size="sm" class="!py-0.5" autofocus />
                                                <flux:button size="xs" variant="primary" wire:click="saveRename" icon="check">{{ __('Save') }}</flux:button>
                                                <flux:button size="xs" wire:click="cancelRename" icon="x-mark">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        @else
                                            <flux:heading class="group cursor-pointer" wire:click="startRename({{ $routerNode->id }})">
                                                {{ $routerNode->name }}
                                                <flux:icon.pencil-square variant="mini" class="ml-1 inline size-3.5 text-zinc-400 opacity-0 group-hover:opacity-100" />
                                            </flux:heading>
                                        @endif
                                        <flux:text class="text-xs">{{ $routerNode->server->host }}</flux:text>
                                    </div>
                                    <flux:badge size="sm" color="orange">{{ __('Router') }}</flux:badge>
                                    <flux:text class="text-xs">{{ ucfirst($routerNode->status->value) }}</flux:text>
                                </div>

                                <div class="flex items-center gap-2">
                                    <flux:button size="sm" wire:click="toggleFirewall({{ $routerNode->id }})" icon="shield-check">
                                        {{ __('Firewall') }}
                                    </flux:button>
                                    <flux:button size="sm" href="{{ route('node.logs', $routerNode) }}" wire:navigate icon="document-text">
                                        {{ __('Logs') }}
                                    </flux:button>
                                    @if(in_array($routerNode->status->value, ['error', 'unknown']))
                                        <flux:button size="sm" variant="primary" wire:click="retrySetupRouter({{ $routerNode->id }})" icon="arrow-path">
                                            {{ __('Retry Setup') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="danger"
                                            wire:click="deleteRouter({{ $routerNode->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete :name?', ['name' => $routerNode->name]) }}">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    @elseif($routerNode->status->value === 'offline')
                                        <flux:button size="sm" variant="danger"
                                            wire:click="deleteRouter({{ $routerNode->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete :name?', ['name' => $routerNode->name]) }}">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>

                            {{-- Router connection details --}}
                            @if($routerNode->status->value === 'online')
                                <div class="mt-4 grid grid-cols-2 gap-4">
                                    <div>
                                        <flux:text class="text-xs">{{ __('Read/Write') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $routerNode->server->host }}:6446</p>
                                    </div>
                                    <div>
                                        <flux:text class="text-xs">{{ __('Read Only') }}</flux:text>
                                        <p class="font-mono text-xs">{{ $routerNode->server->host }}:6447</p>
                                    </div>
                                </div>
                            @endif

                            {{-- Firewall management --}}
                            @if($firewallRouterId === $routerNode->id)
                                <div class="mt-4 border-t border-neutral-200 dark:border-neutral-700" style="padding-top: 1.25rem;">
                                    <div class="flex items-center justify-between mb-3">
                                        <flux:heading size="sm">{{ __('Firewall Rules (6446 / 6447)') }}</flux:heading>
                                        <flux:button wire:click="toggleFirewall({{ $routerNode->id }})" size="xs">
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
                                        <flux:text class="mb-4 text-xs">{{ __('No rules found for ports 6446/6447.') }}</flux:text>
                                    @endif

                                    {{-- Add new rule --}}
                                    <flux:text class="mb-2 text-xs">{{ __('Allow a new IP or CIDR range to connect on ports 6446 (R/W) and 6447 (R/O).') }}</flux:text>
                                    <div class="flex items-center gap-2">
                                        <div class="w-64">
                                            <flux:input wire:model="firewallNewIp" wire:keydown.enter="addFirewallRule" placeholder="e.g. 10.0.0.50 or 10.0.0.0/24" size="sm" />
                                        </div>
                                        <flux:button wire:click="addFirewallRule" size="sm" variant="primary" icon="plus">
                                            {{ __('Add Rule') }}
                                        </flux:button>
                                    </div>
                                </div>
                            @endif

                            {{-- Router setup progress --}}
                            @if($settingUpRouterId === $routerNode->id && count($setupRouterSteps) > 0)
                                <div class="mt-4 border-t border-neutral-200 pb-2 dark:border-neutral-700" style="padding-top: 1.25rem;">
                                    @if($settingUpRouter)
                                        <div wire:poll.2s="pollSetupRouter">
                                    @endif

                                    <div class="space-y-2">
                                        @foreach($setupRouterSteps as $pStep)
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

                                    @if($settingUpRouter)
                                        </div>
                                    @endif

                                    @if($setupRouterComplete)
                                        <flux:callout variant="success" icon="check-circle" class="mt-4">
                                            <flux:callout.heading>{{ __('Router Setup Complete!') }}</flux:callout.heading>
                                        </flux:callout>
                                        <div class="mt-3">
                                            <flux:button wire:click="dismissRouterProgress" size="sm" variant="filled">{{ __('Dismiss') }}</flux:button>
                                        </div>
                                    @elseif(!$settingUpRouter)
                                        {{-- Job failed --}}
                                        <div class="mt-3 flex gap-2">
                                            <flux:button wire:click="retrySetupRouter({{ $settingUpRouterId }})" size="sm" variant="primary" icon="arrow-path">
                                                {{ __('Retry') }}
                                            </flux:button>
                                            <flux:button wire:click="dismissRouterProgress" size="sm">{{ __('Dismiss') }}</flux:button>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Add Router --}}
            @if(!$showAddRouter && !$settingUpRouter)
                <div class="mt-4 flex justify-end">
                    <flux:button wire:click="$set('showAddRouter', true)" variant="primary" icon="plus">
                        {{ __('Add Router') }}
                    </flux:button>
                </div>
            @elseif($showAddRouter)
                <flux:card class="mt-4">
                    <flux:heading size="lg" class="mb-2">{{ __('Set Up New Router') }}</flux:heading>
                    <flux:text class="mb-4">{{ __('This will SSH into the target machine, install MySQL Router, configure the firewall, and bootstrap the router against your cluster.') }}</flux:text>

                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input wire:model="routerHost" label="{{ __('Host IP / Hostname') }}" placeholder="e.g. 192.168.1.20" />
                        <flux:input wire:model="routerName" label="{{ __('Node Name (optional)') }}" placeholder="e.g. router-app-1" />
                        <flux:input wire:model="routerSshUser" label="{{ __('SSH User') }}" />
                        <flux:input wire:model.number="routerSshPort" type="number" label="{{ __('SSH Port') }}" />
                    </div>
                    <flux:text class="mb-4 text-xs">{{ __('Router ports (6446/6447) will only be accessible from localhost by default. Use the Firewall button after setup to allow remote access.') }}</flux:text>

                    {{-- SSH Key --}}
                    <div class="mb-4">
                        <flux:radio.group wire:model="routerSshKeyMode" label="{{ __('SSH Key') }}">
                            <flux:radio value="generate" label="{{ __('Generate new key') }}" />
                            <flux:radio value="existing" label="{{ __('Paste existing key') }}" />
                        </flux:radio.group>

                        @if($routerSshKeyMode === 'generate')
                            @if(!$routerKeyPair)
                                <flux:button wire:click="generateRouterKey" size="sm" class="mt-2" icon="key">
                                    {{ __('Generate Keypair') }}
                                </flux:button>
                            @else
                                <div class="mt-2 space-y-3">
                                    <flux:callout variant="warning" icon="exclamation-triangle">
                                        <flux:callout.heading>{{ __('Add this public key to the router server') }}</flux:callout.heading>
                                        <flux:callout.text>
                                            SSH into <code class="font-mono font-bold">{{ $routerHost ?: 'your server' }}</code> and run:
                                        </flux:callout.text>
                                        <div x-data="{ copied: false, cmd: @js('mkdir -p ~/.ssh && echo "' . $routerKeyPair['public'] . '" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys') }">
                                            <code class="mt-2 block rounded bg-zinc-900 p-2 text-xs text-green-400 break-all">mkdir -p ~/.ssh && echo "{{ $routerKeyPair['public'] }}" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys</code>
                                            <flux:button size="xs" variant="subtle" class="mt-1"
                                                x-on:click="navigator.clipboard.writeText(cmd); copied = true; setTimeout(() => copied = false, 2000)"
                                                icon="clipboard-document">
                                                <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy Command') }}'"></span>
                                            </flux:button>
                                        </div>
                                    </flux:callout>
                                </div>
                            @endif
                        @else
                            <flux:textarea wire:model="routerPrivateKey" rows="4" placeholder="Paste private key..." class="mt-2 font-mono text-xs" />
                        @endif
                    </div>

                    <div class="flex gap-2">
                        <flux:button wire:click="setupRouter" variant="primary">{{ __('Set Up Router') }}</flux:button>
                        <flux:button wire:click="$set('showAddRouter', false)">{{ __('Cancel') }}</flux:button>
                    </div>
                </flux:card>
            @endif
        </div>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- MySQL Users --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('MySQL Users') }}</flux:heading>

            @if(count($mysqlUsers) > 0)
                <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                    @foreach($mysqlUsers as $mysqlUser)
                        @if(!$loop->first)
                            <div class="my-4 border-t border-neutral-200 dark:border-neutral-700"></div>
                        @endif
                        <div class="flex items-center gap-4">
                            <div class="grid flex-1 grid-cols-2 gap-4 md:grid-cols-4">
                                <div>
                                    <flux:text class="text-xs">{{ __('Username') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $mysqlUser['user'] }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Host') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $mysqlUser['host'] }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Database') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $mysqlUser['database'] ?? '*.*' }}</p>
                                </div>
                                <div>
                                    <flux:text class="text-xs">{{ __('Privileges') }}</flux:text>
                                    <p class="font-mono text-xs">{{ $mysqlUser['privileges'] ?? '-' }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <flux:modal.trigger name="user-modal">
                                    <flux:button size="xs" wire:click="openEditUser('{{ $mysqlUser['user'] }}', '{{ $mysqlUser['host'] }}')" icon="pencil-square" />
                                </flux:modal.trigger>
                                <flux:button size="xs" variant="danger"
                                    wire:click="dropUser('{{ $mysqlUser['user'] }}', '{{ $mysqlUser['host'] }}')"
                                    wire:confirm="{{ __('Are you sure you want to drop user :user@:host? This cannot be undone.', ['user' => $mysqlUser['user'], 'host' => $mysqlUser['host']]) }}"
                                    icon="trash" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl border border-neutral-200 p-6 text-center dark:border-neutral-700">
                    <flux:icon.users variant="outline" class="mx-auto mb-2 size-8 text-zinc-400" />
                    <flux:heading size="sm">{{ __('No MySQL Users') }}</flux:heading>
                    <flux:text class="mt-1 text-sm">{{ __('No application users have been created yet. Click "Add User" to create one.') }}</flux:text>
                </div>
            @endif

            <div class="mt-4 flex justify-end">
                <flux:modal.trigger name="user-modal">
                    <flux:button wire:click="openAddUser" variant="primary" icon="plus">
                        {{ __('Add User') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>
        </div>

        {{-- User Modal --}}
        <flux:modal name="user-modal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading size="lg">
                    {{ $editingUser ? __('Edit User') : __('Create MySQL User') }}
                </flux:heading>

                @if($userFormError)
                    <flux:callout variant="danger" icon="x-circle">
                        <flux:callout.text>{{ $userFormError }}</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="userFormUsername" label="{{ __('Username') }}" placeholder="e.g. app_user"
                            :disabled="$editingUser" />
                        <flux:input wire:model="userFormHost" label="{{ __('Host') }}" placeholder="e.g. % or 10.0.0.%"
                            :disabled="$editingUser" />
                    </div>

                    <flux:input wire:model="userFormPassword" type="password" label="{{ $editingUser ? __('New Password (leave blank to keep current)') : __('Password') }}" />

                    @if(!$editingUser)
                        <flux:checkbox wire:model.live="userFormCreateDb" label="{{ __('Create a database with the same name and grant full access') }}" />
                    @endif

                    @if($editingUser || !$userFormCreateDb)
                        <div class="grid grid-cols-2 gap-4">
                            <flux:select wire:model="userFormDatabase" label="{{ __('Database') }}">
                                <flux:select.option value="*">{{ __('All Databases (*.*)') }}</flux:select.option>
                                @foreach($databases as $db)
                                    <flux:select.option value="{{ $db }}">{{ $db }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model="userFormPreset" label="{{ __('Privileges') }}">
                                <flux:select.option value="readonly">{{ __('Read Only') }} (SELECT)</flux:select.option>
                                <flux:select.option value="readwrite">{{ __('Read / Write') }}</flux:select.option>
                                <flux:select.option value="admin">{{ __('Full Access') }} (ALL PRIVILEGES)</flux:select.option>
                            </flux:select>
                        </div>
                    @endif

                    @if(!$editingUser)
                        <flux:text class="text-xs">
                            {{ __('Host "%" allows connections from any IP. Use a specific IP or CIDR like "10.0.0.%" to restrict access.') }}
                        </flux:text>
                    @endif
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button wire:click="closeUserModal">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="saveUser" variant="primary">
                        {{ $editingUser ? __('Update User') : __('Create User') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Recovery Actions --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        <flux:card class="border-red-200 dark:border-red-900/50">
            <flux:heading size="lg" class="!text-red-500">{{ __('Recovery Actions') }}</flux:heading>
            <flux:text class="mb-4">{{ __('Use these actions when the cluster is in a degraded or failed state.') }}</flux:text>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Force Quorum') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('When the cluster has lost majority, restore quorum using a surviving node.') }}</flux:text>
                    @foreach($nodes->where('status.value', 'online') as $node)
                        <flux:button size="xs" variant="danger"
                            wire:click="forceQuorum({{ $node->id }})"
                            wire:confirm="{{ __('This will force quorum using :name. This is a destructive operation. Continue?', ['name' => $node->name]) }}"
                            class="mb-1 mr-1">
                            {{ __('Use') }} {{ $node->name }}
                        </flux:button>
                    @endforeach
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Reboot from Outage') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('When ALL nodes have gone offline, reboot from the node with the most recent data.') }}</flux:text>
                    @foreach($nodes as $node)
                        @if($node->isDbNode())
                            <flux:button size="xs" variant="danger"
                                wire:click="rebootCluster({{ $node->id }})"
                                wire:confirm="{{ __('This will attempt to reboot the entire cluster from :name. Continue?', ['name' => $node->name]) }}"
                                class="mb-1 mr-1">
                                {{ __('From') }} {{ $node->name }}
                            </flux:button>
                        @endif
                    @endforeach
                </div>

                <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading size="sm">{{ __('Rescan Topology') }}</flux:heading>
                    <flux:text class="mb-3 text-xs">{{ __('Detect and reconcile any changes in the cluster topology.') }}</flux:text>
                    <flux:button size="xs" variant="filled" wire:click="rescan">{{ __('Rescan Cluster') }}</flux:button>
                </div>
            </div>
        </flux:card>

        {{-- Raw Cluster Status JSON --}}
        @if($clusterStatus)
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('Cluster Status (Raw)') }}</flux:heading>
                <pre class="max-h-96 overflow-auto rounded-lg bg-zinc-900 p-4 font-mono text-xs text-zinc-300">{{ json_encode($clusterStatus, JSON_PRETTY_PRINT) }}</pre>
            </flux:card>
        @endif
    </div>
