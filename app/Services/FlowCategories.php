<?php

namespace App\Services;

class FlowCategories
{
    public const AUTOMATIC = ['Vendas', 'Recebimentos', 'Estornos', 'Movimentações de caixa'];

    public function __construct(private Tenant $t) {}

    public function choices(): array
    {
        return $this->t->company->settings['flow_categories'] ?? ['Retirada', 'Aporte', 'Saldo inicial', 'Aluguel', 'Fornecedor', 'Impostos', 'Salários', 'Ajuste', 'Outros'];
    }

    public function filters(): array
    {
        return collect($this->choices())->merge(self::AUTOMATIC)->merge($this->t->query('flow_entries')->distinct()->pluck('category'))->unique()->sort()->values()->all();
    }
}
