<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Tenant
{
    public ?Company $company = null;

    private const TABLES = ['debt_receipts', 'customers', 'products', 'suppliers', 'sales', 'sale_items', 'payments', 'cash_registers', 'cash_transactions', 'accounts', 'stock_movements', 'coupons', 'loyalty_transactions', 'customer_credits', 'orders', 'campaigns', 'email_logs', 'alerts', 'goals', 'audit_logs'];

    public function id(): int
    {
        abort_unless($this->company, 403);

        return $this->company->id;
    }

    public function query(string $table)
    {
        abort_unless(in_array($table, self::TABLES), 404);

        return DB::table($table)->where($table.'.company_id', $this->id())->when($table === 'suppliers', fn ($q) => $q->whereNull('suppliers.deleted_at'));
    }

    public function find(string $table, int $id)
    {
        return $this->query($table)->where($table.'.id', $id)->firstOrFail();
    }

    public function insert(string $table, array $data): int
    {
        $this->query($table);

        return DB::table($table)->insertGetId(array_merge($data, ['company_id' => $this->id(), 'created_at' => now(), 'updated_at' => now()]));
    }

    public function update(string $table, int $id, array $data): void
    {
        unset($data['id'], $data['company_id'], $data['created_at']);
        $this->query($table)->where('id', $id)->update($data + ['updated_at' => now()]);
    }

    public function lock(): void
    {
        Company::whereKey($this->id())->lockForUpdate()->firstOrFail();
    }

    public function authorize(string $module, bool $write = false): void
    {
        abort_unless($this->company?->enabled($module) && auth()->user()?->allows($module, $write), 403, 'Módulo indisponível ou acesso não autorizado.');
    }

    public function limit(string $resource): void
    {
        $limit = $this->company->plan->{$resource.'_limit'};
        $count = $resource === 'users' ? DB::table('users')->where('company_id', $this->id())->count() : $this->query($resource)->count();
        if ($count >= $limit) {
            throw ValidationException::withMessages(['limit' => 'O limite do plano foi atingido. Contate a PoseiTech.']);
        }
    }

    public function audit(string $action, string $entity = '', ?int $id = null, $before = null, $after = null): void
    {
        DB::table('audit_logs')->insert(['company_id' => $this->company?->id, 'user_id' => auth()->id(), 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'before' => $before ? json_encode($before) : null, 'after' => $after ? json_encode($after) : null, 'ip' => request()->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function cents($value): int
    {
        $value = (string) ($value ?? '0');
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor válido com até duas casas decimais.']);
        }
        [$whole, $decimal] = array_pad(explode('.', $value), 2, '');

        return ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');
    }

    public static function money($cents): string
    {
        $currency = app(self::class)->company?->currency ?? 'BRL';
        $symbol = ['BRL' => 'R$', 'USD' => 'US$', 'EUR' => '€', 'GBP' => '£'][$currency] ?? $currency;

        return $symbol.' '.number_format(($cents ?? 0) / 100, 2, ',', '.');
    }
}
