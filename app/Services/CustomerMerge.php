<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerMerge
{
    public const FIELDS = ['name' => 'Nome', 'document' => 'CPF/CNPJ', 'email' => 'E-mail', 'phone' => 'Telefone', 'whatsapp' => 'WhatsApp', 'birthday' => 'Nascimento', 'address' => 'Endereço', 'district' => 'Bairro', 'city' => 'Cidade', 'source' => 'Origem', 'due_day' => 'Dia de vencimento'];

    public const TABLES = ['sales', 'accounts', 'payments', 'loyalty_transactions', 'customer_credits', 'email_logs', 'debt_receipts', 'customer_deposits', 'wallet_entries', 'coupons', 'coupon_grants', 'whatsapp_messages', 'whatsapp_conversations', 'template_deliveries', 'mp_checkouts', 'catalog_orders'];

    public function __construct(private Tenant $t) {}

    public function authorize(): void
    {
        $this->t->authorize('customers', true);
        Gate::authorize('manage-company');
    }

    public function preview(int $keepId, int $duplicateId): array
    {
        $this->authorize();
        if ($keepId === $duplicateId) {
            $this->fail('Selecione dois clientes diferentes.');
        }
        $keep = $this->t->find('customers', $keepId);
        $duplicate = $this->t->find('customers', $duplicateId);
        if ($keep->anonymized_at || $duplicate->anonymized_at) {
            $this->fail('Cadastros anonimizados não podem ser unidos.');
        }
        foreach (['email_logs', 'whatsapp_messages', 'template_deliveries'] as $table) {
            if ($this->t->query($table)->whereIn('customer_id', [$keepId, $duplicateId])->where('status', 'sending')->exists()) {
                $this->fail('Há mensagens sendo enviadas para estes clientes. Aguarde o término do envio antes de unir.');
            }
        }
        if ($this->t->query('mp_deliveries')->join('mp_checkouts', 'mp_checkouts.id', '=', 'mp_deliveries.checkout_id')->where('mp_checkouts.company_id', $this->t->id())->whereIn('mp_checkouts.customer_id', [$keepId, $duplicateId])->where('mp_deliveries.status', 'sending')->exists()) {
            $this->fail('Há links de pagamento sendo enviados. Aguarde o término do envio antes de unir.');
        }
        $summaries = [];
        foreach ([$keep, $duplicate] as $customer) {
            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = $this->t->query($table)->where('customer_id', $customer->id)->count();
            }
            $counts['wallet'] = (int) $this->t->query('wallet_entries')->where('customer_id', $customer->id)->sum('amount');
            $counts['debt'] = (int) $this->t->query('accounts')->where('customer_id', $customer->id)->where('type', 'receivable')->where('status', 'pending')->selectRaw('COALESCE(SUM(amount-paid),0) as balance')->value('balance');
            $summaries[$customer->id] = $counts;
        }
        $version = hash_hmac('sha256', json_encode([$keep, $duplicate, $summaries]), config('app.key'));

        return compact('keep', 'duplicate', 'summaries', 'version');
    }

    public function merge(int $keepId, int $duplicateId, string $version, array $choices): int
    {
        $this->authorize();

        return DB::transaction(function () use ($keepId, $duplicateId, $version, $choices) {
            $this->t->lock();
            $preview = $this->preview($keepId, $duplicateId);
            if (! hash_equals($preview['version'], $version)) {
                $this->fail('Os cadastros ou saldos mudaram desde a revisão. Abra a comparação novamente antes de unir.');
            }
            ['keep' => $keep, 'duplicate' => $duplicate] = $preview;
            $data = [];
            $conflicts = [];
            foreach (self::FIELDS as $field => $label) {
                $data[$field] = ($choices[$field] ?? 'keep') === 'duplicate' ? $duplicate->$field : ($keep->$field ?: $duplicate->$field);
                if (filled($keep->$field) && filled($duplicate->$field) && (string) $keep->$field !== (string) $duplicate->$field) {
                    $conflicts[] = $label.': cadastro #'.$keepId.' = '.$keep->$field.'; cadastro #'.$duplicateId.' = '.$duplicate->$field;
                }
            }
            if (! filled($data['name'])) {
                $this->fail('O cadastro final precisa de um nome.');
            }
            $tags = collect(preg_split('/[,;\n]+/u', ($keep->tags ?? '').','.($duplicate->tags ?? '')))->map(fn ($tag) => trim($tag))->filter()->unique(fn ($tag) => mb_strtolower($tag))->implode(', ');
            if (mb_strlen($tags) > 255) {
                $this->fail('As tags reunidas excedem 255 caracteres. Reduza as tags antes de unir.');
            }
            $notes = collect([$keep->notes, $duplicate->notes ? 'Observações do cadastro #'.$duplicateId.":\n".$duplicate->notes : null, $conflicts ? "Dados anteriores à união:\n".implode("\n", $conflicts) : null])->filter()->implode("\n\n");
            if (strlen($notes) > 65000) {
                $this->fail('As observações reunidas são muito extensas. Revise antes de unir.');
            }
            $data += ['tags' => $tags ?: null, 'notes' => $notes ?: null, 'portal_hash' => null, 'portal_expires_at' => null,
                'email_consent' => (bool) $keep->email_consent && (bool) $duplicate->email_consent && filter_var($keep->email, FILTER_VALIDATE_EMAIL) && strcasecmp((string) $keep->email, (string) $duplicate->email) === 0,
                'whatsapp_consent' => (bool) $keep->whatsapp_consent && (bool) $duplicate->whatsapp_consent && WhatsApp::phone($keep->whatsapp ?: $keep->phone) !== null && WhatsApp::phone($keep->whatsapp ?: $keep->phone) === WhatsApp::phone($duplicate->whatsapp ?: $duplicate->phone),
                'consented_at' => $keep->consented_at];
            $this->t->update('customers', $keepId, $data);

            // Preserve every historical row, including duplicate campaign deliveries and used grants.
            foreach (self::TABLES as $table) {
                $this->t->query($table)->where('customer_id', $duplicateId)->update(['customer_id' => $keepId, 'updated_at' => now()]);
            }
            foreach ($this->t->query('coupon_grants')->where('customer_id', $keepId)->get()->groupBy('coupon_id') as $grants) {
                if ($grants->count() < 2) {
                    continue;
                }
                // A single customer cannot gain extra uses from duplicate registrations.
                $used = $grants->contains(fn ($g) => $g->used_sale_id !== null);
                $available = $used ? null : $grants->whereNull('revoked_at')->sortBy('id')->first()?->id;
                foreach ($grants as $grant) {
                    if (! $grant->used_sale_id && ! $grant->revoked_at && $grant->id !== $available) {
                        $this->t->update('coupon_grants', $grant->id, ['revoked_at' => now()]);
                    }
                }
            }
            // Cancel pending duplicate campaign messages; sent history stays untouched.
            $campaigns = $this->t->query('email_logs')->where('customer_id', $keepId)->whereNotNull('campaign_id')->get()->groupBy('campaign_id');
            foreach ($campaigns as $messages) {
                $alreadySent = $messages->contains(fn ($m) => in_array($m->status, ['sent', 'sending']));
                $pending = $messages->whereIn('status', ['pending', 'failed'])->sortBy('id');
                $retain = $alreadySent || ! $data['email_consent'] ? null : $pending->first()?->id;
                foreach ($pending as $message) {
                    if ($message->id !== $retain) {
                        $this->t->update('email_logs', $message->id, ['status' => 'cancelled', 'error' => 'Envio suprimido após união de clientes.']);
                    }
                }
            }
            $this->t->query('audit_logs')->where('entity', 'customers')->where('entity_id', $duplicateId)->update(['entity_id' => $keepId]);
            unset($keep->portal_hash, $duplicate->portal_hash);
            $this->t->audit('customer.merged', 'customers', $keepId, ['kept' => $keep, 'duplicate' => $duplicate], ['source_id' => $duplicateId, 'destination_id' => $keepId, 'records' => $preview['summaries'][$duplicateId]]);
            $this->t->query('customers')->where('id', $duplicateId)->delete();

            return $keepId;
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['merge' => $message]);
    }
}
