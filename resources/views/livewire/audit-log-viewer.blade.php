<x-layouts::app :title="__('Audit Log')">
    <div class="flex flex-col gap-6">
        <flux:heading size="xl">{{ __('Audit Log') }}</flux:heading>

        {{-- Filters --}}
        <div class="flex items-center gap-4">
            <flux:input wire:model.live.debounce.300ms="actionFilter" placeholder="{{ __('Filter by action...') }}" icon="magnifying-glass" class="max-w-xs" />
            <flux:select wire:model.live="statusFilter" class="max-w-[10rem]">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                <flux:select.option value="success">{{ __('Success') }}</flux:select.option>
                <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
                <flux:select.option value="started">{{ __('Started') }}</flux:select.option>
            </flux:select>
        </div>

        {{-- Log table --}}
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column>{{ __('Action') }}</flux:table.column>
                <flux:table.column>{{ __('Cluster') }}</flux:table.column>
                <flux:table.column>{{ __('Node') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Duration') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($logs as $log)
                    <flux:table.row>
                        <flux:table.cell class="whitespace-nowrap text-zinc-500">{{ $log->created_at->format('Y-m-d H:i:s') }}</flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $log->action }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $log->cluster?->name ?? '-' }}</flux:table.cell>
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
                    @if($log->command || $log->error_message)
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="!py-1">
                                @if($log->command)
                                    <flux:text class="text-xs"><strong>{{ __('Command:') }}</strong> <code>{{ Str::limit($log->command, 200) }}</code></flux:text>
                                @endif
                                @if($log->error_message)
                                    <flux:text class="text-xs !text-red-500">{{ $log->error_message }}</flux:text>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                            {{ __('No audit log entries found.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div>{{ $logs->links() }}</div>
    </div>
</x-layouts::app>
