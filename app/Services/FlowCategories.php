<?php

namespace App\Services;

class FlowCategories
{
    public const AUTOMATIC = ['Vendas', 'Recebimentos', 'Estornos', 'Movimentações de caixa'];

    public function __construct(private Tenant $t) {}

    public function choices(?string $direction = null): array
    {
        if ($direction === null) {
            return array_values(array_unique(array_merge($this->choices('in'), $this->choices('out'))));
        }

        return $this->t->company->settings['flow_categories_'.$direction]
            ?? $this->t->company->settings['flow_categories']
            ?? ($direction === 'in' ? ['Aporte', 'Saldo inicial', 'Reembolso', 'Ajuste', 'Outros recebimentos'] : ['Retirada', 'Aluguel', 'Fornecedor', 'Impostos', 'Salários', 'Ajuste', 'Outras despesas']);
    }

    public function filters(): array
    {
        return collect($this->choices())->merge(self::AUTOMATIC)->merge($this->t->query('flow_entries')->distinct()->pluck('category'))->unique()->sort()->values()->all();
    }
}
