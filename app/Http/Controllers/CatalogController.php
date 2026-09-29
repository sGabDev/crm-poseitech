<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function __construct(private Tenant $t) {}

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
        $company = Company::where('slug', $slug)->firstOrFail();
        abort_unless($company->available() && $company->enabled('catalog') && $company->enabled('orders') && ($company->settings['catalog_checkout'] ?? false), 404);
        $this->t->company = $company;
        $d = $r->validate(['request_key' => 'required|uuid', 'name' => 'required|string|max:160', 'phone' => 'required|string|max:30|regex:/^[+()\d\s-]{10,30}$/', 'address' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:1000', 'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:0|max:10000', 'items.*.addons' => 'nullable|array|max:20', 'items.*.addons.*' => 'integer|min:0|max:19']);
        $visitor = self::visitor($r, $company->id);
        $id = DB::transaction(function () use ($d, $company, $visitor) {
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
                $items[] = ['product_id' => $p->id, 'name' => $p->name, 'price' => $price, 'quantity' => $line['quantity'], 'addons' => $chosen];
                $total += $price * $line['quantity'];
            }
            if (! $items || $total <= 0 || $total > 99999999999) {
                throw ValidationException::withMessages(['items' => 'Adicione pelo menos um item e confira o total.']);
            }

            return $this->t->insert('catalog_orders', ['visitor_hash' => $visitor, 'request_key' => $d['request_key'], 'name' => $d['name'], 'phone' => $d['phone'], 'address' => $d['address'] ?? null, 'notes' => $d['notes'] ?? null, 'items' => json_encode($items), 'total' => $total]);
        }, 3);

        return redirect('/catalog/'.$slug.'#catalog-history')->with('catalog_submitted', true)->with('success', 'Pedido #'.$id.' recebido! A equipe confirmará disponibilidade, entrega e pagamento pelo telefone informado.');
    }

    public function action(Request $r, int $id)
    {
        $this->t->authorize('orders', true);
        $d = $r->validate(['action' => 'required|in:cancel,prepare']);
        $order = $this->t->find('catalog_orders', $id);
        abort_unless($order->status === 'pending', 422);
        if ($d['action'] === 'cancel') {
            $this->t->query('catalog_orders')->where('id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->t->audit('catalog.cancelled', 'catalog_orders', $id);

            return back()->with('success', 'Solicitação cancelada.');
        }
        $this->t->authorize('sales', true);

        return redirect('/sales/new')->withInput(['catalog_order_id' => $id, 'items' => json_decode($order->items, true), 'order' => 1, 'address' => $order->address, 'notes' => 'Pedido online #'.$id.' · '.$order->name.' · '.$order->phone.' · '.$order->notes]);
    }
}
