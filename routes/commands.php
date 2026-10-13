<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Analytics;
use App\Services\CatalogPix;
use App\Services\DatabaseCapacity;
use App\Services\DeliveryQueue;
use App\Services\MercadoPago;
use App\Services\Tenant;
use App\Services\WhatsApp;
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
    foreach (Company::with('plan')->where('status', 'active')->lazyById(50) as $company) {
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
    app(DeliveryQueue::class)->process('email', $limit);
    $this->info('Fila processada.');
});
Schedule::command('poseitech:automate')->dailyAt('06:00')->withoutOverlapping()->when(fn () => DatabaseCapacity::remaining() === 0);

Artisan::command('poseitech:whatsapp {--limit=50}', function () {
    DB::table('whatsapp_messages')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(2))->update(['status' => 'pending', 'updated_at' => now()]);
    app(DeliveryQueue::class)->process('whatsapp', max(1, min(500, (int) $this->option('limit'))));
    $this->info('Fila WhatsApp processada.');
});

Artisan::command('poseitech:mercadopago {--limit=30}', function () {
    $query = DB::table('mp_checkouts')->whereIn('status', ['pending', 'unknown', 'creating', 'approved', 'review'])->where('created_at', '>', now()->subDays(180));
    foreach ($query->orderBy('updated_at')->limit(max(1, min(100, (int) $this->option('limit'))))->get() as $checkout) {
        $company = Company::find($checkout->company_id);
        if (! $company || empty($company->mercadopago['access_token'])) {
            continue;
        }
        app(Tenant::class)->company = $company;
        try {
            if (($checkout->kind ?? 'preference') === 'pix') {
                if (! $checkout->pix_provider_id && in_array($checkout->status, ['creating', 'unknown']) && now()->lt($checkout->expires_at)) {
                    app(CatalogPix::class)->generate($checkout);
                }
                app(MercadoPago::class)->refresh(app(Tenant::class)->find('mp_checkouts', $checkout->id));
                $fresh = app(Tenant::class)->find('mp_checkouts', $checkout->id);
                if ($fresh->status === 'pending' && $fresh->pix_provider_id && now()->gte($fresh->expires_at)) {
                    app(MercadoPago::class)->api('PUT', '/v1/payments/'.$fresh->pix_provider_id, ['status' => 'cancelled']);
                    app(MercadoPago::class)->confirm($fresh->pix_provider_id);
                    $fresh = app(Tenant::class)->find('mp_checkouts', $checkout->id);
                }
                if (in_array($fresh->status, ['cancelled', 'rejected', 'refunded', 'charged_back'])) {
                    $order = app(Tenant::class)->query('catalog_orders')->where('checkout_id', $fresh->id)->whereNull('sale_id')->where('status', 'pending')->first();
                    if ($order) {
                        app(CatalogPix::class)->release($order);
                    }
                }

                continue;
            }
            if (! $checkout->checkout_url && in_array($checkout->status, ['creating', 'unknown']) && now()->lt($checkout->expires_at)) {
                app(MercadoPago::class)->recover($checkout);
            }
            app(MercadoPago::class)->refresh($checkout);
        } catch (Throwable $error) {
            $this->warn('Consulta indisponível para o link #'.$checkout->id.'. Será tentada novamente.');
        } finally {
            // Fair rotation, including preferences without any payment attempts yet.
            DB::table('mp_checkouts')->where('id', $checkout->id)->update(['updated_at' => now()]);
        }
    }
    $this->info('Pagamentos consultados.');
});
Schedule::command('poseitech:mercadopago')->everyFiveMinutes()->withoutOverlapping()->when(fn () => DatabaseCapacity::remaining() === 0);

Artisan::command('poseitech:deliver {--once : Processa um lote e encerra} {--limit=100} {--sleep=3} {--shard=0} {--shards=1}', function () {
    $limit = max(1, min(1000, (int) $this->option('limit')));
    $shards = max(1, min(32, (int) $this->option('shards')));
    $shard = (int) $this->option('shard');
    if ($shard < 0 || $shard >= $shards) {
        $this->error('Partição inválida. Use 0 até shards-1.');

        return 1;
    }
    do {
        if (DatabaseCapacity::remaining() === 0) {
            // Recover only journal-deduplicated WhatsApp sends. SMTP ambiguity is never replayed.
            DB::table('whatsapp_messages')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'pending', 'next_attempt_at' => now(), 'updated_at' => now()]);
            DB::table('email_logs')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'failed', 'next_attempt_at' => null, 'error' => 'Envio interrompido sem confirmação. Confira o destinatário antes de reenviar.', 'updated_at' => now()]);
            foreach (['email', 'whatsapp', 'payment'] as $channel) {
                app(DeliveryQueue::class)->process($channel, $limit, $this->option('once') ? 12 : 45, $shard, $shards);
            }
        }
        DeliveryQueue::releaseConnection();
        if ($this->option('once')) {
            break;
        }
        sleep(max(1, min(60, (int) $this->option('sleep'))));
    } while (true);
    $this->info('Filas automáticas processadas.');
});
Schedule::command('poseitech:deliver --once')->everyMinute()->withoutOverlapping(5)->when(fn () => ! config('delivery.daemon') && DatabaseCapacity::remaining() === 0);
