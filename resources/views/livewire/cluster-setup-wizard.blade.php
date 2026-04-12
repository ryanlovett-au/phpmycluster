<x-layouts::app :title="__('Create Cluster')">
    <div class="mx-auto max-w-2xl">
        <div class="mb-8">
            <flux:heading size="xl">{{ __('Create InnoDB Cluster') }}</flux:heading>
            <flux:text class="mt-1">{{ __('This wizard will guide you through setting up a new MySQL InnoDB Cluster from scratch.') }}</flux:text>
        </div>

        {{-- Step indicator --}}
        <flux:tab.group class="mb-8">
            <flux:tabs>
                @foreach(['Cluster Details', 'Seed Node', 'SSH Key', 'Provision'] as $i => $label)
                    <flux:tab :name="'step-'.($i+1)" :class="$step > $i + 1 ? 'text-green-500' : ''">
                        <span @class([
                            'mr-1 inline-flex size-5 items-center justify-center rounded-full text-xs font-bold',
                            'bg-blue-500 text-white' => $step === $i + 1,
                            'bg-green-500 text-white' => $step > $i + 1,
                            'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' => $step < $i + 1,
                        ])>{{ $i + 1 }}</span>
                        {{ $label }}
                    </flux:tab>
                @endforeach
            </flux:tabs>
        </flux:tab.group>

        {{-- Step 1: Cluster Details --}}
        @if($step === 1)
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Cluster Configuration') }}</flux:heading>

                <div class="space-y-4">
                    <flux:input wire:model="clusterName" label="{{ __('Cluster Name') }}" placeholder="e.g. production-cluster" />
                    @error('clusterName') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror

                    <flux:select wire:model="communicationStack" label="{{ __('Communication Stack') }}">
                        <flux:select.option value="MYSQL">MYSQL (recommended for MySQL 8.0.27+)</flux:select.option>
                        <flux:select.option value="XCOM">XCOM (legacy)</flux:select.option>
                    </flux:select>
                    <flux:text class="text-xs">{{ __('MYSQL stack uses port 3306 for GR communication. XCOM uses a separate port (33061).') }}</flux:text>

                    <flux:input wire:model="clusterAdminUser" label="{{ __('Cluster Admin Username') }}" />

                    <flux:input wire:model="clusterAdminPassword" type="password" label="{{ __('Cluster Admin Password') }}" placeholder="Minimum 12 characters" />
                    @error('clusterAdminPassword') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror
                    <flux:text class="text-xs">{{ __('This user will be created on all cluster nodes for administrative access.') }}</flux:text>
                </div>

                <div class="mt-6 flex justify-end">
                    <flux:button wire:click="nextStep" variant="primary">{{ __('Next') }}</flux:button>
                </div>
            </flux:card>
        @endif

        {{-- Step 2: Seed Node --}}
        @if($step === 2)
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('Seed Node (Primary)') }}</flux:heading>
                <flux:text class="mb-4">{{ __('This is the first node in your cluster. It will become the primary. It should be a fresh Debian or Ubuntu server.') }}</flux:text>

                <div class="space-y-4">
                    <flux:input wire:model="seedName" label="{{ __('Node Name') }}" placeholder="e.g. db-primary-1 (optional)" />

                    <flux:input wire:model="seedHost" label="{{ __('Host (IP Address or Hostname)') }}" placeholder="e.g. 192.168.1.10 or db1.example.com" />
                    @error('seedHost') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model.number="seedSshPort" type="number" label="{{ __('SSH Port') }}" />
                        <flux:input wire:model="seedSshUser" label="{{ __('SSH User') }}" />
                    </div>

                    <flux:input wire:model.number="seedMysqlPort" type="number" label="{{ __('MySQL Port') }}" />

                    <flux:input wire:model="mysqlRootPassword" type="password" label="{{ __('MySQL Root Password') }}" placeholder="Root password for initial MySQL setup" />
                    @error('mysqlRootPassword') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror
                    <flux:text class="text-xs">{{ __('The initial root password set during MySQL installation. Only used for initial configuration.') }}</flux:text>
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
                                <flux:textarea label="{{ __('Public Key (copy this to the server)') }}" readonly rows="3" class="font-mono text-xs">{{ $generatedKeyPair['public'] }}</flux:textarea>

                                <flux:callout variant="warning" icon="exclamation-triangle">
                                    <flux:callout.heading>{{ __('Add this public key to your server') }}</flux:callout.heading>
                                    <flux:callout.text>
                                        SSH into <code class="font-mono font-bold">{{ $seedHost ?: 'your server' }}</code> and run:
                                    </flux:callout.text>
                                    <code class="mt-2 block rounded bg-zinc-900 p-2 text-xs text-green-400 break-all">mkdir -p ~/.ssh && echo "{{ $generatedKeyPair['public'] }}" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys</code>
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
                <flux:heading size="lg" class="mb-2">{{ __('Provision & Create Cluster') }}</flux:heading>

                @if(!$provisioning && !$provisioningComplete)
                    <div class="mb-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-2">{{ __('Summary') }}</flux:heading>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <dt class="text-zinc-500">{{ __('Cluster Name') }}</dt>
                            <dd>{{ $clusterName }}</dd>
                            <dt class="text-zinc-500">{{ __('Seed Node') }}</dt>
                            <dd>{{ $seedHost }}:{{ $seedMysqlPort }}</dd>
                            <dt class="text-zinc-500">{{ __('SSH') }}</dt>
                            <dd>{{ $seedSshUser }}@{{ $seedHost }}:{{ $seedSshPort }}</dd>
                            <dt class="text-zinc-500">{{ __('Admin User') }}</dt>
                            <dd>{{ $clusterAdminUser }}</dd>
                        </dl>
                    </div>

                    <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                        <flux:callout.text>
                            {{ __('This will install MySQL 8.4, configure it for InnoDB Cluster, configure the firewall, and create the cluster. This process may take several minutes.') }}
                        </flux:callout.text>
                    </flux:callout>

                    <flux:button wire:click="provision" variant="primary" icon="rocket-launch">
                        {{ __('Begin Provisioning') }}
                    </flux:button>
                @endif

                {{-- Provisioning progress --}}
                @if(count($provisionSteps) > 0)
                    <div class="mt-4 space-y-2">
                        @foreach($provisionSteps as $pStep)
                            <div @class([
                                'flex items-start gap-2 text-sm',
                                'text-green-500' => $pStep['status'] === 'success',
                                'text-red-500' => $pStep['status'] === 'error',
                                'text-blue-500' => $pStep['status'] === 'running',
                            ])>
                                <span class="w-14 shrink-0 font-mono text-xs text-zinc-500">{{ $pStep['time'] }}</span>
                                @if($pStep['status'] === 'success')
                                    <flux:icon.check-circle variant="mini" class="mt-0.5 size-4 shrink-0" />
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

                @if($provisioningComplete)
                    <flux:callout variant="success" icon="check-circle" class="mt-6">
                        <flux:callout.heading>{{ __('Cluster Created Successfully!') }}</flux:callout.heading>
                        <flux:callout.text>
                            <flux:button variant="primary" href="{{ route('cluster.manage', $clusterId) }}" wire:navigate class="mt-2">
                                {{ __('Go to Cluster Manager') }}
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
</x-layouts::app>
