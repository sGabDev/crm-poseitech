<?php

namespace App\Services;

use Carbon\Carbon;

class CouponRewards
{
    public function __construct(private Tenant $t) {}

    public function earn(object $customer, int $sale, int $subtotal): void
    {
        $coupons = $this->t->query('coupons')->whereNull('deleted_at')->where('active', true)->where('minimum', '>', 0)->where('minimum', '<=', $subtotal)->whereDate('expires_at', '>=', today())->whereColumn('uses', '<', 'max_uses')->get();
        foreach ($coupons as $coupon) {
            if ($coupon->customer_id && $coupon->customer_id !== $customer->id) {
                continue;
            }
            if ($this->t->query('coupon_grants')->where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->exists()) {
                continue;
            }
            $mail = null;
            if (filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
                $benefit = $coupon->type === 'percent' ? number_format($coupon->value / 100, 2, ',', '.').'%' : Tenant::money($coupon->value);
                if ($coupon->type === 'product') {
                    $product = $this->t->query('products')->where('id', $coupon->product_id)->whereNull('deleted_at')->where('active', true)->first();
                    if (! $product) {
                        continue;
                    } $benefit = 'uma unidade grátis de '.$product->name.' (adicionais cobrados)';
                }
                $mail = $this->t->insert('email_logs', ['customer_id' => $customer->id, 'recipient' => $customer->email, 'subject' => 'Você ganhou um cupom · '.$this->t->company->name, 'body' => 'Olá, '.$customer->name.'! Sua compra #'.$sale.' liberou o cupom '.$coupon->code.'. Ganhe '.$benefit.' em uma próxima compra, sem valor mínimo. Válido até '.Carbon::parse($coupon->expires_at)->format('d/m/Y').'. Uso único por cliente, sujeito ao limite de usos da campanha. Informe o código no atendimento.']);
            }
            $this->t->insert('coupon_grants', ['coupon_id' => $coupon->id, 'customer_id' => $customer->id, 'earned_sale_id' => $sale, 'email_log_id' => $mail]);
        }
    }
}
