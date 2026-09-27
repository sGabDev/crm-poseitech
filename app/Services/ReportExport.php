<?php

namespace App\Services;

class ReportExport
{
    public function __construct(private Tenant $t) {}

    public function download(string $type, array $period)
    {
        $definitions = [
            'sales' => ['sales', 'sales', ['id' => 'Venda', 'created_at' => 'Data UTC', 'customer_id' => 'Cliente ID', 'total' => 'Total R$', 'paid' => 'Pago R$', 'user_id' => 'Vendedor ID', 'status' => 'Status'], ['total', 'paid']],
            'customers' => ['customers', 'customers', ['id' => 'ID', 'name' => 'Nome', 'phone' => 'Telefone', 'email' => 'E-mail', 'created_at' => 'Cadastro UTC'], []],
            'products' => ['products', 'products', ['id' => 'ID', 'name' => 'Nome', 'category' => 'Categoria', 'price' => 'Preço R$', 'cost' => 'Custo R$', 'stock' => 'Estoque', 'active' => 'Ativo'], ['price', 'cost']],
            'stock' => ['stock', 'stock_movements', ['created_at' => 'Data UTC', 'product_id' => 'Produto ID', 'type' => 'Tipo', 'quantity' => 'Quantidade', 'balance' => 'Saldo', 'notes' => 'Motivo'], []],
            'cash' => ['cash', 'cash_transactions', ['created_at' => 'Data UTC', 'cash_register_id' => 'Caixa ID', 'description' => 'Descrição', 'method' => 'Forma', 'amount' => 'Valor R$'], ['amount']],
            'finance' => ['finance', 'accounts', ['id' => 'Conta', 'description' => 'Descrição', 'type' => 'Tipo', 'due_date' => 'Vencimento', 'amount' => 'Valor R$', 'paid' => 'Pago R$', 'status' => 'Status'], ['amount', 'paid']],
            'credit' => ['credit', 'accounts', ['id' => 'Conta', 'customer_id' => 'Cliente ID', 'description' => 'Descrição', 'due_date' => 'Vencimento', 'amount' => 'Valor R$', 'paid' => 'Pago R$', 'status' => 'Status'], ['amount', 'paid']],
            'payments' => ['finance', 'payments', ['created_at' => 'Data UTC', 'sale_id' => 'Venda ID', 'method' => 'Forma', 'direction' => 'Direção', 'amount' => 'Valor R$', 'reversed_at' => 'Estornado em'], ['amount']],
            'campaigns' => ['campaigns', 'campaigns', ['name' => 'Campanha', 'segment' => 'Segmento', 'status' => 'Status', 'recipients' => 'Destinatários', 'created_at' => 'Cadastro UTC'], []],
            'loyalty' => ['loyalty', 'loyalty_transactions', ['created_at' => 'Data UTC', 'customer_id' => 'Cliente ID', 'points' => 'Pontos', 'description' => 'Descrição'], []],
        ];
        abort_unless(isset($definitions[$type]), 404);
        [$module,$table,$columns,$money] = $definitions[$type];
        $this->t->authorize($module);
        $q = $this->t->query($table);
        if (! in_array($type, ['customers', 'products'])) {
            $q->whereBetween('created_at', $period);
        }
        if ($type === 'credit') {
            $q->where('origin', 'credit');
        }
        $this->t->audit('report.export', $table);

        return response()->streamDownload(function () use ($q, $columns, $money) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_values($columns), ';', '"', '');
            foreach ($q->orderBy('id')->cursor() as $row) {
                $values = [];
                foreach ($columns as $key => $label) {
                    $value = in_array($key, $money) ? number_format($row->$key / 100, 2, ',', '') : (string) ($row->$key ?? '');
                    if (preg_match('/^[=+@\-\t\r\n]/', $value)) {
                        $value = "'".$value;
                    }
                    $values[] = $value;
                }fputcsv($stream, $values, ';', '"', '');
            }fclose($stream);
        }, $type === 'sales' ? 'vendas.csv' : $type.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
