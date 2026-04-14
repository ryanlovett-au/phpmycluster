# Project Rules

## Destructive Actions
This tool must NEVER take destructive action without prompting the user first. Any operation that could destroy data, remove packages, overwrite configurations, or make irreversible changes must be presented to the user as an option — not executed automatically. Always explain what will happen and let the user confirm.

## Architecture Decisions
Before making significant architectural changes (e.g. switching JS to Python mode, restructuring services, changing install strategies), stop and ask the user's opinion first. Don't assume.

## Tech Stack
- Laravel 13 + Livewire 3 + Flux UI components
- Tailwind CSS v4 (uses @import 'tailwindcss', @source, @theme — no tailwind.config.js)
- MySQL InnoDB Cluster managed via MySQL Shell (mysqlsh) AdminAPI in JavaScript mode (--js)
- MySQL packages must come from the official MySQL APT repository (repo.mysql.com), not Ubuntu's default packages
- phpseclib3 for SSH connections
- Queue jobs with database driver for long-running operations
- Cache-based progress tracking for async job status

## Flux UI
- Valid button variants: primary, filled, outline, danger, ghost, subtle (NOT "warning")
- Valid button sizes: base, sm, xs (NOT "lg")
- Use inline styles where Flux overrides Tailwind classes

## Code Style
- Run `./vendor/bin/pint` after modifying PHP files
- Use "Primary Node" terminology (not "Seed Node")
