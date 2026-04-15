    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('MySQL Router') }} — {{ $cluster->name }}</flux:heading>
                <flux:text>{{ __('Manage MySQL Router (access) nodes for this cluster.') }}</flux:text>
            </div>
            <flux:button href="{{ route('mysql.manage', $cluster) }}" wire:navigate icon="arrow-left">
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
                                <flux:badge color="orange">{{ __('Router') }}</flux:badge>
                                <flux:text class="text-xs">{{ ucfirst($routerNode->status->value) }}</flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:button size="sm" wire:click="checkRouterStatus({{ $routerNode->id }})" icon="arrow-path">
                                    {{ __('Check Status') }}
                                </flux:button>
                                <flux:button size="sm" href="{{ route('node.logs', $routerNode) }}" wire:navigate icon="document-text">
                                    {{ __('Logs') }}
                                </flux:button>
                                @if(in_array($routerNode->status->value, ['error', 'unknown']))
                                    <flux:button size="sm" variant="primary" wire:click="retrySetup({{ $routerNode->id }})" icon="arrow-path">
                                        {{ __('Retry Setup') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="danger"
                                        wire:click="deleteRouter({{ $routerNode->id }})"
                                        wire:confirm="{{ __('Are you sure you want to delete :name?', ['name' => $routerNode->name]) }}">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>

                        @if($routerNode->status->value === 'online')
                            <div class="mt-3 text-sm">
                                <flux:text>R/W Port: <strong>6446</strong> | R/O Port: <strong>6447</strong></flux:text>
                                <flux:text>{{ __('Last checked:') }} {{ $routerNode->last_checked_at?->diffForHumans() ?? 'Never' }}</flux:text>
                            </div>
                        @endif

                        {{-- Setup progress for this router node --}}
                        @if($settingUpNodeId === $routerNode->id && count($setupSteps) > 0)
                            <div class="mt-4 border-t border-neutral-200 pb-2 dark:border-neutral-700" style="padding-top: 1.25rem;">
                                @if($settingUpRouter)
                                    <div wire:poll.2s="pollSetup">
                                @endif

                                <div class="space-y-2">
                                    @foreach($setupSteps as $pStep)
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

                                @if($setupComplete)
                                    <flux:callout variant="success" icon="check-circle" class="mt-4">
                                        <flux:callout.heading>{{ __('Router Setup Complete!') }}</flux:callout.heading>
                                    </flux:callout>
                                    <div class="mt-3">
                                        <flux:button wire:click="dismissSetupProgress" size="sm" variant="filled">{{ __('Dismiss') }}</flux:button>
                                    </div>
                                @elseif(!$settingUpRouter)
                                    {{-- Job failed --}}
                                    <div class="mt-3 flex gap-2">
                                        <flux:button wire:click="retrySetup({{ $settingUpNodeId }})" size="sm" variant="primary" icon="arrow-path">
                                            {{ __('Retry') }}
                                        </flux:button>
                                        <flux:button wire:click="dismissSetupProgress" size="sm">{{ __('Dismiss') }}</flux:button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Add Router Node --}}
        @if(!$showAddRouter && !$settingUpRouter)
            <flux:button wire:click="$set('showAddRouter', true)" variant="primary" icon="plus">
                {{ __('Add Router Node') }}
            </flux:button>
        @elseif($showAddRouter)
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('Set Up New Router Node') }}</flux:heading>
                <flux:text class="mb-4">{{ __('This will SSH into the target machine, install MySQL Router, configure the firewall, and bootstrap the router against your cluster.') }}</flux:text>

                <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:input wire:model="routerHost" label="{{ __('Host IP / Hostname') }}" placeholder="e.g. 192.168.1.20" />
                    <flux:input wire:model="routerName" label="{{ __('Node Name (optional)') }}" placeholder="e.g. router-app-1" />
                    <flux:input wire:model="routerSshUser" label="{{ __('SSH User') }}" />
                    <flux:input wire:model.number="routerSshPort" type="number" label="{{ __('SSH Port') }}" />
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
