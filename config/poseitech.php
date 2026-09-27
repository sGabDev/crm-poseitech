<?php

return [
    'modules' => [
        'sales' => 'Vendas', 'customers' => 'Clientes / CRM', 'products' => 'Produtos e serviços',
        'stock' => 'Estoque', 'cash' => 'Caixa', 'credit' => 'Fiados', 'finance' => 'Financeiro',
        'campaigns' => 'Campanhas', 'loyalty' => 'Fidelidade', 'orders' => 'Pedidos',
        'delivery' => 'Delivery', 'catalog' => 'Catálogo público', 'portal' => 'Portal do cliente',
    ],
    'defaults' => ['sales', 'customers', 'products', 'cash', 'credit', 'finance', 'portal'],
    'methods' => ['cash' => 'Dinheiro', 'pix' => 'PIX', 'debit' => 'Débito', 'credit' => 'Crédito', 'boleto' => 'Boleto', 'other' => 'Outros'],
    'order_statuses' => ['received' => 'Recebido', 'confirmed' => 'Confirmado', 'preparing' => 'Preparando', 'ready' => 'Pronto', 'shipping' => 'Saiu para entrega', 'delivered' => 'Entregue', 'cancelled' => 'Cancelado'],
    'resources' => [
        'customers' => ['title' => 'Clientes', 'module' => 'customers', 'fields' => [
            'name' => ['Nome', 'text', 'required|string|max:160'], 'document' => ['CPF/CNPJ', 'text', 'nullable|string|max:20'],
            'phone' => ['Telefone', 'text', 'nullable|string|max:30'], 'whatsapp' => ['WhatsApp', 'text', 'nullable|string|max:30'],
            'email' => ['E-mail', 'email', 'nullable|email|max:180'], 'birthday' => ['Nascimento', 'date', 'nullable|date|before:today'],
            'address' => ['Endereço', 'text', 'nullable|string|max:255'], 'district' => ['Bairro', 'text', 'nullable|string|max:100'],
            'city' => ['Cidade', 'text', 'nullable|string|max:100'], 'tags' => ['Tags', 'text', 'nullable|string|max:255'],
            'source' => ['Origem', 'text', 'nullable|string|max:100'], 'notes' => ['Observações', 'textarea', 'nullable|string|max:3000'],
            'email_consent' => ['Aceita promoções por e-mail', 'checkbox', 'boolean'], 'whatsapp_consent' => ['Aceita promoções por WhatsApp', 'checkbox', 'boolean'],
        ]],
        'products' => ['title' => 'Produtos e serviços', 'module' => 'products', 'fields' => [
            'name' => ['Nome', 'text', 'required|string|max:160'], 'code' => ['Código', 'text', 'nullable|string|max:50'],
            'type' => ['Tipo', 'select:product=Produto,service=Serviço', 'required|in:product,service'],
            'category' => ['Categoria', 'text', 'nullable|string|max:100'], 'price' => ['Preço (R$)', 'money', 'required|numeric|min:0|max:9999999'],
            'cost' => ['Custo (R$)', 'money', 'required|numeric|min:0|max:9999999'], 'min_stock' => ['Estoque mínimo', 'number', 'required|integer|min:0|max:1000000'],
            'description' => ['Descrição', 'textarea', 'nullable|string|max:3000'], 'image' => ['Imagem (JPG, PNG ou WebP, até 2 MB)', 'file', 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048'],
            'addons' => ['Adicionais: um por linha, no formato Nome | preço (ex.: Embalagem | 2.50)', 'textarea', 'nullable|string|max:3000'],
            'active' => ['Ativo', 'checkbox', 'boolean'],
        ]],
        'suppliers' => ['title' => 'Fornecedores', 'module' => 'finance', 'fields' => [
            'name' => ['Empresa', 'text', 'required|string|max:160'], 'contact' => ['Responsável', 'text', 'nullable|string|max:160'],
            'document' => ['CNPJ', 'text', 'nullable|string|max:20'], 'phone' => ['Telefone', 'text', 'nullable|string|max:30'],
            'email' => ['E-mail', 'email', 'nullable|email|max:180'], 'products' => ['Produtos fornecidos', 'textarea', 'nullable|string|max:3000'],
            'notes' => ['Observações', 'textarea', 'nullable|string|max:3000'],
        ]],
        'coupons' => ['title' => 'Cupons', 'module' => 'loyalty', 'fields' => [
            'code' => ['Código', 'text', 'required|alpha_dash|max:40'], 'type' => ['Tipo', 'select:fixed=Valor fixo,percent=Percentual', 'required|in:fixed,percent'],
            'value' => ['Valor em R$ ou percentual', 'money', 'required|numeric|min:0.01|max:99999'],
            'minimum' => ['Compra mínima (R$)', 'money', 'required|numeric|min:0|max:9999999'],
            'max_uses' => ['Limite de usos', 'number', 'required|integer|min:1|max:1000000'],
            'expires_at' => ['Validade', 'date', 'required|date'], 'customer_id' => ['Cliente exclusivo (opcional)', 'customer', 'nullable|integer'],
            'active' => ['Ativo', 'checkbox', 'boolean'],
        ]],
        'goals' => ['title' => 'Metas', 'module' => 'sales', 'fields' => [
            'name' => ['Nome', 'text', 'required|string|max:160'], 'metric' => ['Indicador', 'select:revenue=Faturamento,sales=Quantidade de vendas,ticket=Ticket médio,customers=Novos clientes', 'required|in:revenue,sales,ticket,customers'],
            'target' => ['Meta (R$ ou quantidade)', 'money', 'required|numeric|min:0.01|max:99999999'],
            'starts_at' => ['Início', 'date', 'required|date'], 'ends_at' => ['Fim', 'date', 'required|date|after_or_equal:starts_at'],
        ]],
    ],
];
