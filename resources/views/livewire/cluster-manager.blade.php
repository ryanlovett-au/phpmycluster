<x-layouts::app :title="$cluster->name">
    <div class="flex flex-col gap-6">
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
                <flux:button wire:click="refreshStatus" wire:loading.attr="disabled" variant="primary" icon="arrow-path">
                    <span wire:loading.remove wire:target="refreshStatus">{{ __('Refresh Status') }}</span>
                    <span wire:loading wire:target="refreshStatus">{{ __('Refreshing...') }}</span>
                </flux:button>
                <flux:button wire:click="rescan" icon="magnifying-glass">{{ __('Rescan') }}</flux:button>
                <flux:button href="{{ route('cluster.routers', $cluster) }}" wire:navigate icon="server-stack">{{ __('Routers') }}</flux:button>
            </div>
        </div>

        {{-- Action message --}}
        @if($actionMessage)
            <flux:callout :variant="match($actionStatus) { 'success' => 'success', 'error' => 'danger', default => 'info' }">
                <flux:callout.text>{{ $actionMessage }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Nodes --}}
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
                                <flux:heading>{{ $node->name }}</flux:heading>
                                <flux:text class="text-xs">{{ $node->host }}:{{ $node->mysql_port }}</flux:text>
                            </div>
                            <flux:badge size="sm" :color="match($node->role->value) {
                                'primary' => 'purple',
                                'secondary' => 'blue',
                                'access' => 'orange',
                                default => 'zinc',
                            }">{{ ucfirst($node->role->value) }}</flux:badge>
                            <flux:text class="text-xs">{{ ucfirst($node->status->value) }}</flux:text>
                        </div>

                        <div class="flex items-center gap-2">
                            @if($node->isDbNode())
                                <flux:button size="sm" href="{{ route('node.logs', $node) }}" wire:navigate icon="document-text">
                                    {{ __('Logs') }}
                                </flux:button>

                                @if($node->status->value === 'offline' || $node->status->value === 'error')
                                    <flux:button size="sm" variant="warning" wire:click="rejoinNode({{ $node->id }})" icon="arrow-uturn-left">
                                        {{ __('Rejoin') }}
                                    </flux:button>
                                @endif

                                @if($node->role->value !== 'primary')
                                    <flux:button size="sm" variant="danger"
                                        wire:click="removeNode({{ $node->id }})"
                                        wire:confirm="{{ __('Are you sure you want to remove :name from the cluster?', ['name' => $node->name]) }}">
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endif
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
                                <flux:text class="text-xs">{{ __('Transactions') }}</flux:text>
                                <p class="font-mono text-xs">{{ $node->last_health_json['transactions'] ?? '-' }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Add Node --}}
        @if(!$showAddNode)
            <flux:button wire:click="$set('showAddNode', true)" variant="primary" icon="plus">
                {{ __('Add Node to Cluster') }}
            </flux:button>
        @else
            <flux:card>
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
                            <flux:callout variant="warning" class="mt-2">
                                <flux:callout.heading>{{ __('Add this public key to the new node\'s authorized_keys:') }}</flux:callout.heading>
                                <code class="mt-1 block rounded bg-zinc-900 p-2 text-xs text-green-400 break-all">{{ $newNodeKeyPair['public'] }}</code>
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

        {{-- Recovery Actions --}}
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
                    <flux:button size="xs" variant="warning" wire:click="rescan">{{ __('Rescan Cluster') }}</flux:button>
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
</x-layouts::app>
