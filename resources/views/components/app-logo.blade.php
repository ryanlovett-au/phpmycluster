@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="PHPMyCluster" {{ $attributes }}>
        <x-slot name="logo" style="height: 2rem; min-width: 2rem; background: #2563eb; border-radius: 0.375rem;">
            <x-app-logo-icon style="width: 1.25rem; height: 1.25rem; color: white;" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="PHPMyCluster" {{ $attributes }}>
        <x-slot name="logo" style="height: 2rem; min-width: 2rem; background: #2563eb; border-radius: 0.375rem;">
            <x-app-logo-icon style="width: 1.25rem; height: 1.25rem; color: white;" />
        </x-slot>
    </flux:brand>
@endif
