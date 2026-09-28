<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Analytics;
use App\Services\MailQueue;
use App\Services\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('poseitech:admin {email} {--name=PoseiTech}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
        $this->error('E-mail inválido ou já cadastrado.');

        return 1;
    }
    $password = $this->secret('Senha (mínimo 10 caracteres, letras e números)');
    if (strlen($password) < 10 || ! preg_match('/[a-z]/i', $password) || ! preg_match('/\d/', $password)) {
        $this->error('Senha insuficiente.');

        return 1;
    }
    User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role' => 'super']);
    $this->info('Super Admin criado.');
});
Artisan::command('poseitech:automate', function () {
    foreach (Company::with('plan')->where('status', 'active')->cursor() as $company) {
        if (! $company->available()) {
            continue;
        }
        $t = app(Tenant::class);
        $t->company = $company;
        $analytics = app(Analytics::class);
        $alerts = [];
        if ($company->enabled('customers')) {
            $inactive = $analytics->customers('inactive')->count();
            if ($inactive) {
                $alerts['inactive'] = ['Clientes inativos', "$inactive clientes sem comprar há 30 dias.", '/records/customers?segment=inactive'];
            }
            $birthday = $analytics->customers('birthday')->count();
            if ($birthday) {
                $alerts['birthday'] = ['Aniversários próximos', "$birthday clientes fazem aniversário nos próximos 7 dias.", '/records/customers?segment=birthday'];
            }
        }
        if ($company->enabled('stock')) {
            $low = $t->query('products')->where('type', 'product')->where('active', true)->whereColumn('stock', '<=', 'min_stock')->count();
            if ($low) {
                $alerts['stock'] = ['Estoque baixo', "$low produtos precisam de reposição.", '/stock'];
            }
        }
        if ($company->enabled('finance')) {
            $overdue = $t->query('accounts')->where('status', 'pending')->where('due_date', '<', now()->toDateString())->count();
            if ($overdue) {
                $alerts['overdue'] = ['Contas vencidas', "$overdue contas vencidas precisam de atenção.", '/finance?status=overdue'];
            }
        }
        foreach ($alerts as $key => [$title,$body,$url]) {
            DB::table('alerts')->updateOrInsert(['company_id' => $company->id, 'key' => 'rule-'.$key], ['title' => $title, 'body' => $body, 'url' => $url, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($company->enabled('campaigns') && ($company->settings['birthday_automation'] ?? false)) {
            $customers = $t->query('customers')->whereNull('anonymized_at')->where('email_consent', true)->where('birthday', 'like', '%-'.now()->addDay()->format('m-d'))->count();
            $name = 'Aniversários • '.now()->addDay()->toDateString();
            if ($customers && ! $t->query('campaigns')->where('name', $name)->exists() && $t->query('campaigns')->count() < $company->plan->campaigns_limit) {
                $t->insert('campaigns', ['name' => $name, 'segment' => 'birthday', 'subject' => 'Feliz aniversário!', 'body' => 'Olá, {nome}! Desejamos um excelente aniversário.', 'days' => 1]);
            }
        }
    } $this->info('Alertas atualizados; campanhas de aniversário preparadas como rascunho.');
});
Artisan::command('poseitech:mail {--limit=50}', function () {
    $limit = max(1, min(500, (int) $this->option('limit')));
    foreach (DB::table('email_logs')->where('status', 'pending')->orderBy('id')->limit($limit)->get() as $email) {
        $company = Company::with('plan')->find($email->company_id);
        if (! $company || ! $company->available() || ! $company->smtp) {
            continue;
        }
        app(MailQueue::class)->send($email->id, $company);
    }
    foreach (DB::table('campaigns')->where('status', 'queued')->get() as $campaign) {
        $q = DB::table('email_logs')->where('campaign_id', $campaign->id);
        if (! (clone $q)->whereIn('status', ['pending', 'sending'])->exists()) {
            $failed = (clone $q)->where('status', 'failed')->exists();
            DB::table('campaigns')->where('id', $campaign->id)->update(['status' => $failed ? 'failed' : 'completed', 'updated_at' => now()]);
            DB::table('alerts')->insertOrIgnore(['company_id' => $campaign->company_id, 'key' => 'campaign-'.$campaign->id, 'title' => $failed ? 'Campanha com falhas' : 'Campanha concluída', 'body' => $campaign->name, 'url' => '/campaigns', 'created_at' => now(), 'updated_at' => now()]);
        }
    } $this->info('Fila processada.');
});
Schedule::command('poseitech:automate')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('poseitech:mail')->everyMinute()->withoutOverlapping();
