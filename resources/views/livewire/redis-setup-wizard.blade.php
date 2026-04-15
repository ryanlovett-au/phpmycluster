    <div class="mx-auto max-w-2xl">
        <div class="mb-8">
            <div class="mb-4 flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-lg bg-red-500/10">
                    <flux:icon.server-stack class="size-6 text-red-500" />
                </div>
                @if($isReprovision)
                    <flux:heading size="xl">{{ __('Re-provision Redis Cluster: :name', ['name' => $clusterName]) }}</flux:heading>
                @else
                    <flux:heading size="xl">{{ __('Create Redis Sentinel Cluster') }}</flux:heading>
                @endif
            </div>
            @if($isReprovision)
                <flux:text class="mt-1">{{ __('Re-configure the SSH key and re-run provisioning for this cluster.') }}</flux:text>
            @else
                <flux:text class="mt-1">{{ __('This wizard will guide you through setting up a new Redis Sentinel cluster for high availability.') }}</flux:text>
            @endif
        </div>

        {{-- Step indicator --}}
        @php
            $wizardSteps = ($serverMode === 'existing' && $availableServers->isNotEmpty())
                ? ['Cluster Details', 'Master Node', 'Provision']
                : ['Cluster Details', 'Master Node', 'SSH Key', 'Provision'];
            $stepMap = ($serverMode === 'existing' && $availableServers->isNotEmpty())
                ? [1, 2, 4]
                : [1, 2, 3, 4];
        @endphp
        <div class="mb-8 flex items-center gap-2">
            @foreach($wizardSteps as $i => $label)
                @php $realStep = $stepMap[$i]; @endphp
                <div class="flex items-center">
                    <span style="width: 28px; height: 28px; min-width: 28px;" @class([
                        'inline-flex items-center justify-center rounded-full text-xs font-bold',
                        'bg-red-500 text-white' => $step === $realStep,
                        'bg-green-500 text-white' => $step > $realStep,
                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' => $step < $realStep,
                    ])>{{ $i + 1 }}</span>
                    <span style="margin-left: 10px;" @class([
                        'text-sm whitespace-nowrap',
                        'font-medium' => $step === $realStep,
                        'text-green-500' => $step > $realStep,
                        'text-zinc-400' => $step < $realStep,
                    ])>{{ $label }}</span>
                </div>
                @if($i < count($wizardSteps) - 1)
                    <div class="h-px w-6 bg-zinc-300 dark:bg-zinc-600"></div>
                @endif
            @endforeach
        </div>

        {{-- Step 1: Cluster Details --}}
        @if($step === 1)
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Cluster Configuration') }}</flux:heading>

                <div class="space-y-4">
                    <flux:input wire:model="clusterName" label="{{ __('Cluster Name') }}" placeholder="e.g. redis-production" />
                    @error('clusterName') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror

                    <flux:input wire:model="authPassword" type="password" label="{{ __('Redis AUTH Password') }}" placeholder="Minimum 12 characters" />
                    @error('authPassword') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror
                    <flux:text class="text-xs">{{ __('The requirepass / masterauth password used by all Redis and Sentinel instances.') }}</flux:text>

                    <flux:input wire:model="sentinelPassword" type="password" label="{{ __('Sentinel Password') }}" placeholder="Optional — defaults to AUTH password if blank" />
                    @error('sentinelPassword') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror
                    <flux:text class="text-xs">{{ __('A separate password for Sentinel-to-Sentinel authentication. Leave blank to reuse the AUTH password.') }}</flux:text>

                    <flux:input wire:model.number="quorum" type="number" label="{{ __('Sentinel Quorum') }}" />
                    @error('quorum') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror
                    <flux:text class="text-xs">{{ __('Number of Sentinels that must agree for failover. A quorum of 2 is recommended for a 3-node setup.') }}</flux:text>
                </div>

                <div class="mt-6 flex justify-end">
                    <flux:button wire:click="nextStep" variant="primary">{{ __('Next') }}</flux:button>
                </div>
            </flux:card>
        @endif

        {{-- Step 2: Master Node Details --}}
        @if($step === 2)
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('Master Node') }}</flux:heading>
                <flux:text class="mb-4">{{ __('Choose where to deploy the Redis master node.') }}</flux:text>

                <div class="space-y-4">
                    @if($availableServers->isNotEmpty())
                        <flux:radio.group wire:model.live="serverMode" label="{{ __('Server') }}">
                            <flux:radio value="existing" label="{{ __('Use an existing server') }}" />
                            <flux:radio value="new" label="{{ __('Configure a new server') }}" />
                        </flux:radio.group>
                    @endif

                    @if($serverMode === 'existing' && $availableServers->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($availableServers as $server)
                                <label wire:click="$set('selectedServerId', {{ $server->id }})" @class([
                                    'flex cursor-pointer items-center justify-between rounded-lg border p-4 transition',
                                    'border-red-500 bg-red-50 dark:bg-red-900/10' => $selectedServerId === $server->id,
                                    'border-neutral-200 hover:border-neutral-300 dark:border-neutral-700 dark:hover:border-neutral-600' => $selectedServerId !== $server->id,
                                ])>
                                    <div class="flex items-center gap-3">
                                        <flux:icon.server variant="mini" @class([
                                            'size-5',
                                            'text-red-500' => $selectedServerId === $server->id,
                                            'text-zinc-400' => $selectedServerId !== $server->id,
                                        ]) />
                                        <div>
                                            <div class="font-medium">{{ $server->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $server->ssh_user . '@' . $server->host . ':' . $server->ssh_port }}</div>
                                        </div>
                                    </div>
                                    @if($selectedServerId === $server->id)
                                        <flux:icon.check-circle variant="mini" class="size-5 text-red-500" />
                                    @endif
                                </label>
                            @endforeach
                            @error('selectedServerId') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror
                        </div>

                        <flux:separator />

                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model.number="masterRedisPort" type="number" label="{{ __('Redis Port') }}" />
                            <flux:input wire:model.number="masterSentinelPort" type="number" label="{{ __('Sentinel Port') }}" />
                        </div>

                        <flux:input wire:model="masterNodeName" label="{{ __('Node Name') }}" placeholder="redis-master-1" />
                        <flux:text class="text-xs">{{ __('A friendly name for this node (optional). Used for display purposes.') }}</flux:text>
                    @else
                        <flux:text class="mb-2 text-sm">{{ __('Configure the connection details for the Redis master node. This should be a fresh Debian or Ubuntu server.') }}</flux:text>

                        <flux:input wire:model="masterHost" label="{{ __('Host / IP') }}" placeholder="e.g. 192.168.1.10 or redis1.example.com" />
                        @error('masterHost') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror

                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model.number="masterSshPort" type="number" label="{{ __('SSH Port') }}" />
                            <flux:input wire:model="masterSshUser" label="{{ __('SSH User') }}" />
                        </div>
                        <flux:text class="text-xs">{{ __('The SSH user must be root or have passwordless sudo access (NOPASSWD:ALL).') }}</flux:text>

                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model.number="masterRedisPort" type="number" label="{{ __('Redis Port') }}" />
                            <flux:input wire:model.number="masterSentinelPort" type="number" label="{{ __('Sentinel Port') }}" />
                        </div>

                        <flux:input wire:model="masterNodeName" label="{{ __('Node Name') }}" placeholder="redis-master-1" />
                        <flux:text class="text-xs">{{ __('A friendly name for this node (optional). Used for display purposes.') }}</flux:text>
                    @endif
                </div>

                <div class="mt-6 flex justify-between">
                    <flux:button wire:click="previousStep">{{ __('Back') }}</flux:button>
                    <flux:button wire:click="nextStep" variant="primary">{{ __('Next') }}</flux:button>
                </div>
            </flux:card>
        @endif

        {{-- Step 3: SSH Key Setup --}}
        @if($step === 3)
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('SSH Key Configuration') }}</flux:heading>
                <flux:text class="mb-4">{{ __('PHPMyCluster uses SSH keys to securely connect to your nodes. The private key is stored encrypted in the application database.') }}</flux:text>

                @if($isReprovision && $sshKeyMissing)
                    <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
                        <flux:callout.heading>{{ __('SSH key not found') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('The SSH private key for this node was not found in the database. This can happen if a previous provisioning attempt failed before the key was stored. Please generate a new keypair or paste the existing private key below.') }}
                        </flux:callout.text>
                    </flux:callout>
                @elseif($isReprovision && $sshKeyAuthFailed)
                    <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
                        <flux:callout.heading>{{ __('SSH authentication failed') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('The SSH key stored in the database was rejected by the server. This can happen if a new key was generated but not added to the server\'s authorized_keys file. Please generate a new keypair and add the public key to the server, or paste the private key that matches the server\'s authorized_keys.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="space-y-4">
                    <flux:radio.group wire:model="sshKeyMode" label="{{ __('Key mode') }}">
                        <flux:radio value="generate" label="{{ __('Generate a new keypair') }}" />
                        <flux:radio value="existing" label="{{ __('Use an existing key') }}" />
                    </flux:radio.group>

                    @if($sshKeyMode === 'generate')
                        @if(!$generatedKeyPair)
                            <flux:button wire:click="generateSshKey" variant="filled" icon="key">
                                {{ __('Generate Ed25519 Keypair') }}
                            </flux:button>
                        @else
                            <div class="space-y-3">
                                <flux:callout variant="warning" icon="exclamation-triangle">
                                    <flux:callout.heading>{{ __('Add this public key to your server') }}</flux:callout.heading>
                                    <flux:callout.text>
                                        SSH into <code class="font-mono font-bold">{{ $masterHost ?: 'your server' }}</code> and run:
                                    </flux:callout.text>
                                    <div x-data="{ copied: false, cmd: @js('mkdir -p ~/.ssh && echo "' . $generatedKeyPair['public'] . '" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys') }">
                                        <code class="mt-2 block rounded bg-zinc-900 p-2 text-xs text-green-400 break-all">mkdir -p ~/.ssh && echo "{{ $generatedKeyPair['public'] }}" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys</code>
                                        <flux:button size="xs" variant="subtle" class="mt-1"
                                            x-on:click="navigator.clipboard.writeText(cmd); copied = true; setTimeout(() => copied = false, 2000)"
                                            icon="clipboard-document">
                                            <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy Command') }}'"></span>
                                        </flux:button>
                                    </div>
                                </flux:callout>

                                <flux:checkbox wire:model="publicKeyCopied" label="{{ __('I have added the public key to the server') }}" />
                            </div>
                        @endif
                    @else
                        <flux:textarea wire:model="existingPrivateKey" label="{{ __('Private Key') }}" rows="8" placeholder="Paste your private key here (e.g. contents of ~/.ssh/id_ed25519)" class="font-mono text-xs" />
                        <flux:text class="text-xs">{{ __('The key is encrypted before storage. Supports RSA, Ed25519, and ECDSA keys.') }}</flux:text>
                    @endif

                    @if(($sshKeyMode === 'generate' && $generatedKeyPair) || ($sshKeyMode === 'existing' && $existingPrivateKey))
                        <flux:separator />
                        <flux:button wire:click="testSshConnection" icon="signal">
                            {{ __('Test SSH Connection') }}
                        </flux:button>
                    @endif

                    {{-- Connection test results --}}
                    @if(count($provisionSteps) > 0)
                        <div class="space-y-1">
                            @foreach($provisionSteps as $pStep)
                                <div @class([
                                    'flex items-center gap-2 text-sm',
                                    'text-green-500' => $pStep['status'] === 'success',
                                    'text-red-500' => $pStep['status'] === 'error',
                                    'text-blue-500' => $pStep['status'] === 'running',
                                ])>
                                    @if($pStep['status'] === 'success')
                                        <flux:icon.check-circle variant="mini" class="size-4" />
                                    @elseif($pStep['status'] === 'error')
                                        <flux:icon.x-circle variant="mini" class="size-4" />
                                    @else
                                        <flux:icon.arrow-path variant="mini" class="size-4 animate-spin" />
                                    @endif
                                    <span>{{ $pStep['message'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex justify-between">
                    <flux:button wire:click="previousStep">{{ __('Back') }}</flux:button>
                    <flux:button wire:click="nextStep" variant="primary" :disabled="$sshKeyMode === 'generate' && !$publicKeyCopied">
                        {{ __('Next') }}
                    </flux:button>
                </div>
            </flux:card>
        @endif

        {{-- Step 4: Provision --}}
        @if($step === 4)
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('Provision Redis Cluster') }}</flux:heading>

                @if(!$provisioning && !$provisioningComplete)
                    <div class="mb-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-2">{{ __('Summary') }}</flux:heading>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <dt class="text-zinc-500">{{ __('Cluster Name') }}</dt>
                            <dd>{{ $clusterName }}</dd>
                            <dt class="text-zinc-500">{{ __('Master Node') }}</dt>
                            <dd>{{ $masterHost }}:{{ $masterRedisPort }}</dd>
                            <dt class="text-zinc-500">{{ __('Sentinel Port') }}</dt>
                            <dd>{{ $masterSentinelPort }}</dd>
                            <dt class="text-zinc-500">{{ __('SSH') }}</dt>
                            <dd>{{ $masterSshUser . '@' . $masterHost . ':' . $masterSshPort }}</dd>
                            <dt class="text-zinc-500">{{ __('Quorum') }}</dt>
                            <dd>{{ $quorum }}</dd>
                        </dl>
                    </div>

                    @if($isReprovision)
                        <flux:callout variant="info" class="mb-4">
                            <flux:callout.text>
                                {{ __('The host will be probed to detect what is already provisioned. Completed steps will be skipped automatically.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @else
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                            <flux:callout.text>
                                {{ __('This will install Redis, configure Sentinel for high availability, set up authentication, and configure the firewall. This process may take several minutes.') }}
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <flux:button wire:click="provision" variant="primary" icon="rocket-launch">
                        {{ $isReprovision ? __('Begin Re-provisioning') : __('Begin Provisioning') }}
                    </flux:button>
                @endif

                {{-- Provisioning progress (polls every 2 seconds while running) --}}
                @if($provisioning)
                    <div wire:poll.2s="pollProgress">
                @endif

                @if(count($provisionSteps) > 0)
                    <div class="mt-4 space-y-2">
                        @foreach($provisionSteps as $pStep)
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
                @endif

                @if($provisioning)
                    </div>
                @endif

                @if($provisioningComplete)
                    <flux:callout variant="success" icon="check-circle" class="mt-6">
                        <flux:callout.heading>{{ __('Redis Cluster Created Successfully!') }}</flux:callout.heading>
                        <flux:callout.text>
                            <flux:button variant="primary" href="{{ route('redis.manage', $clusterId) }}" wire:navigate class="mt-2">
                                {{ __('Manage Cluster') }}
                            </flux:button>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                @if(!$provisioning && !$provisioningComplete)
                    <div class="mt-6">
                        <flux:button wire:click="previousStep">{{ __('Back') }}</flux:button>
                    </div>
                @endif
            </flux:card>
        @endif
    </div>
