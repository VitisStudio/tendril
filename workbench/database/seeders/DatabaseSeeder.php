<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use VitisStudio\Tendril\Core\Testing\SyntheticDataset;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Demo data: an admin in two teams plus 60 days of synthetic analytics,
 * aggregated so every Filament page has something to show.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.test', 'name' => 'Demo Admin']);
        $teams = Team::factory()->count(2)->create();
        $admin->teams()->attach($teams->pluck('id'));

        (new SyntheticDataset([
            'tenants' => 2,
            'actors_per_tenant' => 12,
            'visitors_per_day' => 8,
            'days' => 60,
            'tenant_type' => (new Team)->getMorphClass(),
            'actor_type' => (new User)->getMorphClass(),
        ]))->generate();

        app(Aggregator::class)->drain();
    }
}
