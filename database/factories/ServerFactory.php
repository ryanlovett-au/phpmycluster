<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'name' => 'server-'.fake()->numberBetween(1, 99),
            'host' => fake()->ipv4(),
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_private_key_encrypted' => 'test-key-content',
            'ssh_public_key' => 'ssh-ed25519 AAAA testkey',
            'ssh_key_fingerprint' => null,
            'ram_mb' => null,
            'cpu_cores' => null,
            'os_name' => null,
        ];
    }
}
