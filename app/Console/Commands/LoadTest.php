<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @codeCoverageIgnore
 */
class LoadTest extends Command
{
    protected $signature = 'loadtest
        {action : Action to perform: seed, reset, or status}
        {--host=127.0.0.1 : MySQL host}
        {--port=6446 : MySQL port}
        {--database=test : Database name}
        {--username=test : MySQL username}
        {--password= : MySQL password}
        {--size=5 : Target data size in GB (approximate)}
        {--batch-size=5000 : Rows per INSERT batch}';

    protected $description = 'Load test tool: create schema, generate bulk data, or reset a MySQL database';

    private string $connection = 'loadtest';

    public function handle(): int
    {
        $this->configureConnection();

        try {
            DB::connection($this->connection)->getPdo();
        } catch (\Exception $e) {
            $this->error("Cannot connect: {$e->getMessage()}");

            return 1;
        }

        return match ($this->argument('action')) {
            'seed' => $this->seed(),
            'reset' => $this->reset(),
            'status' => $this->status(),
            default => $this->invalidAction(),
        };
    }

    private function configureConnection(): void
    {
        config(["database.connections.{$this->connection}" => [
            'driver' => 'mysql',
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'database' => $this->option('database'),
            'username' => $this->option('username'),
            'password' => $this->option('password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => '',
            'strict' => true,
        ]]);
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }

    // ── Schema ───────────────────────────────────────────────────────────

    private function createSchema(): void
    {
        $this->info('Creating schema...');
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('categories')) {
            $schema->create('categories', function ($t) {
                $t->integer('category_id')->primary();
                $t->string('name', 100)->nullable();
                $t->text('description')->nullable();
                $t->integer('parent_category_id')->nullable();
            });
        }

        if (! $schema->hasTable('suppliers')) {
            $schema->create('suppliers', function ($t) {
                $t->integer('supplier_id')->primary();
                $t->string('company_name', 150)->nullable();
                $t->string('contact_name', 100)->nullable();
                $t->string('email', 100)->nullable();
                $t->string('phone', 20)->nullable();
                $t->string('country', 50)->nullable();
                $t->boolean('is_active')->nullable();
            });
        }

        if (! $schema->hasTable('customers')) {
            $schema->create('customers', function ($t) {
                $t->integer('customer_id')->primary();
                $t->string('first_name', 50)->nullable();
                $t->string('last_name', 50)->nullable();
                $t->string('email', 100)->nullable();
                $t->string('phone', 20)->nullable();
                $t->date('date_of_birth')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->boolean('is_active')->nullable();
            });
        }

        if (! $schema->hasTable('employees')) {
            $schema->create('employees', function ($t) {
                $t->integer('employee_id')->primary();
                $t->string('first_name', 50)->nullable();
                $t->string('last_name', 50)->nullable();
                $t->string('email', 100)->nullable();
                $t->string('department', 50)->nullable();
                $t->string('job_title', 100)->nullable();
                $t->decimal('salary', 10, 2)->nullable();
                $t->date('hire_date')->nullable();
                $t->integer('manager_id')->nullable();
            });
        }

        if (! $schema->hasTable('products')) {
            $schema->create('products', function ($t) {
                $t->integer('product_id')->primary();
                $t->string('name', 150)->nullable();
                $t->integer('category_id')->nullable();
                $t->decimal('price', 10, 2)->nullable();
                $t->integer('stock_quantity')->nullable();
                $t->string('sku', 50)->nullable();
                $t->text('description')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->foreign('category_id')->references('category_id')->on('categories')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('orders')) {
            $schema->create('orders', function ($t) {
                $t->integer('order_id')->primary();
                $t->integer('customer_id')->nullable();
                $t->timestamp('order_date')->nullable();
                $t->string('status', 20)->nullable();
                $t->text('shipping_address')->nullable();
                $t->decimal('total_amount', 10, 2)->nullable();
                $t->text('notes')->nullable();
                $t->foreign('customer_id')->references('customer_id')->on('customers')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('order_items')) {
            $schema->create('order_items', function ($t) {
                $t->integer('order_item_id')->primary();
                $t->integer('order_id')->nullable();
                $t->unsignedBigInteger('product_id')->nullable();
                $t->integer('quantity')->nullable();
                $t->decimal('unit_price', 10, 2)->nullable();
                $t->decimal('discount', 5, 2)->nullable();
                $t->foreign('order_id')->references('order_id')->on('orders')->nullOnDelete();
                $t->foreign('product_id')->references('product_id')->on('products')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('product_suppliers')) {
            $schema->create('product_suppliers', function ($t) {
                $t->integer('product_id');
                $t->integer('supplier_id');
                $t->decimal('cost_price', 10, 2)->nullable();
                $t->integer('lead_time_days')->nullable();
                $t->primary(['product_id', 'supplier_id']);
                $t->foreign('product_id')->references('product_id')->on('products')->nullOnDelete();
                $t->foreign('supplier_id')->references('supplier_id')->on('suppliers')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('reviews')) {
            $schema->create('reviews', function ($t) {
                $t->integer('review_id')->primary();
                $t->unsignedBigInteger('product_id')->nullable();
                $t->integer('customer_id')->nullable();
                $t->integer('rating')->nullable();
                $t->string('title', 200)->nullable();
                $t->text('body')->nullable();
                $t->timestamp('review_date')->nullable();
                $t->integer('helpful_votes')->nullable();
                $t->foreign('product_id')->references('product_id')->on('products')->nullOnDelete();
                $t->foreign('customer_id')->references('customer_id')->on('customers')->nullOnDelete();
            });
        }

        $this->info('Schema ready.');
    }

    // ── Seed ─────────────────────────────────────────────────────────────

    private function seed(): int
    {
        $this->createSchema();

        $targetGb = (float) $this->option('size');
        $batchSize = (int) $this->option('batch-size');

        // Scale row counts relative to target size
        $scale = $targetGb / 5.0;
        $customerCount = (int) (500_000 * $scale);
        $productCount = (int) (20_000 * $scale);
        $orderCount = (int) (2_000_000 * $scale);
        $orderItemCount = (int) (6_000_000 * $scale);
        $reviewCount = (int) (4_000_000 * $scale);

        // Disable FK checks for bulk loading speed and to handle non-contiguous IDs
        $this->db()->statement('SET FOREIGN_KEY_CHECKS=0');

        $this->seedCategories(200);
        $this->seedSuppliers(1_000);
        $this->seedEmployees(5_000);
        $this->seedCustomers($customerCount, $batchSize);
        $this->seedProducts($productCount, $batchSize);
        $this->seedProductSuppliers(50_000, $batchSize);
        $this->seedOrders($orderCount, $batchSize);
        $this->seedOrderItems($orderItemCount, $batchSize);
        $this->seedReviews($reviewCount, $batchSize);

        $this->db()->statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->status();

        return 0;
    }

    private function seedCategories(int $count): void
    {
        $existing = $this->db()->table('categories')->count();
        if ($existing >= $count) {
            $this->line("  categories: {$existing} rows (skipped)");

            return;
        }

        $adjectives = ['Premium', 'Budget', 'Seasonal', 'Trending', 'Classic', 'New', 'Sale', 'Featured'];
        $names = ['Electronics', 'Clothing', 'Home', 'Sports', 'Books', 'Toys', 'Garden', 'Auto', 'Food', 'Health'];

        $rows = [];
        for ($i = $existing + 1; $i <= $count; $i++) {
            $rows[] = [
                'name' => $names[array_rand($names)].' - '.$adjectives[array_rand($adjectives)],
                'description' => $this->fakeText(150),
                'parent_category_id' => $i > 10 && rand(0, 100) < 30 ? rand(1, min($i - 1, 50)) : null,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db()->table('categories')->insert($chunk);
        }

        $this->line("  categories: {$count} rows");
    }

    private function seedSuppliers(int $count): void
    {
        $existing = $this->db()->table('suppliers')->count();
        if ($existing >= $count) {
            $this->line("  suppliers: {$existing} rows (skipped)");

            return;
        }

        $prefixes = ['Global', 'Pacific', 'Atlantic', 'Northern', 'Southern', 'Eastern', 'Western', 'Central', 'Premier', 'Elite'];
        $suffixes = ['Trading', 'Supply', 'Wholesale', 'Distribution', 'Manufacturing', 'Industries', 'Logistics', 'Enterprises'];
        $countries = ['USA', 'Canada', 'UK', 'Germany', 'France', 'Japan', 'China', 'India', 'Brazil', 'Australia', 'Mexico', 'Italy', 'Spain', 'Netherlands', 'Sweden'];

        $rows = [];
        for ($i = $existing + 1; $i <= $count; $i++) {
            $rows[] = [
                'company_name' => $prefixes[array_rand($prefixes)].' '.$suffixes[array_rand($suffixes)]." {$i}",
                'contact_name' => $this->fakeName(),
                'email' => "supplier{$i}@".$this->fakeDomain(),
                'phone' => $this->fakePhone(),
                'country' => $countries[array_rand($countries)],
                'is_active' => rand(0, 100) < 85,
            ];
            if (count($rows) >= 500) {
                $this->db()->table('suppliers')->insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            $this->db()->table('suppliers')->insert($rows);
        }

        $this->line("  suppliers: {$count} rows");
    }

    private function seedEmployees(int $count): void
    {
        $existing = $this->db()->table('employees')->count();
        if ($existing >= $count) {
            $this->line("  employees: {$existing} rows (skipped)");

            return;
        }

        $departments = ['Engineering', 'Sales', 'Marketing', 'Support', 'Operations', 'Finance', 'HR', 'Product'];
        $titles = ['Engineer', 'Manager', 'Analyst', 'Specialist', 'Director', 'Coordinator', 'Lead', 'Associate', 'Senior Engineer', 'VP'];

        $rows = [];
        for ($i = $existing + 1; $i <= $count; $i++) {
            $rows[] = [
                'first_name' => $this->fakeFirstName(),
                'last_name' => $this->fakeLastName(),
                'email' => "emp{$i}@company.com",
                'department' => $departments[array_rand($departments)],
                'job_title' => $titles[array_rand($titles)],
                'salary' => round(35000 + mt_rand(0, 165000) + mt_rand(0, 99) / 100, 2),
                'hire_date' => $this->fakeDate('2015-01-01', '2025-01-01'),
                'manager_id' => $i <= 10 ? null : rand(1, min($i - 1, 500)),
            ];
            if (count($rows) >= 500) {
                $this->db()->table('employees')->insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            $this->db()->table('employees')->insert($rows);
        }

        $this->line("  employees: {$count} rows");
    }

    private function seedCustomers(int $count, int $batchSize): void
    {
        $this->bulkSeed('customers', $count, $batchSize, function (int $i) {
            return [
                'customer_id' => $i,
                'first_name' => $this->fakeFirstName(),
                'last_name' => $this->fakeLastName(),
                'email' => "c{$i}@".$this->fakeDomain(),
                'phone' => $this->fakePhone(),
                'date_of_birth' => $this->fakeDate('1960-01-01', '2005-01-01'),
                'created_at' => $this->fakeTimestamp('2020-01-01', '2025-01-01'),
                'is_active' => rand(0, 100) < 90,
            ];
        });
    }

    private function seedProducts(int $count, int $batchSize): void
    {
        $maxCat = $this->db()->table('categories')->max('category_id') ?: 1;

        $prefixes = ['Ultra', 'Pro', 'Max', 'Elite', 'Prime', 'Core', 'Flex', 'Smart', 'Turbo', 'Nano', 'Mega', 'Super'];
        $types = ['Widget', 'Gadget', 'Device', 'Tool', 'Accessory', 'Component', 'Module', 'Unit', 'System', 'Kit', 'Pack', 'Set', 'Bundle', 'Adapter', 'Connector'];
        $variants = ['X100', 'V2', 'Plus', 'Lite', 'Pro', 'Mini', 'XL', 'Standard'];

        $this->bulkSeed('products', $count, $batchSize, function (int $i) use ($maxCat, $prefixes, $types, $variants) {
            return [
                'product_id' => $i,
                'name' => $prefixes[array_rand($prefixes)].' '.$types[array_rand($types)].' '.$variants[array_rand($variants)]."-{$i}",
                'category_id' => rand(1, $maxCat),
                'price' => round(1.99 + mt_rand(0, 99800) / 100, 2),
                'stock_quantity' => rand(0, 10000),
                'sku' => 'SKU-'.str_pad($i, 6, '0', STR_PAD_LEFT),
                'description' => $this->fakeText(400),
                'created_at' => $this->fakeTimestamp('2021-01-01', '2025-06-01'),
            ];
        });
    }

    private function seedProductSuppliers(int $count, int $batchSize): void
    {
        $maxProduct = $this->db()->table('products')->max('product_id') ?: 1;
        $maxSupplier = $this->db()->table('suppliers')->max('supplier_id') ?: 1;

        $existing = $this->db()->table('product_suppliers')->count();
        if ($existing >= $count) {
            $this->line("  product_suppliers: {$existing} rows (skipped)");

            return;
        }

        $this->info("Seeding product_suppliers ({$count} rows)...");
        $bar = $this->output->createProgressBar($count - $existing);
        $inserted = 0;
        $attempts = 0;
        $maxAttempts = $count * 3;

        while ($inserted < ($count - $existing) && $attempts < $maxAttempts) {
            $rows = [];
            $seen = [];
            $needed = min($batchSize, $count - $existing - $inserted);

            for ($j = 0; $j < $needed * 2 && count($rows) < $needed; $j++) {
                $pid = rand(1, $maxProduct);
                $sid = rand(1, $maxSupplier);
                $key = "{$pid}-{$sid}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'product_id' => $pid,
                    'supplier_id' => $sid,
                    'cost_price' => round(0.50 + mt_rand(0, 50000) / 100, 2),
                    'lead_time_days' => rand(1, 90),
                ];
            }

            if (empty($rows)) {
                break;
            }

            try {
                $this->db()->table('product_suppliers')->insertOrIgnore($rows);
                $inserted += count($rows);
                $bar->advance(count($rows));
            } catch (\Exception $e) {
                // duplicate key - continue
            }

            $attempts += count($rows);
        }

        $bar->finish();
        $this->newLine();
    }

    private function seedOrders(int $count, int $batchSize): void
    {
        $maxCustomer = $this->db()->table('customers')->max('customer_id') ?: 1;
        $statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];
        $streets = ['Oak', 'Maple', 'Cedar', 'Pine', 'Elm', 'Birch', 'Willow', 'Cherry', 'Ash', 'Spruce', 'Walnut', 'Magnolia'];
        $streetTypes = ['Street', 'Avenue', 'Boulevard', 'Drive', 'Lane', 'Court'];
        $cities = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'Austin', 'Jacksonville', 'Columbus', 'Charlotte', 'Indianapolis', 'Seattle'];
        $states = ['NY', 'CA', 'TX', 'FL', 'IL', 'PA', 'OH', 'GA', 'NC', 'WA'];

        $this->bulkSeed('orders', $count, $batchSize, function (int $i) use ($maxCustomer, $statuses, $streets, $streetTypes, $cities, $states) {
            $address = rand(1, 9999).' '.$streets[array_rand($streets)].' '.$streetTypes[array_rand($streetTypes)]
                .', Apt '.rand(1, 500).', '.$cities[array_rand($cities)].', '.$states[array_rand($states)]
                .' '.str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);

            return [
                'order_id' => $i,
                'customer_id' => rand(1, $maxCustomer),
                'order_date' => $this->fakeTimestamp('2022-01-01', '2025-03-01'),
                'status' => $statuses[array_rand($statuses)],
                'shipping_address' => $address.'. '.$this->fakeText(200),
                'total_amount' => round(10 + mt_rand(0, 99000) / 100, 2),
                'notes' => $this->fakeText(400),
            ];
        });
    }

    private function seedOrderItems(int $count, int $batchSize): void
    {
        $maxOrder = $this->db()->table('orders')->max('order_id') ?: 1;
        $maxProduct = $this->db()->table('products')->max('product_id') ?: 1;

        $this->bulkSeed('order_items', $count, $batchSize, function (int $i) use ($maxOrder, $maxProduct) {
            return [
                'order_item_id' => $i,
                'order_id' => rand(1, $maxOrder),
                'product_id' => rand(1, $maxProduct),
                'quantity' => rand(1, 20),
                'unit_price' => round(1.99 + mt_rand(0, 49900) / 100, 2),
                'discount' => round(mt_rand(0, 3000) / 100, 2),
            ];
        });
    }

    private function seedReviews(int $count, int $batchSize): void
    {
        $maxProduct = $this->db()->table('products')->max('product_id') ?: 1;
        $maxCustomer = $this->db()->table('customers')->max('customer_id') ?: 1;

        $titles = [
            'Excellent product!', 'Good value', 'Disappointing quality', 'Exceeded expectations', 'Average product',
            'Best purchase ever', 'Not worth the price', 'Solid and reliable', 'Could be better', 'Highly recommended',
        ];
        $suffixes = ['Would buy again', 'Use it every day', 'Perfect gift', 'Great for beginners', 'Pro grade', 'Family favorite'];

        $this->bulkSeed('reviews', $count, $batchSize, function (int $i) use ($maxProduct, $maxCustomer, $titles, $suffixes) {
            return [
                'review_id' => $i,
                'product_id' => rand(1, $maxProduct),
                'customer_id' => rand(1, $maxCustomer),
                'rating' => rand(1, 5),
                'title' => $titles[array_rand($titles)].' - '.$suffixes[array_rand($suffixes)],
                'body' => $this->fakeText(800),
                'review_date' => $this->fakeTimestamp('2022-06-01', '2025-04-01'),
                'helpful_votes' => rand(0, 200),
            ];
        });
    }

    // ── Bulk insert helper ───────────────────────────────────────────────

    private function bulkSeed(string $table, int $target, int $batchSize, callable $rowFactory): void
    {
        $existing = $this->db()->table($table)->count();
        if ($existing >= $target) {
            $this->line("  {$table}: {$existing} rows (skipped)");

            return;
        }

        $needed = $target - $existing;
        $this->info("Seeding {$table} ({$needed} new rows, {$existing} existing)...");
        $bar = $this->output->createProgressBar($needed);

        $startId = ($this->db()->table($table)->max($table === 'order_items' ? 'order_item_id' : (rtrim($table, 's').'_id')) ?: 0) + 1;

        $inserted = 0;
        while ($inserted < $needed) {
            $chunk = min($batchSize, $needed - $inserted);
            $rows = [];
            for ($j = 0; $j < $chunk; $j++) {
                $rows[] = $rowFactory($startId + $inserted + $j);
            }

            $this->db()->table($table)->insert($rows);
            $inserted += $chunk;
            $bar->advance($chunk);
        }

        $bar->finish();
        $this->newLine();
    }

    // ── Reset ────────────────────────────────────────────────────────────

    private function reset(): int
    {
        if (! $this->confirm('This will DROP all load test tables and recreate them empty. Continue?')) {
            $this->info('Aborted.');

            return 0;
        }

        $this->info('Dropping tables...');
        $schema = Schema::connection($this->connection);

        // Drop in FK-safe order
        $tables = ['reviews', 'order_items', 'product_suppliers', 'orders', 'products', 'employees', 'customers', 'suppliers', 'categories'];
        $this->db()->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
                $this->line("  Dropped {$table}");
            }
        }
        $this->db()->statement('SET FOREIGN_KEY_CHECKS=1');

        $this->createSchema();
        $this->info('Database reset complete.');

        return 0;
    }

    // ── Status ───────────────────────────────────────────────────────────

    private function status(): int
    {
        $this->info('Table status:');

        $tables = ['categories', 'suppliers', 'employees', 'customers', 'products', 'product_suppliers', 'orders', 'order_items', 'reviews'];
        $schema = Schema::connection($this->connection);

        $totalBytes = 0;
        $rows = [];

        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                $rows[] = [$table, 'N/A', 'N/A'];

                continue;
            }

            $count = $this->db()->table($table)->count();
            $sizeResult = $this->db()->selectOne(
                'SELECT DATA_LENGTH + INDEX_LENGTH AS size_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$this->option('database'), $table]
            );

            $bytes = $sizeResult->size_bytes ?? 0;
            $totalBytes += $bytes;
            $rows[] = [$table, number_format($count), $this->formatBytes($bytes)];
        }

        $this->table(['Table', 'Rows', 'Size'], $rows);
        $this->info('Total size: '.$this->formatBytes($totalBytes));

        return 0;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function invalidAction(): int
    {
        $this->error('Invalid action. Use: seed, reset, or status');

        return 1;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    private static array $firstNames = ['James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda', 'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Charles', 'Karen'];

    private static array $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Anderson', 'Taylor', 'Thomas', 'Jackson', 'White', 'Harris', 'Martin', 'Thompson', 'Robinson', 'Clark'];

    private static array $domains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'mail.com', 'proton.me'];

    private static array $textFragments = [
        'This premium product delivers exceptional performance and reliability for demanding users. ',
        'Designed with cutting-edge technology, this item offers unmatched value and durability. ',
        'Our customers love this product for its ease of use and outstanding build quality. ',
        'Featuring advanced materials and innovative design, this product exceeds expectations. ',
        'A top-rated choice among professionals and enthusiasts alike, built to last. ',
        'After extensive research, I decided to go with this product and could not be happier. ',
        'The build quality is excellent and it feels very sturdy and well-constructed. ',
        'I was initially skeptical but after using it for a month, it delivers on every promise. ',
        'Having tried several competing products, this one stands out from the crowd. ',
        'The customer service team was incredibly helpful with setup and configuration. ',
        'From unboxing to first use, the experience was seamless and straightforward. ',
        'The materials used are top-notch and the craftsmanship is evident in every detail. ',
        'Performance-wise, this exceeds what you would expect at this price point. ',
        'The design is both functional and aesthetically pleasing, fitting perfectly in any space. ',
        'Setup was straightforward thanks to the clear instructions included in the package. ',
        'The warranty and return policy gave me confidence in making this purchase. ',
        'Available in multiple configurations to suit your specific needs and requirements. ',
        'Compatible with a wide range of accessories and complementary products in the ecosystem. ',
        'Environmentally conscious manufacturing with sustainable materials throughout. ',
        'Backed by comprehensive warranty and world-class customer support team. ',
    ];

    private function fakeFirstName(): string
    {
        return self::$firstNames[array_rand(self::$firstNames)];
    }

    private function fakeLastName(): string
    {
        return self::$lastNames[array_rand(self::$lastNames)];
    }

    private function fakeName(): string
    {
        return $this->fakeFirstName().' '.$this->fakeLastName();
    }

    private function fakeDomain(): string
    {
        return self::$domains[array_rand(self::$domains)];
    }

    private function fakePhone(): string
    {
        return sprintf('+1-%03d-%04d', rand(100, 999), rand(0, 9999));
    }

    private function fakeDate(string $from, string $to): string
    {
        $start = strtotime($from);
        $end = strtotime($to);

        return date('Y-m-d', rand($start, $end));
    }

    private function fakeTimestamp(string $from, string $to): string
    {
        $start = strtotime($from);
        $end = strtotime($to);

        return date('Y-m-d H:i:s', rand($start, $end));
    }

    private function fakeText(int $approxLength): string
    {
        $text = '';
        while (strlen($text) < $approxLength) {
            $text .= self::$textFragments[array_rand(self::$textFragments)];
        }

        return $text;
    }
}
