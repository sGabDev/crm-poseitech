<?php

namespace App\Services;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageTemplates
{
    public const PURPOSES = ['receipt' => 'Comprovante de venda', 'billing' => 'Cobrança', 'general' => 'Mensagem livre'];

    public const VARIABLES = [
        'general' => ['nome', 'empresa'],
        'receipt' => ['nome', 'empresa', 'venda', 'total', 'comprovante'],
        'billing' => ['nome', 'empresa', 'descricao', 'saldo', 'vencimento'],
    ];

    public function __construct(private Tenant $t) {}

    public function ensureDefaults(): void
    {
        DB::transaction(function () {
            $company = Company::whereKey($this->t->id())->lockForUpdate()->firstOrFail();
            $settings = $company->settings ?? [];
            if ($settings['message_templates_initialized'] ?? false) {
                return;
            }
            $billing = 'Olá, {nome}. A conta {descricao} possui saldo de {saldo} e vencimento em {vencimento}. Entre em contato para combinar o pagamento. Se já pagou, fale com nossa equipe.';
            $defaults = [
                ['name' => 'Comprovante de venda · E-mail', 'channel' => 'email', 'purpose' => 'receipt', 'subject' => 'Comprovante da compra #{venda} · {empresa}', 'body' => 'Olá, {nome}. Sua compra #{venda} foi registrada. Total: {total}.'],
                ['name' => 'Comprovante de venda · WhatsApp', 'channel' => 'whatsapp', 'purpose' => 'receipt', 'subject' => null, 'body' => "Olá, {nome}! Sua compra #{venda} na {empresa} foi finalizada. Total: {total}.\nComprovante: {comprovante}"],
                ['name' => 'Lembrete de cobrança · E-mail', 'channel' => 'email', 'purpose' => 'billing', 'subject' => 'Lembrete de vencimento • {empresa}', 'body' => $billing],
                ['name' => 'Lembrete de cobrança · WhatsApp', 'channel' => 'whatsapp', 'purpose' => 'billing', 'subject' => null, 'body' => $billing],
            ];
            foreach ($defaults as $data) {
                $hasDefault = $this->t->query('message_templates')->where('channel', $data['channel'])->where('purpose', $data['purpose'])->where('is_default', true)->exists();
                $this->t->insert('message_templates', $data + ['is_default' => ! $hasDefault]);
            }
            $company->update(['settings' => array_replace($settings, ['message_templates_initialized' => true])]);
            $this->t->company->settings = $company->settings;
        });
    }

    public function validate(string $purpose, string $text): void
    {
        preg_match_all('/\{([^{}]+)\}/u', $text, $matches);
        if (array_diff($matches[1], self::VARIABLES[$purpose])) {
            throw ValidationException::withMessages(['body' => 'Use apenas as variáveis disponíveis para esta finalidade.']);
        }
    }

    public function values(object $customer, ?object $sale = null, ?object $account = null): array
    {
        $values = ['nome' => $customer->name, 'empresa' => $this->t->company->name];
        if ($sale) {
            $values += ['venda' => $sale->id, 'total' => Tenant::money($sale->total), 'comprovante' => url('/receipt/'.$sale->receipt_hash)];
        }
        if ($account) {
            $values += ['descricao' => $account->description, 'saldo' => Tenant::money($account->amount - $account->paid), 'vencimento' => Carbon::parse($account->due_date)->format('d/m/Y')];
        }

        return $values;
    }

    public function render(object $template, array $values): array
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map['{'.$key.'}'] = (string) $value;
        }
        foreach ([$template->body, $template->subject ?? ''] as $text) {
            preg_match_all('/\{([^{}]+)\}/u', $text, $matches);
            if (array_diff($matches[1], array_keys($values))) {
                throw ValidationException::withMessages(['template' => 'Selecione a venda ou conta necessária para preencher este modelo.']);
            }
        }

        return ['body' => strtr($template->body, $map), 'subject' => strtr($template->subject ?? '', $map)];
    }

    public function automatic(string $channel, string $purpose, array $values, array $fallback): array
    {
        if (! $this->t->company->enabled('message_templates')) {
            return $fallback;
        }
        $template = $this->t->query('message_templates')->where('channel', $channel)->where('purpose', $purpose)->where('is_default', true)->first();

        return $template ? $this->render($template, $values) : $fallback;
    }
}
