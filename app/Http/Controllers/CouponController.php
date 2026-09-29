<?php

namespace App\Http\Controllers;

use App\Services\Commerce;
use App\Services\Tenant;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function preview(Request $r, Tenant $t, Commerce $commerce)
    {
        $t->authorize('sales', true);
        $t->authorize('loyalty', true);
        $d = $r->validate(['coupon' => 'required|string|max:40', 'customer_id' => 'nullable|integer', 'discount' => 'nullable|string|max:30', 'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'nullable|integer', 'items.*.name' => 'nullable|string|max:160', 'items.*.price' => 'nullable|numeric|min:0.01|max:9999999', 'items.*.quantity' => 'required|integer|min:1|max:10000', 'items.*.addons' => 'nullable|array|max:20', 'items.*.addons.*' => 'integer|min:0|max:19']);
        $customer = ! empty($d['customer_id']) ? $t->find('customers', $d['customer_id']) : null;
        abort_if($customer?->anonymized_at, 422);
        $quote = $commerce->quote($d, $customer);
        $coupon = $quote['coupon'];
        $amount = $quote['discount'] - Commerce::adjustment($d['discount'] ?? 0, $quote['subtotal']);
        $label = $coupon->type === 'product' ? 'Uma unidade grátis: '.$t->find('products', $coupon->product_id)->name.' (adicionais cobrados)' : 'Desconto de '.Tenant::money($amount);

        return response()->json(['code' => $coupon->code, 'type' => $coupon->type, 'discount' => $amount, 'label' => $label]);
    }
}
