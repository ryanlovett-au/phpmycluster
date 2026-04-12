<x-layouts::app :title="__('MySQL Router') . ' - ' . $cluster->name">
    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('MySQL Router') }} — {{ $cluster->name }}</flux:heading>
                <flux:text>{{ __('Manage MySQL Router (access) nodes for this cluster.') }}</flux:text>
            </div>
            <flux:button href="{{ route('cluster.manage', $cluster) }}" wire:navigate icon="arrow-left">
                {{ __('Back to Cluster') }}
            </flux:button>
        </div>

        {{-- Action message --}}
        @if($actionMessage)
            <flux:callout :variant="match($actionStatus) { 'success' => 'success', 'error' => 'danger', default => 'info' }">
                <flux:callout.text>{{ $actionMessage }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Existing Router Nodes --}}
        @if($routerNodes->isNotEmpty())
            <div class="grid gap-4">
                @foreach($routerNodes as $routerNode)
                    <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div @class([
                                    'size-3 rounded-full',
                                    'bg-green-500' => $routerNode->status->value === 'online',
                                    'bg-red-500' => $routerNode->status->value === 'offline',
                                    'bg-zinc-400' => $routerNode->status->value === 'unknown',
                                ])></div>
                                <div>
                                    <flux:heading>{{ $routerNode->name }}</flux:heading>
                                    <flux:text class="text-xs">{{ $routerNode->host }}</flux:text>
                                </div>
                                <flux:badge color="orange">{{ __('Router') }}</flux:badge>
                            </div>
                            <div class="flex gap-2">
                                <flux:button size="sm" wire:click="checkRouterStatus({{ $routerNode->id }})" icon="arrow-path">
                                    {{ __('Check Status') }}
                                </flux:button>
                                <flux:button size="sm" href="{{ route('node.logs', $routerNode) }}" wire:navigate icon="document-text">
                                    {{ __('Logs') }}
                                </flux:button>
                            </div>
                        </div>
                        <div class="mt-3 text-sm">
                            <flux:text>R/W Port: <strong>6446</strong> | R/O Port: <strong>6447</strong></flux:text>
                            <flux:text>{{ __('Last checked:') }} {{ $routerNode->last_checked_at?->diffForHumans() ?? 'Never' }}</flux:text>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Add Router Node --}}
        @if(!$showAddRouter)
            <flux:button wire:click="$set('showAddRouter', true)" variant="primary" icon="plus">
                {{ __('Add Router Node') }}
            </flux:button>
        @else
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('Set Up New Router Node') }}</flux:heading>
                <flux:text class="mb-4">{{ __('This will SSH into the target machine, install MySQL Router, configure the firewall, and bootstrap the router against your cluster.') }}</flux:text>

                <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="routerHost" label="{{ __('Host IP / Hostname') }}" placeholder="e.g. 192.168.1.20" />
                    <flux:input wire:model="routerName" label="{{ __('Node Name (optional)') }}" placeholder="e.g. router-app-1" />
                    <flux:input wire:model="routerSshUser" label="{{ __('SSH User') }}" />
                    <flux:input wire:model="routerAllowFrom" label="{{ __('Allow connections from (IP/CIDR or \"any\")') }}" placeholder="e.g. 10.0.0.0/24 or any" />
                </div>

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
                            <flux:callout variant="warning" class="mt-2">
                                <flux:callout.heading>{{ __('Add this public key to the router node\'s authorized_keys:') }}</flux:callout.heading>
                                <code class="mt-1 block rounded bg-zinc-900 p-2 text-xs text-green-400 break-all">{{ $routerKeyPair['public'] }}</code>
                            </flux:callout>
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
</x-layouts::app>
