<?php

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$app = require __DIR__.'/local-bootstrap.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath(getenv('LOAD_STORAGE').'/load.sqlite')) {
    throw new RuntimeException('Refusing a non-isolated database.');
}
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--force' => true]);

function cookieFor(array $values): string
{
    $session = app('session')->driver();
    $session->flush();
    $session->setId(Str::random(40));
    $session->start();
    $session->replace($values);
    $session->save();
    $name = config('session.cookie');
    $value = CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId();

    return $name.'='.rawurlencode(app('encrypter')->encrypt($value, false));
}

$companies = [];
$clients = [];
$password = Hash::make('LocalSynthetic123!');
DB::transaction(function () use (&$companies, &$clients, $password) {
    for ($i = 1; $i <= 110; $i++) {
        $marker = sprintf('EmpresaCarga%04d', $i);
        $company = Company::create(['plan_id' => Plan::where('price', 14900)->value('id'), 'name' => $marker, 'slug' => 'carga-'.$i, 'status' => 'active', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'modules' => array_keys(config('poseitech.modules'))]);
        $user = User::create(['company_id' => $company->id, 'role' => 'admin', 'name' => $marker, 'email' => "empresa$i@example.test", 'password' => $password, 'active' => true, 'must_change_password' => false]);
        $companies[] = ['marker' => $marker, 'cookie' => cookieFor([app('auth')->guard()->getName() => $user->id])];
        DB::table('products')->insert(['company_id' => $company->id, 'name' => 'Produto'.$marker, 'price' => 1000, 'cost' => 100, 'stock' => 100, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        for ($j = 1; $j <= 10; $j++) {
            $name = sprintf('ClienteCarga%04d', (($i - 1) * 10) + $j);
            $hash = hash('sha256', $name);
            $id = DB::table('customers')->insertGetId(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@example.test', 'portal_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $clients[] = ['marker' => $name, 'company' => $marker, 'slug' => $company->slug, 'cookie' => cookieFor(['portal' => ['id' => $id, 'company' => $company->id, 'hash' => $hash]])];
        }
    }
});
file_put_contents(getenv('LOAD_STORAGE').'/actors.json', json_encode(compact('companies', 'clients'), JSON_THROW_ON_ERROR));
