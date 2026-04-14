<x-layouts::auth.simple :title="__('Approval Pending')">
    <div class="flex flex-col items-center text-center">
        <flux:icon.clock class="mb-4 size-12 text-yellow-500" />
        <flux:heading size="lg" class="mb-2">{{ __('Account Pending Approval') }}</flux:heading>
        <flux:text class="mb-6">
            {{ __('Your account has been created but requires approval from an administrator before you can access the application. Please check back later or contact your administrator.') }}
        </flux:text>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="primary">{{ __('Log Out') }}</flux:button>
        </form>
    </div>
</x-layouts::auth.simple>
