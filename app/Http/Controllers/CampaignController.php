<?php

namespace App\Http\Controllers;

use App\Services\Analytics;
use App\Services\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CampaignController extends Controller
{
    public function __construct(private Tenant $t, private Analytics $analytics) {}

    public function index()
    {
        $this->t->authorize('campaigns');

        return view('campaigns', ['campaigns' => $this->t->query('campaigns')->orderByDesc('id')->paginate(15), 'logs' => $this->t->query('email_logs')->orderByDesc('id')->limit(30)->get(), 'products' => $this->t->query('products')->orderBy('name')->get()]);
    }

    public function store(Request $r)
    {
        $this->t->authorize('campaigns', true);
        $d = $r->validate(['name' => 'required|string|max:160', 'segment' => 'required|in:all,vip,new,inactive,birthday,product,ticket,pending,recurring', 'days' => 'required|integer|min:1|max:3650',
            'product_id' => 'nullable|integer', 'minimum_ticket' => 'required|numeric|min:0|max:99999999', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:10000']);
        if ($d['segment'] === 'product') {
            $this->t->find('products', (int) ($d['product_id'] ?? 0));
        }
        $d['minimum_ticket'] = Tenant::cents($d['minimum_ticket']);
        DB::transaction(function () use ($d) {
            $this->t->lock();
            $this->t->limit('campaigns');
            $id = $this->t->insert('campaigns', $d);
            $this->t->audit('campaign.created', 'campaigns', $id);
        });

        return back()->with('success', 'Rascunho salvo. Revise antes de colocar na fila.');
    }

    public function send(Request $r, int $id)
    {
        $this->t->authorize('campaigns', true);
        abort_unless($this->t->company->smtp, 422, 'Configure o SMTP da empresa antes do envio.');
        DB::transaction(function () use ($id) {
            $this->t->lock();
            $c = $this->t->find('campaigns', $id);
            abort_unless($c->status === 'draft', 422, 'Campanha já colocada na fila.');
            $customers = $this->analytics->customers($c->segment, $c->days, $c->product_id, $c->minimum_ticket)->where('email_consent', true)->whereNotNull('email')->get();
            foreach ($customers as $customer) {
                $this->t->insert('email_logs', ['campaign_id' => $id, 'customer_id' => $customer->id, 'recipient' => $customer->email, 'subject' => $c->subject, 'body' => str_replace('{nome}', $customer->name, $c->body)]);
            }
            $this->t->update('campaigns', $id, ['status' => $customers->isEmpty() ? 'completed' : 'queued', 'recipients' => $customers->count()]);
            $this->t->audit('campaign.queued', 'campaigns', $id, null, ['recipients' => $customers->count()]);
        });

        return back()->with('success', 'Campanha colocada na fila de envio.');
    }

    public function receipt(Request $r, int $id)
    {
        $this->t->authorize('sales', true);
        $sale = $this->t->find('sales', $id);
        abort_unless($sale->customer_id, 422, 'Venda sem cliente.');
        $customer = $this->t->find('customers', $sale->customer_id);
        abort_unless($customer->email && ! $customer->anonymized_at, 422, 'Cliente sem e-mail.');
        abort_unless($this->t->company->smtp, 422, 'Configure o SMTP.');
        $status = DB::transaction(function () use ($id, $customer, $sale) {
            $this->t->lock();
            $existing = $this->t->query('email_logs')->where('sale_id', $id)->first();
            if ($existing) {
                if (in_array($existing->status, ['failed', 'cancelled'])) {
                    $this->t->query('email_logs')->where('id', $existing->id)->whereIn('status', ['failed', 'cancelled'])->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);

                    return 'pending';
                }

                return $existing->status;
            }
            $this->t->insert('email_logs', ['sale_id' => $id, 'customer_id' => $customer->id, 'recipient' => $customer->email, 'subject' => 'Comprovante de compra #'.$id,
                'body' => 'Olá, '.$customer->name.'. Seu comprovante de '.Tenant::money($sale->total).' está disponível em '.url('/receipt/'.$sale->receipt_hash)]);

            return 'pending';
        });
        $this->t->audit('receipt.queued', 'sales', $id);

        return back()->with('success', $status === 'sent' ? 'Este comprovante já foi enviado.' : ($status === 'sending' ? 'Este comprovante está sendo enviado.' : 'Comprovante colocado na fila.'));
    }

    public function billing(Request $r, int $id)
    {
        $account = $this->t->find('accounts', $id);
        $this->t->authorize($account->origin === 'credit' ? 'credit' : 'finance', true);
        abort_unless($account->type === 'receivable' && $account->status === 'pending' && $account->customer_id, 422, 'Conta sem cobrança pendente.');
        $customer = $this->t->find('customers', $account->customer_id);
        abort_unless($customer->email && ! $customer->anonymized_at && $this->t->company->smtp, 422, 'Cadastre o e-mail do cliente e configure o SMTP.');
        $this->t->insert('email_logs', ['customer_id' => $customer->id, 'recipient' => $customer->email, 'subject' => 'Lembrete de vencimento • '.$this->t->company->name,
            'body' => 'Olá, '.$customer->name.'. A conta '.$account->description.' possui saldo de '.Tenant::money($account->amount - $account->paid).' e vencimento em '.Carbon::parse($account->due_date)->format('d/m/Y').'. Entre em contato para combinar o pagamento. Se já pagou, fale com nossa equipe.']);
        $this->t->audit('billing.queued', 'accounts', $id);

        return back()->with('success', 'Lembrete colocado na fila.');
    }

    public function report(Request $r)
    {
        Gate::authorize('manage-company');
        $this->t->authorize('finance');
        abort_unless($this->t->company->smtp, 422, 'Configure o SMTP.');
        $data = $this->analytics->dashboard($this->analytics->period($r));
        $this->t->insert('email_logs', ['recipient' => auth()->user()->email, 'subject' => 'Resumo gerencial • '.$this->t->company->name,
            'body' => 'Faturamento: '.Tenant::money($data['totals']->revenue)."\nVendas: ".$data['totals']->count."\nTicket médio: ".Tenant::money($data['ticket'])."\nRecebido: ".Tenant::money($data['incoming'])."\nA receber (total): ".Tenant::money($data['receivable'])]);
        $this->t->audit('report.queued');

        return back()->with('success', 'Resumo colocado na fila para o seu e-mail.');
    }
}
