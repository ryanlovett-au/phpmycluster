    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ $node->name }} — {{ __('Logs') }}</flux:heading>
                <flux:text>{{ $node->server->host }} ({{ ucfirst($node->role->value) }})</flux:text>
            </div>
            <flux:button href="{{ route('mysql.manage', $node->cluster_id) }}" wire:navigate icon="arrow-left">
                {{ __('Back to Cluster') }}
            </flux:button>
        </div>

        {{-- Log type selector --}}
        <div class="flex gap-2">
            @foreach(['error' => 'Error Log', 'slow' => 'Slow Query', 'general' => 'General', 'systemd' => 'Systemd', 'router' => 'Router'] as $type => $label)
                <flux:button wire:click="setLogType('{{ $type }}')" size="sm" :variant="$logType === $type ? 'primary' : 'ghost'">
                    {{ __($label) }}
                </flux:button>
            @endforeach
        </div>

        {{-- Controls --}}
        <div class="flex items-center gap-4">
            <flux:select wire:model="lines" class="w-24">
                <flux:select.option value="50">50</flux:select.option>
                <flux:select.option value="100">100</flux:select.option>
                <flux:select.option value="250">250</flux:select.option>
                <flux:select.option value="500">500</flux:select.option>
                <flux:select.option value="1000">1000</flux:select.option>
            </flux:select>

            <flux:button wire:click="fetchLogs" variant="primary" icon="arrow-path">
                <span wire:loading.remove wire:target="fetchLogs">{{ __('Fetch Logs') }}</span>
                <span wire:loading wire:target="fetchLogs">{{ __('Loading...') }}</span>
            </flux:button>

            <flux:checkbox wire:model.live="autoRefresh" label="{{ __('Auto-refresh (10s)') }}" />
        </div>

        @if($autoRefresh)
            <div wire:poll.10s="fetchLogs"></div>
        @endif

        {{-- Log output --}}
        <div class="max-h-[70vh] overflow-auto rounded-xl border border-neutral-200 bg-zinc-900 p-4 font-mono text-xs text-zinc-300 whitespace-pre-wrap dark:border-neutral-700">
            @if($logContent)
                {{ $logContent }}
            @else
                <span class="text-zinc-600">{{ __('Click "Fetch Logs" to load log data from the node.') }}</span>
            @endif
        </div>
    </div>
