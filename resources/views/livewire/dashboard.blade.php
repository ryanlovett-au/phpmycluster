    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Cluster Dashboard') }}</flux:heading>
            <div class="flex gap-2">
                @if($mysqlClusters->isNotEmpty() || $redisClusters->isNotEmpty())
                    <flux:button wire:click="refreshAll" wire:loading.attr="disabled" icon="arrow-path">
                        <span wire:loading.remove wire:target="refreshAll">{{ __('Refresh All') }}</span>
                        <span wire:loading wire:target="refreshAll">{{ __('Refreshing...') }}</span>
                    </flux:button>
                @endif
            </div>
        </div>

        @if($refreshMessage)
            <div x-data="{ show: true }" x-init="setTimeout(() => { show = false; $wire.set('refreshMessage', '') }, 4000)" x-show="show" x-transition.opacity.duration.500ms>
                <flux:callout variant="success">
                    <flux:callout.text>{{ $refreshMessage }}</flux:callout.text>
                </flux:callout>
            </div>
        @endif

        @if($mysqlClusters->isEmpty() && $redisClusters->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-xl border border-neutral-200 p-16 dark:border-neutral-700">
                <flux:icon.server class="mb-4 size-16 text-zinc-400" />
                <flux:heading size="lg" class="mb-2">{{ __('No clusters configured') }}</flux:heading>
                <flux:text class="mb-6">{{ __('Get started by creating your first cluster.') }}</flux:text>
                <div class="flex gap-3">
                    <flux:button variant="primary" href="{{ route('mysql.create') }}" wire:navigate>
                        {{ __('Create MySQL Cluster') }}
                    </flux:button>
                    <flux:button variant="primary" href="{{ route('redis.create') }}" wire:navigate>
                        {{ __('Create Redis Cluster') }}
                    </flux:button>
                </div>
            </div>
        @else
            {{-- MySQL Clusters --}}
            @if($mysqlClusters->isNotEmpty())
                <div class="flex items-center gap-3">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-blue-500/10">
                        <flux:icon.circle-stack class="size-5 text-blue-500" />
                    </div>
                    <flux:heading size="lg">{{ __('MySQL InnoDB Clusters') }}</flux:heading>
                    <flux:button size="xs" href="{{ route('mysql.create') }}" wire:navigate icon="plus">
                        {{ __('New') }}
                    </flux:button>
                </div>

                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($mysqlClusters as $cluster)
                        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                            <div class="mb-4 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <flux:icon.circle-stack class="size-4 text-blue-500" />
                                    <flux:heading size="lg">{{ $cluster->name }}</flux:heading>
                                </div>
                                <flux:badge :color="match($cluster->status->value) {
                                    'online' => 'green',
                                    'degraded' => 'yellow',
                                    'offline', 'error' => 'red',
                                    default => 'zinc',
                                }">{{ ucfirst($cluster->status->value) }}</flux:badge>
                            </div>

                            <div class="mb-4 space-y-1 text-sm">
                                <div class="flex justify-between">
                                    <flux:text>{{ __('DB Nodes') }}</flux:text>
                                    <span class="font-medium">{{ $cluster->dbNodes->count() }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <flux:text>{{ __('Router Nodes') }}</flux:text>
                                    <span class="font-medium">{{ $cluster->accessNodes->count() }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <flux:text>{{ __('Last Checked') }}</flux:text>
                                    <span class="font-medium">{{ $cluster->last_checked_at?->diffForHumans() ?? 'Never' }}</span>
                                </div>
                            </div>

                            {{-- Node status dots --}}
                            <div class="mb-4 flex gap-1">
                                @foreach($cluster->nodes as $node)
                                    <div title="{{ $node->name }}: {{ $node->status->value }}" @class([
                                        'size-3 rounded-full',
                                        'bg-green-500' => $node->status->value === 'online',
                                        'bg-yellow-500' => $node->status->value === 'recovering',
                                        'bg-red-500' => in_array($node->status->value, ['offline', 'error', 'unreachable']),
                                        'bg-zinc-400' => $node->status->value === 'unknown',
                                    ])></div>
                                @endforeach
                            </div>

                            <div class="flex gap-2">
                                <flux:button size="sm" href="{{ route('mysql.manage', $cluster) }}" wire:navigate class="flex-1">
                                    {{ __('Manage') }}
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Redis Clusters --}}
            @if($redisClusters->isNotEmpty())
                <div class="flex items-center gap-3">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-red-500/10">
                        <flux:icon.server-stack class="size-5 text-red-500" />
                    </div>
                    <flux:heading size="lg">{{ __('Redis Sentinel Clusters') }}</flux:heading>
                    <flux:button size="xs" href="{{ route('redis.create') }}" wire:navigate icon="plus">
                        {{ __('New') }}
                    </flux:button>
                </div>

                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($redisClusters as $cluster)
                        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                            <div class="mb-4 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <flux:icon.server-stack class="size-4 text-red-500" />
                                    <flux:heading size="lg">{{ $cluster->name }}</flux:heading>
                                </div>
                                <flux:badge :color="match($cluster->status->value) {
                                    'online' => 'green',
                                    'degraded' => 'yellow',
                                    'offline', 'error' => 'red',
                                    default => 'zinc',
                                }">{{ ucfirst($cluster->status->value) }}</flux:badge>
                            </div>

                            <div class="mb-4 space-y-1 text-sm">
                                <div class="flex justify-between">
                                    <flux:text>{{ __('Master') }}</flux:text>
                                    <span class="font-medium">{{ $cluster->nodes->where('role.value', 'master')->count() }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <flux:text>{{ __('Replicas') }}</flux:text>
                                    <span class="font-medium">{{ $cluster->nodes->where('role.value', 'replica')->count() }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <flux:text>{{ __('Last Checked') }}</flux:text>
                                    <span class="font-medium">{{ $cluster->last_checked_at?->diffForHumans() ?? 'Never' }}</span>
                                </div>
                            </div>

                            {{-- Node status dots --}}
                            <div class="mb-4 flex gap-1">
                                @foreach($cluster->nodes as $node)
                                    <div title="{{ $node->name }}: {{ $node->status->value }}" @class([
                                        'size-3 rounded-full',
                                        'bg-green-500' => $node->status->value === 'online',
                                        'bg-yellow-500' => $node->status->value === 'syncing',
                                        'bg-red-500' => in_array($node->status->value, ['offline', 'error', 'unreachable']),
                                        'bg-zinc-400' => $node->status->value === 'unknown',
                                    ])></div>
                                @endforeach
                            </div>

                            <div class="flex gap-2">
                                <flux:button size="sm" href="{{ route('redis.manage', $cluster) }}" wire:navigate class="flex-1">
                                    {{ __('Manage') }}
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Create buttons when at least one type exists but user might want the other --}}
            @if($mysqlClusters->isEmpty() || $redisClusters->isEmpty())
                <div class="flex gap-3">
                    @if($mysqlClusters->isEmpty())
                        <flux:button variant="primary" href="{{ route('mysql.create') }}" wire:navigate icon="plus">
                            {{ __('Create MySQL Cluster') }}
                        </flux:button>
                    @endif
                    @if($redisClusters->isEmpty())
                        <flux:button variant="primary" href="{{ route('redis.create') }}" wire:navigate icon="plus">
                            {{ __('Create Redis Cluster') }}
                        </flux:button>
                    @endif
                </div>
            @endif

            {{-- Recent Audit Log --}}
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <div class="mb-4 flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Recent Activity') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" href="{{ route('audit-logs') }}" wire:navigate>
                        {{ __('View all') }}
                    </flux:button>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Time') }}</flux:table.column>
                        <flux:table.column>{{ __('Action') }}</flux:table.column>
                        <flux:table.column>{{ __('Node') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Duration') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($recentLogs as $log)
                            <flux:table.row>
                                <flux:table.cell class="text-zinc-500">{{ $log->created_at->format('H:i:s') }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $log->action }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ $log->node?->name ?? $log->redisNode?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="match($log->status) {
                                        'success' => 'green',
                                        'failed' => 'red',
                                        'started' => 'blue',
                                        default => 'zinc',
                                    }">{{ $log->status }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-right text-zinc-500">{{ $log->duration_ms ? $log->duration_ms.'ms' : '-' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
