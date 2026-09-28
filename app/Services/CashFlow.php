<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class CashFlow
{
    public function __construct(private Tenant $t) {}

    public function entries(string $until): Collection
    {
        $entries = collect();
        $transactions = $this->t->query('cash_transactions')->where('created_at', '<', $until)->get();
        foreach ($transactions->whereNull('payment_id') as $entry) {
            $entries->push(['date' => $entry->created_at, 'source' => 'Caixa #'.$entry->cash_register_id, 'description' => $entry->description, 'method' => $entry->method, 'amount' => (int) $entry->amount, 'key' => 'cash-'.$entry->id]);
        }
        $linked = $transactions->whereNotNull('payment_id')->groupBy('payment_id');
        foreach ($this->t->query('payments')->where('created_at', '<', $until)->get() as $p) {
            $amount = $p->direction === 'out' ? -$p->amount : $p->amount;
            $original = ($linked[$p->id] ?? collect())->first(fn ($t) => ! str_starts_with($t->description, 'Estorno'));
            $reversal = ($linked[$p->id] ?? collect())->first(fn ($t) => str_starts_with($t->description, 'Estorno'));
            $entries->push(['date' => $p->created_at, 'source' => $original ? 'Caixa #'.$original->cash_register_id : 'Recebimento #'.$p->id, 'description' => $p->sale_id ? 'Venda #'.$p->sale_id : ($p->customer_id ? 'Conta do cliente #'.$p->customer_id : 'Pagamento de conta'), 'method' => $p->method, 'amount' => $amount, 'key' => 'payment-'.$p->id]);
            if ($p->reversed_at && $p->reversed_at < $until) {
                $entries->push(['date' => $p->reversed_at, 'source' => $reversal ? 'Caixa #'.$reversal->cash_register_id : 'Estorno #'.$p->id, 'description' => 'Devolução do pagamento #'.$p->id, 'method' => $p->method, 'amount' => -$amount, 'key' => 'reversal-'.$p->id]);
            }
        }
        foreach ($this->t->query('flow_entries')->where('occurred_at', '<', $until)->get() as $entry) {
            $entries->push(['date' => $entry->occurred_at, 'source' => 'Fluxo #'.$entry->id, 'description' => $entry->category.' · '.$entry->description, 'method' => $entry->method, 'amount' => (int) $entry->amount, 'key' => 'flow-'.$entry->id]);
        }

        return $entries->sortBy(fn ($e) => $e['date'].'-'.$e['key'])->values();
    }

    public function month(string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month, $this->t->company->timezone)->startOfMonth();
        $end = $start->copy()->addMonth()->utc()->toDateTimeString();
        $startUtc = $start->utc()->toDateTimeString();
        $all = $this->entries($end);
        $opening = $all->where('date', '<', $startUtc)->sum('amount');
        $balance = $opening;
        $entries = $all->where('date', '>=', $startUtc)->map(function ($e) use (&$balance) {
            $balance += $e['amount'];

            return $e + ['balance' => $balance];
        });

        return ['month' => $month, 'opening' => $opening, 'closing' => $balance, 'incoming' => $entries->where('amount', '>', 0)->sum('amount'), 'outgoing' => -$entries->where('amount', '<', 0)->sum('amount'), 'entries' => $entries,
            'sources' => $entries->groupBy('source')->map(fn ($group) => $group->groupBy('method')->map(fn ($method) => ['in' => $method->where('amount', '>', 0)->sum('amount'), 'out' => -$method->where('amount', '<', 0)->sum('amount')]))];
    }
}
