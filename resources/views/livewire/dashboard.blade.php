<x-layouts::app :title="__('Dashboard')">
    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('InnoDB Cluster Dashboard') }}</flux:heading>
            <flux:button variant="primary" href="{{ route('cluster.create') }}" wire:navigate icon="plus">
                {{ __('Create New Cluster') }}
            </flux:button>
        </div>

        @if($clusters->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-xl border border-neutral-200 p-16 dark:border-neutral-700">
                <flux:icon.server class="mb-4 size-16 text-zinc-400" />
                <flux:heading size="lg" class="mb-2">{{ __('No clusters configured') }}</flux:heading>
                <flux:text class="mb-6">{{ __('Get started by creating your first InnoDB Cluster.') }}</flux:text>
                <flux:button variant="primary" href="{{ route('cluster.create') }}" wire:navigate>
                    {{ __('Create Cluster') }}
                </flux:button>
            </div>
        @else
            {{-- Cluster cards --}}
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach($clusters as $cluster)
                    <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                        <div class="mb-4 flex items-center justify-between">
                            <flux:heading size="lg">{{ $cluster->name }}</flux:heading>
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
                            <flux:button size="sm" href="{{ route('cluster.manage', $cluster) }}" wire:navigate class="flex-1">
                                {{ __('Manage') }}
                            </flux:button>
                            <flux:button size="sm" href="{{ route('cluster.routers', $cluster) }}" wire:navigate>
                                {{ __('Routers') }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

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
                                <flux:table.cell class="text-zinc-500">{{ $log->node?->name ?? '-' }}</flux:table.cell>
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
</x-layouts::app>
