    <div class="mx-auto max-w-2xl">
        <div class="mb-8">
            @if($isReprovision)
                <flux:heading size="xl">{{ __('Re-provision Cluster: :name', ['name' => $clusterName]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('Re-configure the SSH key and re-run provisioning for this cluster.') }}</flux:text>
            @else
                <flux:heading size="xl">{{ __('Create InnoDB Cluster') }}</flux:heading>
                <flux:text class="mt-1">{{ __('This wizard will guide you through setting up a new MySQL InnoDB Cluster from scratch.') }}</flux:text>
            @endif
        </div>

        {{-- Step indicator --}}
        <div class="mb-8 flex items-center gap-2">
            @foreach(['Cluster Details', 'Primary Node', 'SSH Key', 'Provision'] as $i => $label)
                <div class="flex items-center">
                    <span style="width: 28px; height: 28px; min-width: 28px;" @class([
                        'inline-flex items-center justify-center rounded-full text-xs font-bold',
                        'bg-blue-500 text-white' => $step === $i + 1,
                        'bg-green-500 text-white' => $step > $i + 1,
                        'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' => $step < $i + 1,
                    ])>{{ $i + 1 }}</span>
                    <span style="margin-left: 10px;" @class([
                        'text-sm whitespace-nowrap',
                        'font-medium' => $step === $i + 1,
                        'text-green-500' => $step > $i + 1,
                        'text-zinc-400' => $step < $i + 1,
                    ])>{{ $label }}</span>
                </div>
                @if($i < 3)
                    <div class="h-px w-6 bg-zinc-300 dark:bg-zinc-600"></div>
                @endif
            @endforeach
        </div>

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

        {{-- Step 2: Primary Node --}}
        @if($step === 2)
            <flux:card>
                <flux:heading size="lg" class="mb-2">{{ __('Primary Node') }}</flux:heading>
                <flux:text class="mb-4">{{ __('This is the first node in your cluster and will become the primary. It should be a fresh Debian or Ubuntu server.') }}</flux:text>

                <div class="space-y-4">
                    <flux:input wire:model="seedName" label="{{ __('Node Name') }}" placeholder="e.g. db-primary-1 (optional)" />

                    <flux:input wire:model="seedHost" label="{{ __('Host (IP Address or Hostname)') }}" placeholder="e.g. 192.168.1.10 or db1.example.com" />
                    @error('seedHost') <flux:text class="!text-red-500">{{ $message }}</flux:text> @enderror

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model.number="seedSshPort" type="number" label="{{ __('SSH Port') }}" />
                        <flux:input wire:model="seedSshUser" label="{{ __('SSH User') }}" />
                    </div>
                    <flux:text class="text-xs">{{ __('The SSH user must be root or have passwordless sudo access (NOPASSWD:ALL).') }}</flux:text>

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
                                        SSH into <code class="font-mono font-bold">{{ $seedHost ?: 'your server' }}</code> and run:
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
                <flux:heading size="lg" class="mb-2">{{ __('Provision & Create Cluster') }}</flux:heading>

                @if(!$provisioning && !$provisioningComplete)
                    <div class="mb-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm" class="mb-2">{{ __('Summary') }}</flux:heading>
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <dt class="text-zinc-500">{{ __('Cluster Name') }}</dt>
                            <dd>{{ $clusterName }}</dd>
                            <dt class="text-zinc-500">{{ __('Primary Node') }}</dt>
                            <dd>{{ $seedHost }}:{{ $seedMysqlPort }}</dd>
                            <dt class="text-zinc-500">{{ __('SSH') }}</dt>
                            <dd>{{ $seedSshUser }}@{{ $seedHost }}:{{ $seedSshPort }}</dd>
                            <dt class="text-zinc-500">{{ __('Admin User') }}</dt>
                            <dd>{{ $clusterAdminUser }}</dd>
                        </dl>
                    </div>

                    @if($isReprovision)
                        <flux:callout variant="info" class="mb-4">
                            <flux:callout.text>
                                {{ __('The host will be probed to detect what is already provisioned. Completed steps will be skipped automatically.') }}
                            </flux:callout.text>
                        </flux:callout>

                        <div class="mb-4">
                            <flux:input wire:model="mysqlRootPassword" type="password" label="{{ __('MySQL Root Password (only needed if MySQL has not been configured yet)') }}" placeholder="Leave blank if already configured" />
                        </div>
                    @else
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4">
                            <flux:callout.text>
                                {{ __('This will install MySQL 8.4, configure it for InnoDB Cluster, configure the firewall, and create the cluster. This process may take several minutes.') }}
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
