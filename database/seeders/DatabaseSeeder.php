<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([['Básico', 7900, 3, 1000, 500, 5, 100, config('poseitech.defaults')], ['Profissional', 14900, 10, 10000, 5000, 30, 500, array_keys(config('poseitech.modules'))], ['Premium', 24900, 50, 100000, 20000, 100, 2000, array_keys(config('poseitech.modules'))]] as [$name,$price,$users,$customers,$products,$campaigns,$storage,$modules]) {
            Plan::firstOrCreate(['name' => $name], ['price' => $price, 'users_limit' => $users, 'customers_limit' => $customers, 'products_limit' => $products, 'campaigns_limit' => $campaigns, 'storage_mb' => $storage, 'modules' => $modules]);
        }
    }
}
