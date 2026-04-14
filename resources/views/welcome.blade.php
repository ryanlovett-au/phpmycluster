<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>PHPMyCluster - MySQL InnoDB Cluster Management</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-950">
        {{-- Navigation --}}
        <header class="sticky top-0 z-50 w-full border-b border-zinc-200/80 bg-white/80 backdrop-blur-lg dark:border-zinc-800/80 dark:bg-zinc-950/80">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-3">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white">
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
            <section class="relative overflow-hidden bg-gradient-to-b from-blue-50 via-white to-white dark:from-blue-950/30 dark:via-zinc-950 dark:to-zinc-950">
                <div class="relative mx-auto max-w-4xl px-6 pb-24 pt-20 text-center sm:pb-32 sm:pt-28">
                    <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-4 py-1.5 text-sm font-medium text-blue-700 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-300">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                        </span>
                        Open-source cluster management
                    </div>

                    <h1 class="text-4xl font-extrabold tracking-tight text-zinc-900 sm:text-5xl lg:text-6xl dark:text-white">
                        MySQL InnoDB Cluster
                        <br />
                        <span class="bg-gradient-to-r from-blue-600 to-cyan-500 bg-clip-text text-transparent dark:from-blue-400 dark:to-cyan-300">Management Made Simple</span>
                    </h1>

                    <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-zinc-600 dark:text-zinc-400">
                        Deploy, monitor, and manage highly available MySQL InnoDB Clusters from a single control panel. Provision fresh servers, configure Group Replication, manage MySQL Router, and handle failover recovery &mdash; all over SSH.
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

                    {{-- Quick stats --}}
                    <div class="mx-auto mt-16 grid max-w-lg grid-cols-3 gap-8">
                        <div>
                            <p class="text-3xl font-bold text-zinc-900 dark:text-white">3+</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Node clusters</p>
                        </div>
                        <div>
                            <p class="text-3xl font-bold text-zinc-900 dark:text-white">&lt;15s</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Auto failover</p>
                        </div>
                        <div>
                            <p class="text-3xl font-bold text-zinc-900 dark:text-white">100%</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">SSH managed</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Features --}}
            <section class="relative border-t border-zinc-200/80 px-6 py-24 dark:border-zinc-800/80">
                <div class="mx-auto max-w-6xl">
                    <div class="mb-4 text-center">
                        <p class="text-sm font-semibold uppercase tracking-widest text-blue-600 dark:text-blue-400">Features</p>
                    </div>
                    <div class="mb-16 text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Everything You Need</h2>
                        <p class="mx-auto mt-4 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">Full lifecycle cluster management from a single dashboard.</p>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="group rounded-xl border border-zinc-200 bg-white p-6 transition-all hover:border-zinc-300 hover:shadow-lg hover:shadow-zinc-200/50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700 dark:hover:shadow-zinc-900/50">
                            <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                <flux:icon.server-stack class="!h-5 !w-5" />
                            </div>
                            <h3 class="mb-2 font-semibold text-zinc-900 dark:text-white">Cluster Provisioning</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Create InnoDB Clusters from scratch on fresh Debian/Ubuntu servers. Installs MySQL, configures Group Replication, and bootstraps the cluster via SSH.</p>
                        </div>

                        <div class="group rounded-xl border border-zinc-200 bg-white p-6 transition-all hover:border-zinc-300 hover:shadow-lg hover:shadow-zinc-200/50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700 dark:hover:shadow-zinc-900/50">
                            <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                                <flux:icon.heart class="!h-5 !w-5" />
                            </div>
                            <h3 class="mb-2 font-semibold text-zinc-900 dark:text-white">Health Monitoring</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Real-time cluster status, node health checks, and role tracking. Instantly see which nodes are online, recovering, or need attention.</p>
                        </div>

                        <div class="group rounded-xl border border-zinc-200 bg-white p-6 transition-all hover:border-zinc-300 hover:shadow-lg hover:shadow-zinc-200/50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700 dark:hover:shadow-zinc-900/50">
                            <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                                <flux:icon.wrench-screwdriver class="!h-5 !w-5" />
                            </div>
                            <h3 class="mb-2 font-semibold text-zinc-900 dark:text-white">Recovery Tools</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Force quorum, reboot from complete outage, rejoin lost nodes, and rescan topology. All the MySQL Shell AdminAPI recovery options at your fingertips.</p>
                        </div>

                        <div class="group rounded-xl border border-zinc-200 bg-white p-6 transition-all hover:border-zinc-300 hover:shadow-lg hover:shadow-zinc-200/50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700 dark:hover:shadow-zinc-900/50">
                            <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                                <flux:icon.arrows-right-left class="!h-5 !w-5" />
                            </div>
                            <h3 class="mb-2 font-semibold text-zinc-900 dark:text-white">MySQL Router</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Bootstrap and manage MySQL Router on dedicated access nodes. Automatic read/write splitting and transparent failover for your applications.</p>
                        </div>

                        <div class="group rounded-xl border border-zinc-200 bg-white p-6 transition-all hover:border-zinc-300 hover:shadow-lg hover:shadow-zinc-200/50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700 dark:hover:shadow-zinc-900/50">
                            <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                                <flux:icon.shield-check class="!h-5 !w-5" />
                            </div>
                            <h3 class="mb-2 font-semibold text-zinc-900 dark:text-white">Firewall Management</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Automatic UFW configuration with dynamic IP allowlists. Ports are opened only between cluster nodes, keeping your infrastructure locked down.</p>
                        </div>

                        <div class="group rounded-xl border border-zinc-200 bg-white p-6 transition-all hover:border-zinc-300 hover:shadow-lg hover:shadow-zinc-200/50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700 dark:hover:shadow-zinc-900/50">
                            <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-cyan-100 text-cyan-600 dark:bg-cyan-900/30 dark:text-cyan-400">
                                <flux:icon.document-text class="!h-5 !w-5" />
                            </div>
                            <h3 class="mb-2 font-semibold text-zinc-900 dark:text-white">Log Streaming</h3>
                            <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Stream error logs, slow query logs, general logs, and systemd journals from any node in real time. Debug issues without leaving the browser.</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Architecture diagram --}}
            <section class="border-t border-zinc-200/80 bg-zinc-50 px-6 py-24 dark:border-zinc-800/80 dark:bg-zinc-900/50">
                <div class="mx-auto max-w-4xl">
                    <div class="mb-4 text-center">
                        <p class="text-sm font-semibold uppercase tracking-widest text-blue-600 dark:text-blue-400">Architecture</p>
                    </div>
                    <div class="mb-16 text-center">
                        <h2 class="text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">How It Works</h2>
                        <p class="mx-auto mt-4 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                            PHPMyCluster runs on a separate control node and manages your entire cluster infrastructure over SSH.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm sm:p-12 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex flex-col items-center gap-4">
                            {{-- Control node --}}
                            <div class="flex w-full max-w-sm items-center gap-4 rounded-xl border-2 border-blue-500 bg-blue-50 p-4 dark:border-blue-600 dark:bg-blue-950/50">
                                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
                                    <x-app-logo-icon class="h-6 w-6" />
                                </div>
                                <div>
                                    <p class="font-semibold text-blue-900 dark:text-blue-200">Control Node</p>
                                    <p class="text-xs text-blue-700 dark:text-blue-400">PHPMyCluster + Laravel + SQLite</p>
                                </div>
                            </div>

                            {{-- SSH connector --}}
                            <div class="flex flex-col items-center">
                                <div class="h-6 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-4 py-1.5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <flux:icon.lock-closed class="!h-3.5 !w-3.5 text-zinc-500" />
                                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">SSH + mysqlsh</span>
                                </div>
                                <div class="h-6 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                            </div>

                            {{-- Cluster nodes --}}
                            <div class="grid w-full max-w-lg grid-cols-3 gap-3">
                                <div class="rounded-xl border-2 border-green-400 bg-green-50 p-4 text-center dark:border-green-600 dark:bg-green-950/50">
                                    <div class="mx-auto mb-2 flex h-8 w-8 items-center justify-center rounded-full bg-green-500 text-white">
                                        <flux:icon.circle-stack class="!h-4 !w-4" />
                                    </div>
                                    <p class="text-sm font-semibold text-green-900 dark:text-green-200">Primary</p>
                                    <p class="mt-0.5 text-xs text-green-700 dark:text-green-400">Read / Write</p>
                                </div>
                                <div class="rounded-xl border border-zinc-300 bg-zinc-50 p-4 text-center dark:border-zinc-600 dark:bg-zinc-800">
                                    <div class="mx-auto mb-2 flex h-8 w-8 items-center justify-center rounded-full bg-zinc-400 text-white dark:bg-zinc-600">
                                        <flux:icon.circle-stack class="!h-4 !w-4" />
                                    </div>
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-200">Secondary</p>
                                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Read Only</p>
                                </div>
                                <div class="rounded-xl border border-zinc-300 bg-zinc-50 p-4 text-center dark:border-zinc-600 dark:bg-zinc-800">
                                    <div class="mx-auto mb-2 flex h-8 w-8 items-center justify-center rounded-full bg-zinc-400 text-white dark:bg-zinc-600">
                                        <flux:icon.circle-stack class="!h-4 !w-4" />
                                    </div>
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-200">Secondary</p>
                                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Read Only</p>
                                </div>
                            </div>

                            {{-- Group Replication label --}}
                            <div class="flex items-center gap-3">
                                <div class="h-px w-12 bg-zinc-300 dark:bg-zinc-600"></div>
                                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Group Replication</span>
                                <div class="h-px w-12 bg-zinc-300 dark:bg-zinc-600"></div>
                            </div>

                            {{-- Router connector --}}
                            <div class="flex flex-col items-center">
                                <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                            </div>

                            {{-- Router / Access node --}}
                            <div class="flex w-full max-w-sm items-center gap-4 rounded-xl border-2 border-purple-400 bg-purple-50 p-4 dark:border-purple-600 dark:bg-purple-950/50">
                                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-600 text-white">
                                    <flux:icon.arrows-right-left class="!h-5 !w-5" />
                                </div>
                                <div>
                                    <p class="font-semibold text-purple-900 dark:text-purple-200">MySQL Router</p>
                                    <p class="text-xs text-purple-700 dark:text-purple-400">R/W :6446 &middot; R/O :6447 &middot; Auto failover</p>
                                </div>
                            </div>

                            {{-- App connector --}}
                            <div class="flex flex-col items-center">
                                <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-4 py-1.5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">Port 6446 / 6447</span>
                                </div>
                                <div class="h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                            </div>

                            {{-- Application --}}
                            <div class="flex w-full max-w-sm items-center gap-4 rounded-xl border border-zinc-300 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800">
                                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-zinc-700 text-white dark:bg-zinc-600">
                                    <flux:icon.code-bracket class="!h-5 !w-5" />
                                </div>
                                <div>
                                    <p class="font-semibold text-zinc-900 dark:text-zinc-200">Your Application</p>
                                    <p class="text-xs text-zinc-600 dark:text-zinc-400">Connects via MySQL Router</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Tech stack --}}
            <section class="border-t border-zinc-200/80 px-6 py-16 dark:border-zinc-800/80">
                <div class="mx-auto max-w-4xl text-center">
                    <p class="mb-8 text-sm font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">Built With</p>
                    <div class="flex flex-wrap items-center justify-center gap-6">
                        @foreach(['Laravel 13', 'Livewire 3', 'Flux UI', 'MySQL Shell AdminAPI', 'Tailwind CSS', 'phpseclib'] as $tech)
                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-4 py-2 text-sm font-medium text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">{{ $tech }}</span>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- CTA --}}
            <section class="border-t border-zinc-200/80 dark:border-zinc-800/80">
                <div class="mx-auto max-w-4xl px-6 py-24 text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                        Ready to manage your cluster?
                    </h2>
                    <p class="mx-auto mt-4 max-w-xl text-lg text-zinc-600 dark:text-zinc-400">
                        Get started in minutes. All you need is SSH access to your servers.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center justify-center gap-4">
                        @auth
                            <flux:button variant="primary" :href="route('dashboard')">
                                {{ __('Go to Dashboard') }}
                            </flux:button>
                        @else
                            @if(Route::has('register'))
                                <flux:button variant="primary" :href="route('register')">
                                    {{ __('Get Started Free') }}
                                </flux:button>
                            @endif
                        @endauth
                    </div>
                </div>
            </section>
        </main>


        @fluxScripts
    </body>
</html>
