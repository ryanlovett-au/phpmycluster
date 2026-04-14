<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>PHPMyCluster - MySQL InnoDB Cluster Management</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-900">
        {{-- Navigation --}}
        <header class="sticky top-0 z-50 w-full border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <x-app-logo-icon class="h-5 w-5" />
                    </div>
                    <span class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">PHPMyCluster</span>
                </div>
                <nav class="flex items-center gap-3">
                    @auth
                        <flux:button variant="primary" :href="route('dashboard')">
                            {{ __('Dashboard') }}
                        </flux:button>
                    @else
                        <flux:button variant="ghost" :href="route('login')">
                            {{ __('Log in') }}
                        </flux:button>
                        @if(Route::has('register'))
                            <flux:button variant="primary" :href="route('register')">
                                {{ __('Get Started') }}
                            </flux:button>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main>
            {{-- Hero --}}
            <section class="relative overflow-hidden px-6 pb-20 pt-16 sm:pt-24 sm:pb-28">
                <div class="relative mx-auto max-w-4xl text-center">
                    {{-- Logo --}}
                    <div class="mb-8 flex justify-center">
                        <div class="inline-flex h-20 w-20 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-xl">
                            <x-app-logo-icon class="h-12 w-12" />
                        </div>
                    </div>

                    <h1 class="text-4xl font-extrabold tracking-tight sm:text-5xl lg:text-6xl">
                        <span class="text-blue-600 dark:text-blue-400">MySQL InnoDB Cluster</span>
                        <br />
                        <span class="text-zinc-900 dark:text-white">Management Made Simple</span>
                    </h1>

                    <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-zinc-600 dark:text-zinc-400">
                        Deploy, monitor, and manage highly available MySQL InnoDB Clusters from a single control panel.
                        Provision fresh servers, configure Group Replication, manage MySQL Router, and handle failover recovery &mdash; all over SSH.
                    </p>

                    <div class="mt-10 flex flex-wrap items-center justify-center gap-4">
                        @auth
                            <flux:button variant="primary" :href="route('dashboard')">
                                {{ __('Go to Dashboard') }}
                            </flux:button>
                        @else
                            @if(Route::has('register'))
                                <flux:button variant="primary" :href="route('register')">
                                    {{ __('Get Started') }}
                                </flux:button>
                            @endif
                            <flux:button variant="ghost" :href="route('login')">
                                {{ __('Log in') }}
                            </flux:button>
                        @endauth
                    </div>
                </div>
            </section>

            {{-- Features --}}
            <section class="relative px-6 py-20">
                <div class="mx-auto max-w-6xl">
                    <div class="mb-16 text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Everything You Need</h2>
                        <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">Full lifecycle cluster management from a single dashboard.</p>
                    </div>

                    <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                        {{-- Feature 1 --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-800/50">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/50 dark:text-blue-400">
                                <flux:icon.server-stack class="!h-6 !w-6" />
                            </div>
                            <h3 class="mb-2 text-lg font-semibold text-zinc-900 dark:text-white">Cluster Provisioning</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Create InnoDB Clusters from scratch on fresh Debian/Ubuntu servers. Installs MySQL, configures Group Replication, and bootstraps the cluster via SSH.
                            </p>
                        </div>

                        {{-- Feature 2 --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-800/50">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/50 dark:text-green-400">
                                <flux:icon.heart class="!h-6 !w-6" />
                            </div>
                            <h3 class="mb-2 text-lg font-semibold text-zinc-900 dark:text-white">Health Monitoring</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Real-time cluster status, node health checks, and role tracking. Instantly see which nodes are online, recovering, or need attention.
                            </p>
                        </div>

                        {{-- Feature 3 --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-800/50">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/50 dark:text-amber-400">
                                <flux:icon.wrench-screwdriver class="!h-6 !w-6" />
                            </div>
                            <h3 class="mb-2 text-lg font-semibold text-zinc-900 dark:text-white">Recovery Tools</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Force quorum, reboot from complete outage, rejoin lost nodes, and rescan topology. All the MySQL Shell AdminAPI recovery options at your fingertips.
                            </p>
                        </div>

                        {{-- Feature 4 --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-800/50">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-purple-100 text-purple-600 dark:bg-purple-900/50 dark:text-purple-400">
                                <flux:icon.arrows-right-left class="!h-6 !w-6" />
                            </div>
                            <h3 class="mb-2 text-lg font-semibold text-zinc-900 dark:text-white">MySQL Router</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Bootstrap and manage MySQL Router on dedicated access nodes. Automatic read/write splitting and transparent failover for your applications.
                            </p>
                        </div>

                        {{-- Feature 5 --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-800/50">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-900/50 dark:text-red-400">
                                <flux:icon.shield-check class="!h-6 !w-6" />
                            </div>
                            <h3 class="mb-2 text-lg font-semibold text-zinc-900 dark:text-white">Firewall Management</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Automatic UFW configuration with dynamic IP allowlists. Ports are opened only between cluster nodes, keeping your infrastructure locked down.
                            </p>
                        </div>

                        {{-- Feature 6 --}}
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-800/50">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-cyan-100 text-cyan-600 dark:bg-cyan-900/50 dark:text-cyan-400">
                                <flux:icon.document-text class="!h-6 !w-6" />
                            </div>
                            <h3 class="mb-2 text-lg font-semibold text-zinc-900 dark:text-white">Log Streaming</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Stream error logs, slow query logs, general logs, and systemd journals from any node in real time. Debug issues without leaving the browser.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Architecture diagram --}}
            <section class="px-6 py-20">
                <div class="mx-auto max-w-4xl">
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 sm:p-12 dark:border-zinc-800 dark:bg-zinc-800/50">
                        <div class="mb-10 text-center">
                            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">How It Works</h2>
                            <p class="mt-3 text-zinc-600 dark:text-zinc-400">PHPMyCluster runs on a separate control node and manages your cluster over SSH.</p>
                        </div>

                        <div class="flex flex-col items-center gap-6">
                            {{-- Control node --}}
                            <div class="flex w-full max-w-sm items-center gap-4 rounded-xl border-2 border-blue-500 bg-blue-50 p-4 dark:border-blue-600 dark:bg-blue-950/50">
                                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
                                    <flux:icon.computer-desktop class="!h-5 !w-5" />
                                </div>
                                <div>
                                    <p class="font-semibold text-blue-900 dark:text-blue-200">Control Node</p>
                                    <p class="text-xs text-blue-700 dark:text-blue-400">PHPMyCluster + SQLite</p>
                                </div>
                            </div>

                            {{-- SSH arrows --}}
                            <div class="flex flex-col items-center gap-1">
                                <div class="h-6 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                <span class="rounded-full bg-zinc-200 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">SSH + mysqlsh</span>
                                <div class="h-6 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                            </div>

                            {{-- Cluster nodes --}}
                            <div class="grid w-full max-w-lg grid-cols-3 gap-3">
                                <div class="rounded-xl border border-green-300 bg-green-50 p-3 text-center dark:border-green-700 dark:bg-green-950/50">
                                    <flux:icon.circle-stack class="mx-auto mb-1 !h-6 !w-6 text-green-600 dark:text-green-400" />
                                    <p class="text-xs font-semibold text-green-900 dark:text-green-200">Primary</p>
                                    <p class="text-[10px] text-green-700 dark:text-green-400">R/W</p>
                                </div>
                                <div class="rounded-xl border border-zinc-300 bg-zinc-100 p-3 text-center dark:border-zinc-600 dark:bg-zinc-800">
                                    <flux:icon.circle-stack class="mx-auto mb-1 !h-6 !w-6 text-zinc-500 dark:text-zinc-400" />
                                    <p class="text-xs font-semibold text-zinc-900 dark:text-zinc-200">Secondary</p>
                                    <p class="text-[10px] text-zinc-600 dark:text-zinc-400">R/O</p>
                                </div>
                                <div class="rounded-xl border border-zinc-300 bg-zinc-100 p-3 text-center dark:border-zinc-600 dark:bg-zinc-800">
                                    <flux:icon.circle-stack class="mx-auto mb-1 !h-6 !w-6 text-zinc-500 dark:text-zinc-400" />
                                    <p class="text-xs font-semibold text-zinc-900 dark:text-zinc-200">Secondary</p>
                                    <p class="text-[10px] text-zinc-600 dark:text-zinc-400">R/O</p>
                                </div>
                            </div>

                            {{-- Router --}}
                            <div class="flex flex-col items-center gap-1">
                                <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                <span class="rounded-full bg-zinc-200 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">MySQL Router</span>
                                <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                            </div>

                            <div class="flex w-full max-w-xs items-center gap-4 rounded-xl border border-purple-300 bg-purple-50 p-4 dark:border-purple-700 dark:bg-purple-950/50">
                                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-600 text-white">
                                    <flux:icon.arrows-right-left class="!h-5 !w-5" />
                                </div>
                                <div>
                                    <p class="font-semibold text-purple-900 dark:text-purple-200">Access Node</p>
                                    <p class="text-xs text-purple-700 dark:text-purple-400">Load balancing + failover</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Tech stack --}}
            <section class="border-t border-zinc-200 px-6 py-16 dark:border-zinc-800">
                <div class="mx-auto max-w-4xl text-center">
                    <p class="mb-6 text-sm font-medium uppercase tracking-widest text-zinc-500">Built With</p>
                    <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-4 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <span>Laravel 13</span>
                        <span class="text-zinc-300 dark:text-zinc-700">/</span>
                        <span>Livewire</span>
                        <span class="text-zinc-300 dark:text-zinc-700">/</span>
                        <span>Flux UI</span>
                        <span class="text-zinc-300 dark:text-zinc-700">/</span>
                        <span>MySQL Shell AdminAPI</span>
                        <span class="text-zinc-300 dark:text-zinc-700">/</span>
                        <span>phpseclib</span>
                    </div>
                </div>
            </section>
        </main>

        {{-- Footer --}}
        <footer class="border-t border-zinc-200 px-6 py-8 dark:border-zinc-800">
            <div class="mx-auto max-w-6xl text-center">
                <p class="text-sm text-zinc-500">
                    PHPMyCluster &mdash; Open-source MySQL InnoDB Cluster management.
                </p>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
