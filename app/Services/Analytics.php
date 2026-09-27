<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Analytics
{
    public function __construct(public Tenant $t) {}

    public function period(Request $r): array
    {
        $r->validate(['period' => 'nullable|in:today,yesterday,7,30,month,previous,year,custom', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $now = now($this->t->company->timezone);
        [$from, $to] = match ($r->input('period', 'month')) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'previous' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'custom' => [Carbon::parse($r->input('from') ?: $now->toDateString(), $now->timezone)->startOfDay(), Carbon::parse($r->input('to') ?: $now->toDateString(), $now->timezone)->endOfDay()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
        if ($from->diffInDays($to) > 730) {
            throw ValidationException::withMessages(['period' => 'Selecione um período de até dois anos.']);
        }

        return [$from->utc(), $to->utc()];
    }

    public function sales(array $period)
    {
        return $this->t->query('sales')->where('status', 'completed')->whereBetween('sales.created_at', $period);
    }

    public function dashboard(array $period): array
    {
        [$from,$to] = $period;
        $sales = $this->sales($period);
        $totals = (clone $sales)->selectRaw('COUNT(*) as count, COALESCE(SUM(total),0) as revenue, COALESCE(SUM(subtotal),0) as gross, COALESCE(SUM(discount),0) as discount, COALESCE(SUM(extra),0) as extra, COALESCE(SUM(cost),0) as cost')->first();
        $days = max(1, (int) ceil($from->diffInSeconds($to) / 86400));
        $previous = $this->sales([$from->copy()->subDays($days), $from->copy()->subSecond()])->sum('total');
        $payments = $this->t->query('payments')->whereNull('reversed_at')->whereBetween('created_at', $period);
        $incoming = (clone $payments)->where('direction', 'in')->sum('amount');
        $outgoing = (clone $payments)->where('direction', 'out')->sum('amount');
        $pending = $this->t->query('accounts')->where('status', 'pending');
        $receivable = (clone $pending)->where('type', 'receivable')->selectRaw('COALESCE(SUM(amount-paid),0) as balance')->value('balance');
        $payable = (clone $pending)->where('type', 'payable')->selectRaw('COALESCE(SUM(amount-paid),0) as balance')->value('balance');
        $overdue = (clone $pending)->where('due_date', '<', now()->toDateString())->count();
        $dayTotals = [];
        $hourTotals = [];
        foreach ((clone $sales)->select('created_at', 'total')->orderBy('created_at')->cursor() as $s) {
            $date = Carbon::parse($s->created_at)->timezone($this->t->company->timezone);
            $day = $date->toDateString();
            $hour = $date->format('H');
            $dayTotals[$day] = ($dayTotals[$day] ?? 0) + $s->total;
            $hourTotals[$hour] = ($hourTotals[$hour] ?? 0) + $s->total;
        }
        $daily = collect($dayTotals)->map(fn ($total, $day) => (object) compact('day', 'total'))->values();
        ksort($hourTotals);
        $top = $this->t->query('sale_items')->whereIn('sale_id', (clone $sales)->select('sales.id'))->selectRaw('name, SUM(quantity) as quantity, SUM(total) as total')->groupBy('name')->orderByDesc('total')->limit(8)->get();
        $methods = (clone $payments)->where('direction', 'in')->selectRaw('method, SUM(amount) as total')->groupBy('method')->get();
        $newCustomers = $this->t->query('customers')->whereBetween('created_at', $period)->count();
        $recurring = $this->t->query('sales')->where('status', 'completed')->whereNotNull('customer_id')->groupBy('customer_id')->havingRaw('COUNT(*) > 1')->select('customer_id')->get()->count();
        $inactive = $this->customers('inactive')->count();
        $register = $this->t->query('cash_registers')->whereNull('closed_at')->first();
        $cash = $register ? app(Commerce::class)->expected($register) : 0;
        $recent = $this->t->query('sales')->orderByDesc('id')->limit(6)->get();
        $ticket = $totals->count ? (int) round($totals->revenue / $totals->count) : 0;
        $goals = $this->t->query('goals')->where('ends_at', '>=', now()->toDateString())->get()->map(function ($g) {
            $q = $this->sales([Carbon::parse($g->starts_at)->startOfDay(), Carbon::parse($g->ends_at)->endOfDay()]);
            $g->actual = match ($g->metric) {
                'revenue' => (clone $q)->sum('total'), 'sales' => (clone $q)->count() * 100, 'ticket' => (int) ((clone $q)->avg('total') ?? 0), 'customers' => $this->t->query('customers')->whereBetween('created_at', [$g->starts_at.' 00:00:00', $g->ends_at.' 23:59:59'])->count() * 100
            };

            return $g;
        });
        $todayRevenue = $this->sales([now($this->t->company->timezone)->startOfDay()->utc(), now()])->sum('total');
        $monthRevenue = $this->sales([now($this->t->company->timezone)->startOfMonth()->utc(), now()])->sum('total');

        return compact('totals', 'previous', 'incoming', 'outgoing', 'receivable', 'payable', 'overdue', 'daily', 'top', 'methods', 'newCustomers', 'recurring', 'inactive', 'cash', 'recent', 'ticket', 'goals', 'days', 'hourTotals', 'todayRevenue', 'monthRevenue');
    }

    public function customers(string $segment = 'all', int $days = 30, ?int $product = null, int $minimum = 0)
    {
        $q = $this->t->query('customers')->whereNull('anonymized_at');
        $sales = $this->t->query('sales')->where('status', 'completed')->whereNotNull('customer_id');
        if ($segment === 'inactive') {
            $q->where('customers.created_at', '<', now()->subDays($days))->whereNotIn('customers.id', (clone $sales)->where('created_at', '>=', now()->subDays($days))->select('customer_id'));
        }
        if ($segment === 'new') {
            $q->where('customers.created_at', '>=', now()->subDays($days));
        }
        if ($segment === 'vip') {
            $q->whereIn('customers.id', (clone $sales)->select('customer_id')->groupBy('customer_id')->havingRaw('SUM(total) >= ?', [max(100000, $minimum)]));
        }
        if ($segment === 'recurring') {
            $q->whereIn('customers.id', (clone $sales)->select('customer_id')->groupBy('customer_id')->havingRaw('COUNT(*) >= 2'));
        }
        if ($segment === 'ticket') {
            $q->whereIn('customers.id', (clone $sales)->select('customer_id')->groupBy('customer_id')->havingRaw('AVG(total) >= ?', [$minimum]));
        }
        if ($segment === 'pending') {
            $q->whereIn('customers.id', $this->t->query('accounts')->where('status', 'pending')->whereNotNull('customer_id')->select('customer_id'));
        }
        if ($segment === 'product') {
            $q->whereIn('customers.id', (clone $sales)->whereIn('id', $this->t->query('sale_items')->where('product_id', $product)->select('sale_id'))->select('customer_id'));
        }
        if ($segment === 'birthday') {
            $dates = collect($days === 1 ? [1] : range(0, 7))->map(fn ($i) => now($this->t->company->timezone)->addDays($i)->format('m-d'))->all();
            $q->where(function ($query) use ($dates) {
                foreach ($dates as $date) {
                    $query->orWhere('birthday', 'like', '%-'.$date);
                }
            });
        }

        return $q;
    }

    public function opportunities(): array
    {
        $inactive = $this->customers('inactive')->count();
        $debtors = $this->customers('pending')->count();
        $low = $this->t->company->enabled('stock') ? $this->t->query('products')->where('active', true)->where('type', 'product')->whereColumn('stock', '<=', 'min_stock')->count() : 0;
        $month = $this->sales([now()->startOfMonth(), now()]);
        $prev = $this->sales([now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()]);
        $ticket = (clone $month)->avg('total') ?? 0;
        $previous = (clone $prev)->avg('total') ?? 0;
        $cards = [
            ['title' => "$inactive clientes sem comprar há 30 dias", 'body' => 'Retome o contato com uma oferta relevante.', 'url' => '/records/customers?segment=inactive', 'action' => 'Ver clientes'],
            ['title' => "$debtors clientes com valores pendentes", 'body' => 'Consulte o histórico e combine o recebimento.', 'url' => '/credit', 'action' => 'Acompanhar fiados'],
        ];
        if ($low) {
            $cards[] = ['title' => "$low produtos com estoque baixo", 'body' => 'Planeje a reposição antes de perder uma venda.', 'url' => '/stock', 'action' => 'Ver estoque'];
        }
        if ($previous > 0) {
            $cards[] = ['title' => 'Ticket médio '.($ticket >= $previous ? 'subiu ' : 'caiu ').round(abs($ticket / $previous - 1) * 100).'%', 'body' => 'Mês atual comparado ao mês anterior.', 'url' => '/reports', 'action' => 'Analisar vendas'];
        }
        $history = $this->t->query('sales')->where('status', 'completed')->where('created_at', '>=', now()->subDays(90))->select('created_at', 'total')->get();
        if ($history->isNotEmpty()) {
            $hour = $history->groupBy(fn ($s) => Carbon::parse($s->created_at)->timezone($this->t->company->timezone)->format('H'))->map->count()->sortDesc()->keys()->first();
            $week = $history->groupBy(fn ($s) => Carbon::parse($s->created_at)->timezone($this->t->company->timezone)->dayOfWeek)->map->count()->sortDesc()->keys()->first();
            $cards[] = ['title' => "Seu horário mais movimentado: {$hour}h", 'body' => 'Nos últimos 90 dias. Reforce a equipe nesse horário.', 'url' => '/reports', 'action' => 'Ver relatório'];
            $cards[] = ['title' => 'Você vende mais: '.['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'][$week], 'body' => 'Concentração de vendas nos últimos 90 dias.', 'url' => '/campaigns', 'action' => 'Planejar campanha'];
        }
        $pairs = $this->t->query('sale_items')->join('sale_items as partner', function ($join) {
            $join->on('sale_items.sale_id', '=', 'partner.sale_id')->on('sale_items.company_id', '=', 'partner.company_id')->on('sale_items.product_id', '<', 'partner.product_id');
        })
            ->whereIn('sale_items.sale_id', $this->t->query('sales')->where('status', 'completed')->where('created_at', '>=', now()->subDays(90))->select('id'))
            ->selectRaw('sale_items.name as first_name, partner.name as second_name, COUNT(DISTINCT sale_items.sale_id) as count')->groupBy('sale_items.name', 'partner.name')->havingRaw('COUNT(DISTINCT sale_items.sale_id)>=2')->orderByDesc('count')->first();
        if ($pairs) {
            $cards[] = ['title' => $pairs->first_name.' + '.$pairs->second_name, 'body' => 'Apareceram juntos em '.$pairs->count.' compras nos últimos 90 dias. Experimente uma oferta combinada.', 'url' => '/campaigns', 'action' => 'Criar campanha'];
        }
        $recurringIds = $this->t->query('sales')->where('status', 'completed')->whereNotNull('customer_id')->select('customer_id')->groupBy('customer_id')->havingRaw('COUNT(*)>=2');
        $average = $this->t->query('sales')->where('status', 'completed')->whereIn('customer_id', $recurringIds)->avg('total');
        if ($average) {
            $cards[] = ['title' => 'Clientes recorrentes gastam '.Tenant::money($average), 'body' => 'Valor médio por compra entre clientes com pelo menos duas compras.', 'url' => '/records/customers?segment=recurring', 'action' => 'Conhecer clientes'];
        }
        $current = $this->t->query('sale_items')->whereIn('sale_id', (clone $month)->select('id'))->selectRaw('product_id, name, SUM(quantity) as quantity')->groupBy('product_id', 'name')->get();
        $old = $this->t->query('sale_items')->whereIn('sale_id', (clone $prev)->select('id'))->selectRaw('product_id, SUM(quantity) as quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $growth = $current->filter(fn ($p) => ($old[$p->product_id] ?? 0) > 0 && $p->quantity > $old[$p->product_id])->sortByDesc(fn ($p) => $p->quantity / $old[$p->product_id])->first();
        if ($growth) {
            $cards[] = ['title' => $growth->name.' cresceu '.round(($growth->quantity / $old[$growth->product_id] - 1) * 100).'%', 'body' => 'Quantidade vendida no mês atual comparada ao mês anterior completo.', 'url' => '/reports', 'action' => 'Ver produtos'];
        }

        return $cards;
    }

    public function forecast(): array
    {
        $zone = $this->t->company->timezone;
        $end = now($zone)->startOfDay();
        $start = $end->copy()->subDays(56);
        $daily = [];
        foreach ($this->t->query('sales')->where('status', 'completed')->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()->subSecond()])->select('created_at', 'total')->cursor() as $s) {
            $key = Carbon::parse($s->created_at)->timezone($zone)->toDateString();
            $daily[$key] = ($daily[$key] ?? 0) + $s->total;
        }
        $weekdays = array_fill(0, 7, 0);
        $counts = array_fill(0, 7, 0);
        $recent = 0;
        $previous = 0;
        for ($i = 0; $i < 56; $i++) {
            $date = $start->copy()->addDays($i);
            $amount = $daily[$date->toDateString()] ?? 0;
            $weekdays[$date->dayOfWeek] += $amount;
            $counts[$date->dayOfWeek]++;
            if ($i >= 28) {
                $recent += $amount;
            } else {
                $previous += $amount;
            }
        }
        $trend = $previous > 0 ? max(.5, min(1.5, $recent / $previous)) : 1;
        $estimate = function (Carbon $from, Carbon $to) use ($weekdays, $counts, $trend) {
            $sum = 0;
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $sum += $weekdays[$d->dayOfWeek] / max(1, $counts[$d->dayOfWeek]) * $trend;
            }

            return $sum;
        };
        $next = $end->copy()->addMonthNoOverflow();

        return ['daily' => $recent / 28, 'weekly' => $recent / 4, 'trend' => ($trend - 1) * 100, 'tomorrow' => $estimate($end->copy()->addDay(), $end->copy()->addDay()),
            'seven' => $estimate($end->copy()->addDay(), $end->copy()->addDays(7)),
            'month' => $this->sales([$end->copy()->startOfMonth()->utc(), now()])->sum('total') + $estimate($end->copy()->addDay(), $end->copy()->endOfMonth()),
            'next_month' => $estimate($next->copy()->startOfMonth(), $next->copy()->endOfMonth()), 'sample' => count($daily)];
    }
}
