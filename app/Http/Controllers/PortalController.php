<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\PortalLink;
use App\Services\Tenant;
use Carbon\Carbon;
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
        if (! $c->portal_hash) {
            $this->t->update('customers', $id, ['portal_hash' => hash('sha256', Str::random(64)), 'portal_expires_at' => null]);
            $c = $this->t->find('customers', $id);
        }
        $this->t->audit('portal.issued', 'customers', $id);

        return back()->with('portal_link', PortalLink::url($c));
    }

    public function access(Request $r, string $token)
    {
        if (preg_match('/^p\.(\d+)\.([a-f0-9]{64})$/D', $token, $parts)) {
            $c = DB::table('customers')->where('id', $parts[1])->whereNotNull('portal_hash')->whereNull('anonymized_at')->first();
            abort_unless($c && hash_equals(PortalLink::token($c), $token), 404, 'Link inválido ou revogado.');
        } else {
            $c = DB::table('customers')->where('portal_hash', hash('sha256', $token))->whereNull('anonymized_at')->first();
        }
        abort_unless($c, 404, 'Link inválido ou revogado.');
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
        $customer = $this->t->query('customers')->where('id', $access['id'])->where('portal_hash', $access['hash'])->whereNull('anonymized_at')->firstOrFail();
        $r->validate(['month' => 'nullable|date_format:Y-m']);
        $month = $r->input('month', now($company->timezone)->format('Y-m'));
        $start = Carbon::createFromFormat('!Y-m', $month, $company->timezone)->startOfMonth();
        $monthLabel = $start->copy()->locale('pt_BR')->translatedFormat('F \\d\\e Y');
        $q = $this->t->query('sales')->where('customer_id', $customer->id)->where('created_at', '>=', $start->copy()->utc())->where('created_at', '<', $start->copy()->addMonth()->utc());
        $totals = (clone $q)->where('status', 'completed')->selectRaw('COALESCE(SUM(total),0) as total, COALESCE(SUM(paid),0) as paid')->first();
        $sales = $q->orderByDesc('id')->paginate(15)->withQueryString();
        $accounts = $this->t->query('accounts')->where('customer_id', $customer->id)->where('type', 'receivable')->where('status', 'pending')->whereColumn('amount', '>', 'paid')->orderBy('due_date')->get();

        return view('portal', compact('month', 'monthLabel', 'company', 'customer', 'sales', 'totals', 'accounts'));
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
