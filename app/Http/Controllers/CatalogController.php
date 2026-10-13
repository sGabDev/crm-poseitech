<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CatalogPix;
use App\Services\CatalogRegistration;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function __construct(private Tenant $t) {}

    private function company(string $slug): Company
    {
        $company = Company::with('plan')->where('slug', $slug)->firstOrFail();
        abort_unless($company->available() && $company->enabled('catalog'), 404);
        $this->t->company = $company;

        return $company;
    }

    public function register(Request $r, string $slug)
    {
        $company = $this->company($slug);
        abort_unless($company->enabled('customers'), 404);
        app(CatalogRegistration::class)->save($r, $this->t);

        return redirect('/catalog/'.$slug.'#catalog-cart')->with('success', 'Cadastro salvo. Confira sua sacola para concluir o pedido.');
    }

    public function payment(Request $r, string $slug, int $id)
    {
        $company = $this->company($slug);
        $order = $this->t->query('catalog_orders')->where('id', $id)->where('visitor_hash', self::visitor($r, $company->id))->firstOrFail();
        $checkout = $order->checkout_id ? $this->t->find('mp_checkouts', $order->checkout_id) : null;

        return response()->view('catalog-payment', compact('company', 'order', 'checkout'))->header('Cache-Control', 'private, no-store');
    }

    public function paymentStatus(Request $r, string $slug, int $id)
    {
        $company = $this->company($slug);
        $order = $this->t->query('catalog_orders')->where('id', $id)->where('visitor_hash', self::visitor($r, $company->id))->firstOrFail();
        $checkout = $order->checkout_id ? $this->t->find('mp_checkouts', $order->checkout_id) : null;

        return response()->json(['status' => $checkout?->status ?? 'pending'])->header('Cache-Control', 'private, no-store');
    }

    public function retryPix(Request $r, string $slug, int $id)
    {
        $company = $this->company($slug);
        $order = $this->t->query('catalog_orders')->where('id', $id)->where('visitor_hash', self::visitor($r, $company->id))->firstOrFail();
        abort_unless($order->checkout_id && $order->status === 'pending', 404);
        try {
            app(CatalogPix::class)->generate($this->t->find('mp_checkouts', $order->checkout_id));
        } catch (\Throwable) {
            return redirect('/catalog/'.$slug.'/orders/'.$id)->withErrors(['pix' => 'A geração ainda não foi confirmada. Seu pedido foi preservado; tente novamente em instantes.']);
        }

        return redirect('/catalog/'.$slug.'/orders/'.$id);
    }

    public static function visitor(Request $r, int $company): string
    {
        $name = 'catalog_visitor_'.$company;
        $token = $r->attributes->get($name) ?? $r->cookie($name);
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            Cookie::queue(cookie($name, $token, 525600, '/', null, $r->isSecure(), true, false, 'lax'));
        }
        $r->attributes->set($name, $token);

        return hash('sha256', $token);
    }

    public function checkout(Request $r, string $slug)
    {
        $company = $this->company($slug);
        abort_unless($company->enabled('customers') && $company->enabled('orders') && ($company->settings['catalog_checkout'] ?? false) && ($company->settings['catalog_open'] ?? true), 404);
        $this->t->company = $company;
        $profile = CatalogRegistration::profile($r, $company->id);
        if (! $profile) {
            throw ValidationException::withMessages(['registration' => 'Preencha seu cadastro antes de concluir o pedido.']);
        }
        $d = $r->validate(['request_key' => 'required|uuid', 'payment_method' => 'required|in:pix,cash,card', 'fulfillment' => 'required|in:delivery,pickup', 'notes' => 'nullable|string|max:1000', 'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:0|max:10000', 'items.*.addons' => 'nullable|array|max:20', 'items.*.addons.*' => 'integer|min:0|max:19']) + $profile;
        if ($d['fulfillment'] === 'delivery' && ! $company->enabled('delivery')) {
            throw ValidationException::withMessages(['fulfillment' => 'Esta loja está disponível apenas para retirada.']);
        }
        if ($d['payment_method'] === 'pix' && (! ($company->mercadopago['enabled'] ?? false) || $company->currency !== 'BRL')) {
            throw ValidationException::withMessages(['payment_method' => 'Pix online indisponível nesta loja.']);
        }
        $visitor = self::visitor($r, $company->id);
        $id = DB::transaction(function () use ($d, $company, $visitor, $profile) {
            $this->t->lock();
            if ($existing = $this->t->query('catalog_orders')->where('request_key', $d['request_key'])->first()) {
                abort_unless(hash_equals($existing->visitor_hash ?? '', $visitor), 404);

                return $existing->id;
            }
            $items = [];
            $total = 0;
            $quantities = [];
            foreach ($d['items'] as $line) {
                if (! $line['quantity']) {
                    continue;
                }
                $p = $this->t->query('products')->where('id', $line['product_id'])->where('active', true)->whereNull('deleted_at')->firstOrFail();
                $quantities[$p->id] = ($quantities[$p->id] ?? 0) + $line['quantity'];
                if ($company->enabled('stock') && $p->type === 'product' && $quantities[$p->id] > $p->stock) {
                    throw ValidationException::withMessages(['items' => 'Estoque insuficiente para '.$p->name.'. Atualize as quantidades.']);
                }
                $price = $p->price;
                $addons = json_decode($p->addons ?? '[]', true);
                $chosen = array_values(array_unique($line['addons'] ?? []));
                foreach ($chosen as $index) {
                    if (! isset($addons[$index])) {
                        abort(422);
                    } $price += $addons[$index]['price'];
                }
                $reserve = $d['payment_method'] === 'pix' && $company->enabled('stock') && $p->type === 'product';
                $items[] = ['product_id' => $p->id, 'name' => $p->name, 'price' => $price, 'quantity' => $line['quantity'], 'addons' => $chosen, 'reserved' => $reserve];
                $total += $price * $line['quantity'];
            }
            if (! $items || $total <= 0 || $total > 99999999999) {
                throw ValidationException::withMessages(['items' => 'Adicione pelo menos um item e confira o total.']);
            }
            if ($total < ($company->settings['catalog_minimum'] ?? 0)) {
                throw ValidationException::withMessages(['items' => 'O pedido não atingiu o valor mínimo da loja.']);
            }
            $fee = $d['fulfillment'] === 'delivery' ? (int) ($company->settings['catalog_delivery_fee'] ?? 0) : 0;
            $id = $this->t->insert('catalog_orders', ['customer_id' => $d['customer_id'], 'payment_method' => $d['payment_method'], 'fulfillment' => $d['fulfillment'], 'delivery_fee' => $fee, 'stock_reserved' => $d['payment_method'] === 'pix', 'visitor_hash' => $visitor, 'request_key' => $d['request_key'], 'name' => $d['name'], 'phone' => $d['phone'], 'address' => $d['address'], 'notes' => $d['notes'] ?? null, 'items' => json_encode($items), 'total' => $total + $fee]);
            if ($d['payment_method'] === 'pix') {
                $pixCheckout = app(CatalogPix::class)->prepare($id, $profile);
                foreach ($items as $item) {
                    if ($item['reserved']) {
                        $this->t->query('products')->where('id', $item['product_id'])->decrement('stock', $item['quantity'], ['updated_at' => now()]);
                        $this->t->insert('stock_movements', ['user_id' => $pixCheckout->user_id, 'product_id' => $item['product_id'], 'type' => 'reservation', 'quantity' => -$item['quantity'], 'balance' => $this->t->find('products', $item['product_id'])->stock, 'notes' => 'Reserva pedido online #'.$id]);
                    }
                }
            }

            return $id;
        }, 3);
        $order = $this->t->find('catalog_orders', $id);
        if ($order->checkout_id) {
            try {
                app(CatalogPix::class)->generate($this->t->find('mp_checkouts', $order->checkout_id));
            } catch (\Throwable) {
                return redirect('/catalog/'.$slug.'/orders/'.$id)->with('catalog_submitted', true)->withErrors(['pix' => 'Pedido salvo. Estamos aguardando a geração do Pix; você pode consultar novamente nesta tela.']);
            }

            return redirect('/catalog/'.$slug.'/orders/'.$id)->with('catalog_submitted', true);
        }

        return redirect('/catalog/'.$slug.'#catalog-history')->with('catalog_submitted', true)->with('success', 'Pedido #'.$id.' recebido! A equipe confirmará disponibilidade, entrega e pagamento pelo telefone informado.');
    }

    public function action(Request $r, int $id)
    {
        $this->t->authorize('orders', true);
        $d = $r->validate(['action' => 'required|in:cancel,prepare']);
        $order = $this->t->find('catalog_orders', $id);
        abort_unless($order->status === 'pending', 422);
        if ($d['action'] === 'cancel') {
            if ($order->checkout_id) {
                app(CatalogPix::class)->release($order);

                return redirect('/orders')->with('success', 'Pedido cancelado e reserva liberada.');
            }
            $this->t->query('catalog_orders')->where('id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->t->audit('catalog.cancelled', 'catalog_orders', $id);

            return back()->with('success', 'Solicitação cancelada.');
        }
        $this->t->authorize('sales', true);

        if ($order->checkout_id && $this->t->find('mp_checkouts', $order->checkout_id)->status !== 'approved') {
            throw ValidationException::withMessages(['payment' => 'Aguarde a confirmação real do Pix antes de registrar a venda.']);
        }

        return redirect('/sales/new')->withInput(['catalog_order_id' => $id, 'customer_id' => $order->customer_id, 'items' => json_decode($order->items, true), 'payments' => [['method' => $order->checkout_id ? 'mercadopago' : $order->payment_method, 'amount' => number_format($order->total / 100, 2, '.', '')]], 'order' => 1, 'delivery' => $order->fulfillment === 'delivery', 'fee' => number_format($order->delivery_fee / 100, 2, '.', ''), 'address' => $order->address, 'notes' => 'Pedido online #'.$id.' · '.$order->name.' · '.$order->phone.' · '.$order->notes]);
    }
}
