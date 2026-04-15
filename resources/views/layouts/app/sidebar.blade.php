<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('MySQL')" class="grid">
                    @foreach(\App\Models\MysqlCluster::orderBy('name')->get() as $mysqlCluster)
                        <flux:sidebar.item icon="circle-stack" :href="route('mysql.manage', $mysqlCluster)" :current="request()->routeIs('mysql.manage') && request()->route('cluster')?->id === $mysqlCluster->id" wire:navigate>
                            {{ $mysqlCluster->name }}
                        </flux:sidebar.item>
                    @endforeach
                    <flux:sidebar.item icon="plus-circle" :href="route('mysql.create')" :current="request()->routeIs('mysql.create')" wire:navigate>
                        {{ __('New MySQL Cluster') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Redis')" class="grid">
                    @foreach(\App\Models\RedisCluster::orderBy('name')->get() as $redisCluster)
                        <flux:sidebar.item icon="server-stack" :href="route('redis.manage', $redisCluster)" :current="request()->routeIs('redis.manage') && request()->route('cluster')?->id === $redisCluster->id" wire:navigate>
                            {{ $redisCluster->name }}
                        </flux:sidebar.item>
                    @endforeach
                    <flux:sidebar.item icon="plus-circle" :href="route('redis.create')" :current="request()->routeIs('redis.create')" wire:navigate>
                        {{ __('New Redis Cluster') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Monitoring')" class="grid">
                    <flux:sidebar.item icon="clipboard-document-list" :href="route('audit-logs')" :current="request()->routeIs('audit-logs')" wire:navigate>
                        {{ __('Audit Log') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if(auth()->user()->is_admin)
                    <flux:sidebar.group :heading="__('Administration')" class="grid">
                        <flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.index')" wire:navigate>
                            {{ __('Users') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="book-open-text" href="https://dev.mysql.com/doc/mysql-shell/8.4/en/mysql-innodb-cluster.html" target="_blank">
                    {{ __('MySQL Cluster Docs') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="book-open-text" href="https://redis.io/docs/latest/operate/oss_and_stack/management/sentinel/" target="_blank">
                    {{ __('Redis Sentinel Docs') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
