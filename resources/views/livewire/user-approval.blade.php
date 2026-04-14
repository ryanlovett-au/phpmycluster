    <div class="flex flex-col gap-6">
        <flux:heading size="xl">{{ __('User Management') }}</flux:heading>

        {{-- Action message --}}
        @if($actionMessage)
            <flux:callout :variant="match($actionStatus) { 'success' => 'success', 'error' => 'danger', default => 'info' }">
                <flux:callout.text>{{ $actionMessage }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Pending Approval --}}
        @if($pendingUsers->isNotEmpty())
            <div>
                <flux:heading size="lg" class="mb-3">{{ __('Pending Approval') }}</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Email') }}</flux:table.column>
                        <flux:table.column>{{ __('Registered') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($pendingUsers as $user)
                            <flux:table.row>
                                <flux:table.cell>{{ $user->name }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ $user->email }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ $user->created_at->diffForHumans() }}</flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="primary" wire:click="approve({{ $user->id }})">
                                            {{ __('Approve') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="danger"
                                            wire:click="deleteUser({{ $user->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this user?') }}">
                                            {{ __('Delete') }}
                                        </flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @else
            <flux:callout variant="info">
                <flux:callout.text>{{ __('No users pending approval.') }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Approved Users --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Approved Users') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Email') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column>{{ __('Approved') }}</flux:table.column>
                    <flux:table.column>{{ __('Approved By') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($approvedUsers as $user)
                        <flux:table.row>
                            <flux:table.cell>
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                    <flux:badge size="sm" color="blue">{{ __('You') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $user->email }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$user->is_admin ? 'purple' : 'zinc'">
                                    {{ $user->is_admin ? __('Admin') : __('User') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $user->approved_at->diffForHumans() }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $user->approver?->name ?? __('System') }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                @if($user->id !== auth()->id())
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" wire:click="toggleAdmin({{ $user->id }})">
                                            {{ $user->is_admin ? __('Remove Admin') : __('Make Admin') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="danger"
                                            wire:click="revoke({{ $user->id }})"
                                            wire:confirm="{{ __('Revoke approval for :name?', ['name' => $user->name]) }}">
                                            {{ __('Revoke') }}
                                        </flux:button>
                                    </div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
