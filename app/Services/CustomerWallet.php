<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerWallet
{
    public function __construct(private Tenant $t, private Commerce $commerce) {}

    public function deposit(array $d): int
    {
        $this->t->authorize('credit', true);

        return DB::transaction(function () use ($d) {
            $this->t->lock();
            $customer = $this->t->find('customers', $d['customer_id']);
            abort_if($customer->anonymized_at, 422, 'Cliente anonimizado.');
            if ($previous = $this->t->query('customer_deposits')->where('request_key', $d['request_key'])->first()) {
                abort_unless($previous->customer_id === $customer->id, 422);

                return $previous->id;
            }
            $amount = Tenant::cents($d['amount']);
            $debt = $this->t->query('accounts')->where('customer_id', $customer->id)->where('origin', 'credit')->where('status', 'pending')->selectRaw('COALESCE(SUM(amount-paid),0) as total')->value('total');
            $settled = min($amount, $debt);
            $id = $this->t->insert('customer_deposits', ['customer_id' => $customer->id, 'user_id' => auth()->id(), 'amount' => $amount, 'debt_paid' => $settled, 'method' => $d['method'], 'request_key' => $d['request_key']]);
            if ($settled) {
                $this->commerce->settleCustomer($customer->id, ['amount' => number_format($settled / 100, 2, '.', ''), 'method' => $d['method'], 'request_key' => (string) Str::uuid()]);
            }
            if ($amount > $settled) {
                $this->commerce->payment(null, null, $customer->id, $amount - $settled, $d['method'], 'in');
                $this->t->insert('wallet_entries', ['customer_id' => $customer->id, 'user_id' => auth()->id(), 'customer_deposit_id' => $id, 'amount' => $amount - $settled, 'description' => 'Depósito na conta #'.$id]);
            }
            $this->t->audit('customer.deposit', 'customer_deposits', $id, null, ['amount' => $amount, 'debt_paid' => $settled]);

            return $id;
        }, 3);
    }
}
