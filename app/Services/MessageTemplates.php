<?php

namespace App\Services;

use Carbon\Carbon;
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
