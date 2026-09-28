<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Analytics;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    public function __construct(private Tenant $t) {}

    public function issue(Request $r, int $id)
    {
        $this->t->authorize('portal', true);
        $c = $this->t->find('customers', $id);
        abort_if($c->anonymized_at, 422);
        if ($r->input('action') === 'revoke') {
            $this->t->update('customers', $id, ['portal_hash' => null, 'portal_expires_at' => null]);
            $this->t->audit('portal.revoked', 'customers', $id);

            return back()->with('success', 'Link revogado.');
        }
        $token = Str::random(64);
        $this->t->update('customers', $id, ['portal_hash' => hash('sha256', $token), 'portal_expires_at' => now()->addDays(30)]);
        $this->t->audit('portal.issued', 'customers', $id);

        return back()->with('portal_link', url('/portal/access/'.$token));
    }

    public function access(Request $r, string $token)
    {
        $c = DB::table('customers')->where('portal_hash', hash('sha256', $token))->where('portal_expires_at', '>', now())->whereNull('anonymized_at')->first();
        abort_unless($c, 404, 'Link inválido ou expirado.');
        $company = Company::with('plan')->findOrFail($c->company_id);
        abort_unless($company->available() && $company->enabled('portal'), 404);
        $r->session()->put('portal', ['id' => $c->id, 'company' => $c->company_id, 'hash' => $c->portal_hash]);
        $r->session()->regenerate();

        return redirect('/portal');
    }

    public function home(Request $r)
    {
        $access = $r->session()->get('portal');
        abort_unless($access, 403, 'Abra o link fornecido pelo estabelecimento.');
        $company = Company::with('plan')->findOrFail($access['company']);
        abort_unless($company->available() && $company->enabled('portal'), 404);
        $this->t->company = $company;
        $customer = $this->t->query('customers')->where('id', $access['id'])->where('portal_hash', $access['hash'])->where('portal_expires_at', '>', now())->firstOrFail();
        $period = app(Analytics::class)->period($r);
        $q = $this->t->query('sales')->where('customer_id', $customer->id)->whereBetween('created_at', $period);
        if ($r->input('method') === 'fiado') {
            $q->whereIn('id', $this->t->query('accounts')->where('origin', 'credit')->select('sale_id'));
        } elseif ($r->filled('method')) {
            $q->whereIn('id', $this->t->query('payments')->where('method', $r->input('method'))->whereNull('reversed_at')->select('sale_id'));
        }
        $totals = (clone $q)->where('status', 'completed')->selectRaw('COALESCE(SUM(total),0) as total, COALESCE(SUM(paid),0) as paid')->first();
        $sales = $q->orderByDesc('id')->paginate(15)->withQueryString();
        $items = $this->t->query('sale_items')->whereIn('sale_id', $sales->pluck('id'))->get()->groupBy('sale_id');
        $accounts = $this->t->query('accounts')->where('customer_id', $customer->id)->where('type', 'receivable')->where('status', '!=', 'cancelled')->orderBy('due_date')->get();
        $payments = $this->t->query('payments')->where('customer_id', $customer->id)->where('direction', 'in')->whereNull('reversed_at')->orderBy('created_at')->get();
        $ledger = $this->t->query('sales')->where('customer_id', $customer->id)->where('status', 'completed')->get()->map(fn ($s) => (object) ['date' => $s->created_at, 'label' => 'Compra #'.$s->id, 'amount' => $s->total])->concat($payments->map(fn ($p) => (object) ['date' => $p->created_at, 'label' => 'Pagamento '.config('poseitech.methods.'.$p->method), 'amount' => -$p->amount]))->sortBy('date');
        $ledger = $ledger->concat($accounts->whereNull('sale_id')->map(fn ($a) => (object) ['date' => $a->created_at, 'label' => $a->description, 'amount' => $a->amount]))->sortBy('date');
        $points = $this->t->query('loyalty_transactions')->where('customer_id', $customer->id)->sum('points');
        $credits = $this->t->query('customer_credits')->where('customer_id', $customer->id)->orderByDesc('id')->get();

        $walletEntries = $this->t->query('wallet_entries')->where('customer_id', $customer->id)->orderByDesc('id')->get();

        return view('portal', compact('walletEntries', 'company', 'customer', 'sales', 'totals', 'items', 'accounts', 'payments', 'ledger', 'points', 'credits'));
    }

    public function logout(Request $r)
    {
        $r->session()->forget('portal');

        return redirect('/login');
    }

    public function receipt(string $token)
    {
        $sale = DB::table('sales')->where('receipt_hash', $token)->firstOrFail();
        $company = Company::with('plan')->findOrFail($sale->company_id);
        abort_unless($company->available(), 404);
        $this->t->company = $company;

        return view('receipt', ['sale' => $sale, 'company' => $company, 'items' => $this->t->query('sale_items')->where('sale_id', $sale->id)->get(), 'payments' => $this->t->query('payments')->where('sale_id', $sale->id)->get()]);
    }

    public function catalog(string $slug)
    {
        $company = Company::with('plan')->where('slug', $slug)->firstOrFail();
        abort_unless($company->available() && $company->enabled('catalog'), 404);
        $this->t->company = $company;

        return view('catalog', ['company' => $company, 'products' => $this->t->query('products')->where('active', true)->orderBy('category')->orderBy('name')->paginate(24)]);
    }

    public function image(Request $r, int $companyId, int $productId)
    {
        $company = Company::with('plan')->findOrFail($companyId);
        abort_unless($company->available(), 404);
        $allowed = $company->enabled('catalog') || ($r->user() && ($r->user()->company_id === $companyId || ($r->user()->role === 'super' && $r->session()->get('support_company') === $companyId)));
        abort_unless($allowed, 404);
        $this->t->company = $company;
        $p = $this->t->find('products', $productId);
        abort_unless($p->image && ($p->active || $r->user()?->company_id === $companyId), 404);

        return Storage::disk('local')->response($p->image, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function logo(int $companyId)
    {
        $company = Company::with('plan')->findOrFail($companyId);
        abort_unless($company->available() && $company->logo, 404);

        return Storage::disk('local')->response($company->logo, null, ['Cache-Control' => 'private, max-age=300']);
    }
}
