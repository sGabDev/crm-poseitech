<?php

namespace App\Services;

use Carbon\Carbon;
use DOMDocument;

class ReceiptHtml
{
    public const VARIABLES = ['nome', 'empresa', 'venda', 'total', 'comprovante', 'data', 'endereco', 'itens_html', 'resumo_html', 'pagamentos_html', 'portal_html', 'mensagem_html'];

    public static function defaults(): string
    {
        return file_get_contents(resource_path('templates/receipt.html'));
    }

    public static function sanitize(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $allowed = ['html', 'head', 'title', 'body', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'div', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'br', 'hr', 'a', 'ul', 'ol', 'li', 'blockquote'];
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $element) {
            if (! in_array(strtolower($element->tagName), $allowed, true)) {
                $element->parentNode?->removeChild($element);

                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                $valid = in_array($name, ['lang', 'role', 'width', 'height', 'cellpadding', 'cellspacing', 'align', 'valign', 'colspan', 'rowspan', 'style', 'href', 'title'], true);
                if ($name === 'href') {
                    $valid = preg_match('~^(https?://|mailto:|\{(?:comprovante)\}$)~i', trim($value)) === 1;
                }
                if (! $valid) {
                    $element->removeAttribute($attribute->name);
                } elseif ($name === 'style') {
                    $element->setAttribute('style', self::style($value));
                }
            }
        }
        $result = $dom->saveHTML();

        $result = preg_replace('/<\?xml[^>]*>/', '', $result);

        return str_ireplace('%7Bcomprovante%7D', '{comprovante}', $result);
    }

    private static function style(string $style): string
    {
        $allowed = ['color', 'background', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-align', 'text-decoration', 'vertical-align', 'white-space', 'word-break', 'overflow-wrap', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height', 'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'border', 'border-top', 'border-bottom', 'border-left', 'border-right', 'border-radius', 'border-collapse', 'border-spacing', 'display', 'overflow'];
        $clean = [];
        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            if (in_array(strtolower(trim($property)), $allowed, true) && preg_match('/^[\pL\pN\s#.,%()\-\'"\/]+$/u', trim($value)) && ! preg_match('/url\s*\(|expression|javascript|behavior|@import/i', $value)) {
                $clean[] = trim($property).':'.trim($value);
            }
        }

        return implode(';', $clean);
    }

    public static function render(string $html, array $values, Tenant $tenant, string $body): string
    {
        $sale = $tenant->find('sales', (int) $values['venda']);
        $customer = $tenant->find('customers', $sale->customer_id);
        $data = ['sale' => $sale, 'customer' => $customer, 'company' => $tenant->company, 'items' => $tenant->query('sale_items')->where('sale_id', $sale->id)->get(), 'payments' => $tenant->query('payments')->where('sale_id', $sale->id)->get()];
        $values += ['data' => Carbon::parse($sale->created_at)->timezone($tenant->company->timezone)->format('d/m/Y H:i'), 'endereco' => $tenant->company->address ?? ''];
        $map = [];
        foreach ($values as $key => $value) {
            $map['{'.$key.'}'] = e($value);
        }
        foreach (['itens', 'resumo', 'pagamentos', 'portal'] as $part) {
            $map['{'.$part.'_html}'] = view('emails.receipt-'.$part, $data)->render();
        }
        $original = 'Olá, '.$customer->name.'. Sua compra #'.$sale->id.' foi registrada. Total: '.$values['total'].'.';
        $map['{mensagem_html}'] = $body === $original ? '' : nl2br(e($body));

        return self::sanitize(strtr(self::sanitize($html), $map));
    }
}
