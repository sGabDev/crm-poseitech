<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Commerce
{
    public function __construct(public Tenant $t) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }

    public function sell(array $d): int
    {
        $this->t->authorize('sales', true);

        return DB::transaction(function () use ($d) {
            $this->t->lock();
            if ($existing = $this->t->query('sales')->where('request_key', $d['request_key'])->first()) {
                return $existing->id;
            }
            if (! $this->t->query('cash_registers')->whereNull('closed_at')->exists()) {
                $this->fail('Abra o caixa antes de registrar qualquer venda, inclusive Pix, cartão, fiado ou saldo da conta.');
            }
            $catalogOrder = null;
            if (! empty($d['catalog_order_id'])) {
                $this->t->authorize('orders', true);
                $catalogOrder = $this->t->find('catalog_orders', $d['catalog_order_id']);
                if ($catalogOrder->status !== 'pending') {
                    $this->fail('Este pedido online já foi atendido ou cancelado.');
                }
                $d['order'] = true;
            }
            $customer = ! empty($d['customer_id']) ? $this->t->find('customers', $d['customer_id']) : null;
            if ($customer?->anonymized_at) {
                $this->fail('Cliente anonimizado.');
            }
            $items = [];
            $subtotal = 0;
            $cost = 0;
            foreach ($d['items'] as $line) {
                if (empty($line['product_id'])) {
                    if (! trim($line['name'] ?? '') || Tenant::cents($line['price'] ?? 0) <= 0) {
                        $this->fail('Informe nome e valor do item avulso.');
                    }
                    $p = (object) ['id' => null, 'name' => trim($line['name']), 'price' => Tenant::cents($line['price']), 'cost' => 0, 'type' => 'service', 'active' => true, 'addons' => '[]'];
                } else {
                    $p = $this->t->find('products', $line['product_id']);
                }
                if (! $p->active) {
                    $this->fail('Produto inativo.');
                }
                $qty = (int) $line['quantity'];
                $addons = json_decode($p->addons ?? '[]', true);
                $chosen = array_values(array_unique($line['addons'] ?? []));
                sort($chosen);
                $addonPrice = 0;
                $names = [];
                foreach ($chosen as $key) {
                    if (! isset($addons[$key])) {
                        $this->fail('Adicional inválido.');
                    }$addonPrice += $addons[$key]['price'];
                    $names[] = $addons[$key]['name'];
                }
                $p->price += $addonPrice;
                $itemKey = ($p->id ?? 'custom-'.count($items)).':'.implode(',', $chosen);
                $items[$itemKey] = ['p' => $p, 'addons' => implode(', ', $names), 'quantity' => ($items[$itemKey]['quantity'] ?? 0) + $qty];
            }
            foreach ($items as $line) {
                $subtotal += $line['p']->price * $line['quantity'];
                $cost += $line['p']->cost * $line['quantity'];
            }
            $discount = self::adjustment($d['discount'] ?? 0, $subtotal);
            $extra = self::adjustment($d['extra'] ?? 0, $subtotal);
            $coupon = null;
            if (! empty($d['coupon'])) {
                $this->t->authorize('loyalty', true);
                $coupon = $this->t->query('coupons')->whereNull('deleted_at')->where('code', Str::upper($d['coupon']))->first();
                if (! $coupon || ! $coupon->active || $coupon->expires_at < now()->toDateString() || $coupon->uses >= $coupon->max_uses || ($coupon->customer_id && $coupon->customer_id !== $customer?->id)) {
                    $this->fail('Cupom inválido para esta compra.');
                }
                $grant = $customer ? $this->t->query('coupon_grants')->where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->whereNull('used_sale_id')->whereNull('revoked_at')->first() : null;
                if ($coupon->minimum > 0 && ! $grant) {
                    $this->fail('Este cliente ainda não ganhou este cupom ou já utilizou o benefício.');
                }
                $discount += min(max(0, $subtotal - $discount), $coupon->type === 'percent' ? (int) round($subtotal * $coupon->value / 10000) : $coupon->value);
            }
            $fee = 0;
            if (! empty($d['order'])) {
                $this->t->authorize('orders', true);
                if (! empty($d['delivery'])) {
                    $this->t->authorize('delivery', true);
                    $fee = Tenant::cents($d['fee'] ?? 0);
                    if (empty($d['address'])) {
                        $this->fail('Informe o endereço da entrega.');
                    }
                }
            }
            $extra += $fee;
            if ($discount > $subtotal) {
                $this->fail('O desconto não pode superar o subtotal.');
            }
            $total = $subtotal - $discount + $extra;
            if (($total <= 0 && ! $coupon) || $total < 0 || $total > 99999999999) {
                $this->fail('Total da venda inválido.');
            }
            $submitted = collect($d['payments'] ?? []);
            $wallet = 0;
            if ($customer && ! empty($d['use_balance'])) {
                $this->t->authorize('credit', true);
                $wallet = min($total, max(0, (int) $this->t->query('wallet_entries')->where('customer_id', $customer->id)->sum('amount')));
            }
            if (count($submitted) === 1 && ! empty($d['auto_payment'])) {
                $submitted = $submitted->map(fn ($p) => array_merge($p, ['amount' => number_format(($total - $wallet) / 100, 2, '.', '')]));
            }
            $payments = $submitted->filter(fn ($p) => $p['method'] !== 'fiado' && Tenant::cents($p['amount'] ?? 0) > 0);
            $paid = $wallet + $payments->sum(fn ($p) => Tenant::cents($p['amount']));
            if ($paid > $total) {
                $this->fail('Os pagamentos excedem o total da venda. Informe o valor recebido sem o troco.');
            }
            if ($paid < $total) {
                $this->t->authorize('credit', true);
                if (! $customer) {
                    $this->fail('Selecione um cliente para registrar fiado.');
                }
            }
            $methods = $payments->pluck('method')->unique()->values()->all();
            if ($wallet) {
                $methods[] = 'wallet';
            }
            if ($paid < $total) {
                $methods[] = 'fiado';
            }
            $saleId = $this->t->insert('sales', ['customer_id' => $customer?->id, 'user_id' => auth()->id(), 'coupon_id' => $coupon?->id,
                'subtotal' => $subtotal, 'discount' => $discount, 'extra' => $extra, 'total' => $total, 'cost' => $cost, 'paid' => $paid, 'wallet_used' => $wallet,
                'notes' => $d['notes'] ?? null, 'receipt_hash' => hash('sha256', Str::random(64)), 'request_key' => $d['request_key'], 'payment_methods' => json_encode($methods), 'fiado_amount' => $total - $paid]);
            if ($wallet) {
                $this->t->insert('wallet_entries', ['customer_id' => $customer->id, 'user_id' => auth()->id(), 'sale_id' => $saleId, 'amount' => -$wallet, 'description' => 'Saldo utilizado na venda #'.$saleId]);
            }
            foreach ($items as $line) {
                $p = $line['p'];
                $qty = $line['quantity'];
                $deduct = $this->t->company->enabled('stock') && $p->type === 'product';
                if ($deduct) {
                    $this->stock($p->id, -$qty, 'sale', 'Venda #'.$saleId, $saleId, ! empty($d['allow_negative_stock']));
                }
                $this->t->insert('sale_items', ['sale_id' => $saleId, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => $qty,
                    'price' => $p->price, 'cost' => $p->cost, 'total' => $p->price * $qty, 'stock_deducted' => $deduct, 'addons' => $line['addons'] ?: null]);
            }
            foreach ($payments as $p) {
                $this->payment($saleId, null, $customer?->id, Tenant::cents($p['amount']), $p['method'], 'in');
            }
            if ($paid < $total) {
                $this->t->insert('accounts', ['type' => 'receivable', 'description' => 'Fiado venda #'.$saleId,
                    'customer_id' => $customer->id, 'sale_id' => $saleId, 'amount' => $total - $paid,
                    'due_date' => $this->nextDueDate($customer->due_day), 'origin' => 'credit']);
            }
            if ($coupon) {
                $this->t->query('coupons')->where('id', $coupon->id)->increment('uses');
                if (isset($grant) && $grant) {
                    $this->t->update('coupon_grants', $grant->id, ['used_sale_id' => $saleId]);
                }
            }
            if ($customer && $this->t->company->enabled('loyalty')) {
                app(CouponRewards::class)->earn($customer, $saleId, $subtotal);
                $settings = $this->t->company->settings ?? [];
                if (($settings['loyalty_mode'] ?? 'points') === 'cashback') {
                    $cashback = (int) floor($total * ($settings['loyalty_rate'] ?? 1) / 100);
                    if ($cashback) {
                        $this->t->insert('customer_credits', ['customer_id' => $customer->id, 'sale_id' => $saleId, 'user_id' => auth()->id(), 'amount' => $cashback, 'description' => 'Cashback venda #'.$saleId]);
                    }
                } else {
                    $points = ($settings['loyalty_mode'] ?? 'points') === 'purchases' ? 1 : (int) floor($total / 100 * ($settings['loyalty_rate'] ?? 1));
                    $this->t->insert('loyalty_transactions', ['customer_id' => $customer->id, 'sale_id' => $saleId, 'points' => $points, 'description' => 'Venda #'.$saleId]);
                }
            }
            if (! empty($d['order'])) {
                $this->t->insert('orders', ['sale_id' => $saleId, 'delivery' => ! empty($d['delivery']), 'address' => ($d['address'] ?? null) ?: ($customer->address ?? $catalogOrder->address ?? null), 'fee' => $fee, 'region' => $d['region'] ?? null]);
            }
            if ($catalogOrder) {
                $this->t->update('catalog_orders', $catalogOrder->id, ['status' => 'converted', 'sale_id' => $saleId]);
            }
            $this->t->audit('sale.created', 'sales', $saleId, null, ['total' => $total, 'paid' => $paid]);
            if ($customer && filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                $this->t->insert('email_logs', ['sale_id' => $saleId, 'customer_id' => $customer->id, 'recipient' => $customer->email, 'subject' => 'Comprovante da compra #'.$saleId.' · '.$this->t->company->name, 'body' => 'Olá, '.$customer->name.'. Sua compra #'.$saleId.' foi registrada. Total: '.Tenant::money($total).'.']);
            }

            return $saleId;
        }, 3);
    }

    public function payment(?int $sale, ?int $account, ?int $customer, int $amount, string $method, string $direction): int
    {
        if (! array_key_exists($method, config('poseitech.methods')) || $amount <= 0) {
            $this->fail('Pagamento inválido.');
        }
        $register = $this->t->query('cash_registers')->whereNull('closed_at')->first();
        if ($method === 'cash' && $this->t->company->enabled('cash') && ! $register) {
            $this->fail('Abra o caixa antes de movimentar dinheiro.');
        }
        if ($direction === 'out' && $method === 'cash' && $register && $this->expected($register) < $amount) {
            $this->fail('Saldo insuficiente no caixa.');
        }
        $id = $this->t->insert('payments', ['sale_id' => $sale, 'account_id' => $account, 'customer_id' => $customer, 'user_id' => auth()->id(), 'amount' => $amount, 'method' => $method, 'direction' => $direction]);
        if ($register) {
            $this->t->insert('cash_transactions', ['cash_register_id' => $register->id, 'payment_id' => $id, 'user_id' => auth()->id(),
                'description' => $sale ? 'Venda #'.$sale : ($account ? 'Conta #'.$account : 'Depósito cliente #'.$customer), 'method' => $method, 'amount' => $direction === 'in' ? $amount : -$amount]);
        }

        return $id;
    }

    public static function adjustment($value, int $subtotal): int
    {
        $value = str_replace(',', '.', trim((string) ($value ?? '0')));
        if (str_ends_with($value, '%')) {
            $percent = Tenant::cents(trim(substr($value, 0, -1)));
            if ($percent > 10000) {
                throw ValidationException::withMessages(['discount' => 'O percentual deve estar entre 0% e 100%.']);
            }

            return (int) round($subtotal * $percent / 10000);
        }

        return Tenant::cents($value);
    }

    public function settle(int $id, array $d): void
    {
        DB::transaction(function () use ($id, $d) {
            $this->t->lock();
            $a = $this->t->find('accounts', $id);
            $this->t->authorize($a->origin === 'credit' ? 'credit' : 'finance', true);
            if (! empty($d['request_key']) && $this->t->query('payments')->where('request_key', $d['request_key'])->exists()) {
                return;
            }
            $amount = Tenant::cents($d['amount']);
            if ($a->status !== 'pending' || $amount <= 0 || $amount > $a->amount - $a->paid) {
                $this->fail('Valor superior ao saldo ou conta já encerrada.');
            }
            $paymentId = $this->payment($a->sale_id, $a->id, $a->customer_id, $amount, $d['method'], $a->type === 'receivable' ? 'in' : 'out');
            if (! empty($d['debt_receipt_id'])) {
                $this->t->update('payments', $paymentId, ['debt_receipt_id' => $d['debt_receipt_id']]);
            }
            if (! empty($d['request_key'])) {
                $this->t->update('payments', $paymentId, ['request_key' => $d['request_key']]);
            }
            $paid = $a->paid + $amount;
            $this->t->update('accounts', $id, ['paid' => $paid, 'status' => $paid === $a->amount ? 'paid' : 'pending']);
            if ($a->sale_id) {
                $this->t->query('sales')->where('id', $a->sale_id)->increment('paid', $amount);
            }
            if ($paid === $a->amount && $a->recurrence !== 'none') {
                $due = Carbon::parse($a->due_date);
                $due = $a->recurrence === 'weekly' ? $due->addWeek() : $due->addMonthNoOverflow();
                $this->t->insert('accounts', ['type' => $a->type, 'description' => $a->description, 'category' => $a->category, 'supplier_id' => $a->supplier_id,
                    'customer_id' => $a->customer_id, 'amount' => $a->amount, 'due_date' => $due->toDateString(), 'recurrence' => $a->recurrence]);
            }
            $this->t->audit('account.payment', 'accounts', $id, ['paid' => $a->paid], ['paid' => $paid]);
        }, 3);
    }

    public function stock(int $id, int $delta, string $type, ?string $notes, ?int $sale = null, bool $allowNegative = false): void
    {
        $p = $this->t->find('products', $id);
        if ($p->type !== 'product' || ($p->stock + $delta < 0 && $delta < 0 && ! ($allowNegative && $type === 'sale' && $sale))) {
            $this->fail('Estoque insuficiente ou item é um serviço: '.$p->name);
        }
        if ($allowNegative && $p->stock + $delta < 0) {
            $this->t->audit('stock.override', 'products', $id, ['stock' => $p->stock], ['quantity' => $delta, 'sale_id' => $sale]);
        }
        $this->t->update('products', $id, ['stock' => $p->stock + $delta]);
        $this->t->insert('stock_movements', ['product_id' => $id, 'sale_id' => $sale, 'user_id' => auth()->id(), 'type' => $type, 'quantity' => $delta, 'balance' => $p->stock + $delta, 'notes' => $notes]);
    }

    public function nextDueDate(int $day): string
    {
        $today = now($this->t->company->timezone)->startOfDay();
        $due = $today->copy()->day(min($day, $today->daysInMonth));
        if ($due->lt($today)) {
            $due = $today->copy()->startOfMonth()->addMonth();
            $due->day(min($day, $due->daysInMonth));
        }

        return $due->toDateString();
    }

    public function settleCustomer(int $id, array $d): void
    {
        $this->t->authorize('credit', true);
        DB::transaction(function () use ($id, $d) {
            $this->t->lock();
            $this->t->find('customers', $id);
            $previous = $this->t->query('debt_receipts')->where('request_key', $d['request_key'])->first();
            if ($previous) {
                if ($previous->customer_id !== $id) {
                    $this->fail('Identificador de pagamento já utilizado.');
                }

                return;
            }
            $accounts = $this->t->query('accounts')->where('customer_id', $id)->where('origin', 'credit')->where('status', 'pending')->orderBy('due_date')->orderBy('id')->get();
            $amount = Tenant::cents($d['amount']);
            $balance = $accounts->sum(fn ($a) => $a->amount - $a->paid);
            if ($amount <= 0 || $amount > $balance) {
                $this->fail('Informe um pagamento entre um centavo e o saldo devedor do cliente.');
            }
            $receipt = $this->t->insert('debt_receipts', ['customer_id' => $id, 'amount' => $amount, 'method' => $d['method'], 'request_key' => $d['request_key']]);
            $remaining = $amount;
            foreach ($accounts as $a) {
                if (! $remaining) {
                    break;
                }$part = min($remaining, $a->amount - $a->paid);
                $this->settle($a->id, ['amount' => number_format($part / 100, 2, '.', ''), 'method' => $d['method'], 'debt_receipt_id' => $receipt]);
                $remaining -= $part;
            }
            $this->t->audit('customer.debt_payment', 'customers', $id, ['balance' => $balance], ['paid' => $amount, 'balance' => $balance - $amount]);
        }, 3);
    }

    public static function paymentLabel(object $sale): string
    {
        $methods = json_decode($sale->payment_methods ?? '[]', true) ?: [];
        $labels = ['wallet' => 'Saldo da conta', 'cash' => 'Dinheiro', 'card' => 'Cartão', 'pix' => 'Pix', 'credit' => 'Cartão', 'debit' => 'Cartão', 'boleto' => 'Boleto', 'other' => 'Outros'];
        $paid = array_values(array_unique(array_map(fn ($m) => $labels[$m] ?? $m, array_filter($methods, fn ($m) => $m !== 'fiado'))));
        if (($sale->fiado_amount ?? 0) > 0) {
            return $paid ? 'Pago + Fiado ('.implode(', ', $paid).')' : 'Fiado';
        }

        return implode(' + ', $paid) ?: 'Pago';
    }

    public function expected(object $register): int
    {
        return $register->opening + $this->t->query('cash_transactions')->where('cash_register_id', $register->id)->where('method', 'cash')->sum('amount');
    }

    public function cancel(int $id, string $reason): void
    {
        $this->t->authorize('sales', true);
        Gate::authorize('manage-company');
        DB::transaction(function () use ($id, $reason) {
            $this->t->lock();
            $sale = $this->t->find('sales', $id);
            if ($sale->status === 'cancelled') {
                $this->fail('Venda já cancelada.');
            }
            if ($sale->wallet_used) {
                $this->t->insert('wallet_entries', ['customer_id' => $sale->customer_id, 'user_id' => auth()->id(), 'sale_id' => $id, 'amount' => $sale->wallet_used, 'description' => 'Saldo devolvido pelo cancelamento #'.$id]);
            }
            foreach ($this->t->query('payments')->where('sale_id', $id)->whereNull('reversed_at')->get() as $p) {
                $register = $this->t->query('cash_registers')->whereNull('closed_at')->first();
                if ($p->method === 'cash' && $this->t->company->enabled('cash') && ! $register) {
                    $this->fail('Abra o caixa para registrar a devolução.');
                }
                if ($p->method === 'cash' && $register && $this->expected($register) < $p->amount) {
                    $this->fail('Saldo insuficiente para devolver o pagamento.');
                }
                if ($register) {
                    $this->t->insert('cash_transactions', ['cash_register_id' => $register->id, 'payment_id' => $p->id, 'user_id' => auth()->id(), 'description' => 'Estorno venda #'.$id, 'method' => $p->method, 'amount' => -$p->amount]);
                }
                $this->t->update('payments', $p->id, ['reversed_at' => now()]);
            }
            $this->t->query('coupon_grants')->where('used_sale_id', $id)->update(['used_sale_id' => null, 'updated_at' => now()]);
            $this->t->query('coupon_grants')->where('earned_sale_id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            foreach ($this->t->query('sale_items')->where('sale_id', $id)->where('stock_deducted', true)->get() as $item) {
                $this->stock($item->product_id, $item->quantity, 'return', 'Cancelamento #'.$id, $id);
            }
            $this->t->query('accounts')->where('sale_id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->t->query('orders')->where('sale_id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $points = $this->t->query('loyalty_transactions')->where('sale_id', $id)->sum('points');
            if ($points) {
                $this->t->insert('loyalty_transactions', ['customer_id' => $sale->customer_id, 'sale_id' => $id, 'points' => -$points, 'description' => 'Cancelamento #'.$id]);
            }
            $credit = $this->t->query('customer_credits')->where('sale_id', $id)->sum('amount');
            if ($credit) {
                $this->t->insert('customer_credits', ['customer_id' => $sale->customer_id, 'sale_id' => $id, 'user_id' => auth()->id(), 'amount' => -$credit, 'description' => 'Estorno de cashback #'.$id]);
            }
            if ($sale->coupon_id) {
                $this->t->query('coupons')->where('id', $sale->coupon_id)->where('uses', '>', 0)->decrement('uses');
            }
            $this->t->update('sales', $id, ['status' => 'cancelled', 'paid' => 0]);
            $this->t->audit('sale.cancelled', 'sales', $id, $sale, ['reason' => $reason]);
        }, 3);
    }
}
