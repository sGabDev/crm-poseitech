<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\MercadoPago;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MercadoPagoController extends Controller
{
    public function __construct(private Tenant $t, private MercadoPago $mp) {}

    public function settings(Request $r)
    {
        Gate::authorize('manage-company');
        $data = $r->validate(['enabled' => 'nullable|boolean', 'mode' => 'required|in:test,production', 'access_token' => 'nullable|string|max:500', 'webhook_secret' => 'nullable|string|max:500']);
        $previous = $this->t->company->mercadopago ?? [];
        $token = trim($data['access_token'] ?? '') ?: ($previous['access_token'] ?? '');
        $secret = trim($data['webhook_secret'] ?? '') ?: ($previous['webhook_secret'] ?? '');
        if ($r->boolean('enabled') && (! $token || ! $secret || $this->t->company->currency !== 'BRL' || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https')) {
            throw ValidationException::withMessages(['mercadopago' => 'Para ativar, informe Access Token e assinatura secreta, use BRL e configure APP_URL com o domínio HTTPS público do CRM.']);
        }
        $collector = $previous['collector_id'] ?? null;
        if ($token) {
            try {
                $user = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(15)->get('https://api.mercadopago.com/users/me')->throw()->json();
                $collector = (string) ($user['id'] ?? '');
                if (! $collector) {
                    throw new \RuntimeException('Invalid account');
                }
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['mercadopago' => 'Não foi possível validar a conta. Confira o Access Token e a conexão com o Mercado Pago.']);
            }
        }
        // Existing checkouts must continue to use the same receiving account and environment.
        if (($collector !== ($previous['collector_id'] ?? null) || $data['mode'] !== ($previous['mode'] ?? 'test')) && $this->t->query('mp_checkouts')->whereIn('status', ['creating', 'unknown', 'pending', 'approved', 'review'])->exists()) {
            throw ValidationException::withMessages(['mercadopago' => 'Há links ou pagamentos para acompanhar. Mantenha a mesma conta e ambiente; o token pode ser atualizado para a mesma conta.']);
        }
        $this->t->company->update(['mercadopago' => ['enabled' => $r->boolean('enabled'), 'mode' => $data['mode'], 'access_token' => $token, 'webhook_secret' => $secret, 'collector_id' => $collector]]);

        return back()->with('success', 'Integração Mercado Pago salva e conta verificada.');
    }

    private function authorizeAccount(object $a, bool $write = false): void
    {
        if ($a->sale_id && $this->t->company->enabled('sales') && auth()->user()->allows('sales', $write)) {
            return;
        }
        $this->t->authorize($a->origin === 'credit' ? 'credit' : 'finance', $write);
    }

    public function create(int $id)
    {
        $this->authorizeAccount($this->t->find('accounts', $id), true);
        $checkout = $this->mp->create($id);
        foreach (['email', 'whatsapp'] as $channel) {
            try {
                if ($channel === 'whatsapp') {
                    $this->mp->queueWhatsApp($checkout);
                } else {
                    $this->mp->deliver($checkout, $channel);
                }
            } catch (ValidationException) {
                // Missing customer contact or disabled channel is shown in the payment panel.
            }
        }

        return redirect('/payments/mercadopago/'.$checkout->id)->with('success', 'Link disponível. Envios automáticos solicitados para os canais disponíveis.');
    }

    public function show(int $id)
    {
        $checkout = $this->t->find('mp_checkouts', $id);
        $account = $this->t->find('accounts', $checkout->account_id);
        $this->authorizeAccount($account);
        $deliveries = $this->t->query('mp_deliveries')->where('checkout_id', $id)->get();
        $emails = $this->t->query('email_logs')->whereIn('id', $deliveries->pluck('email_log_id')->filter())->get()->keyBy('id');
        $module = $account->origin === 'credit' ? 'credit' : 'finance';
        $canManage = ($account->sale_id && $this->t->company->enabled('sales') && auth()->user()->allows('sales', true)) || ($this->t->company->enabled($module) && auth()->user()->allows($module, true));

        return view('mercadopago', compact('checkout', 'account', 'deliveries', 'emails', 'canManage'));
    }

    public function action(Request $r, int $id)
    {
        $r->validate(['action' => 'required|in:refresh,email,whatsapp,reconcile']);
        $c = $this->t->find('mp_checkouts', $id);
        $this->authorizeAccount($this->t->find('accounts', $c->account_id), true);
        try {
            if ($r->input('action') === 'refresh') {
                $this->mp->refresh($c);
            } elseif ($r->input('action') === 'reconcile') {
                $this->mp->create($c->account_id);
            } elseif ($r->input('action') === 'whatsapp') {
                $this->t->authorize('whatsapp', true);
                $this->mp->queueWhatsApp($c);
            } else {
                $this->mp->deliver($c, $r->input('action'));
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['mercadopago' => 'Não foi possível consultar o serviço. Os dados da venda foram preservados. Tente novamente.']);
        }

        return redirect('/payments/mercadopago/'.$id)->with('success', 'Solicitação processada. Confira o estado do pagamento e dos envios.');
    }

    public function webhook(Request $r, int $companyId)
    {
        $company = Company::findOrFail($companyId);
        $secret = $company->mercadopago['webhook_secret'] ?? '';
        $bodyId = (string) $r->input('data.id', '');
        $queryId = (string) ($r->query('data_id') ?? $r->query('data.id') ?? '');
        $id = $queryId ?: $bodyId;
        $signature = [];
        foreach (explode(',', $r->header('x-signature', '')) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $signature[$pair[0]] = $pair[1];
            }
        }
        $requestId = $r->header('x-request-id', '');
        $ts = $signature['ts'] ?? '';
        $valid = $secret && ctype_digit($id) && ctype_digit($ts) && $requestId && (! $bodyId || ! $queryId || $bodyId === $queryId);
        $expected = hash_hmac('sha256', "id:$id;request-id:$requestId;ts:$ts;", $secret);
        abort_unless($valid && hash_equals($expected, $signature['v1'] ?? ''), 401);
        if ($r->input('type', $r->query('type')) !== 'payment') {
            return response()->json(['received' => true]);
        }
        $this->t->company = $company;
        try {
            $this->mp->confirm($id);
        } catch (\Throwable $e) {
            // Return a retryable response; never acknowledge an unprocessed payment.
            return response()->json(['received' => false], 503);
        }

        return response()->json(['received' => true]);
    }

    public function publicPayment(string $reference)
    {
        $checkout = DB::table('mp_checkouts')->where('reference', $reference)->firstOrFail();
        $company = Company::findOrFail($checkout->company_id);
        $account = DB::table('accounts')->where('company_id', $company->id)->where('id', $checkout->account_id)->firstOrFail();
        $available = $company->available() && ($company->mercadopago['enabled'] ?? false) && $checkout->checkout_url && $checkout->status === 'pending' && now()->lt($checkout->expires_at) && $account->status === 'pending' && $account->amount - $account->paid === $checkout->amount;

        return response()->view('payment-public', compact('checkout', 'company', 'available'))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
