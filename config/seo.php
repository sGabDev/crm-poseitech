<?php

return [
    'indexing' => env('SEO_INDEXING', env('APP_ENV') === 'production'),
    'name' => env('SEO_SITE_NAME', 'PoseiTech CRM'),
    'description' => env('SEO_DESCRIPTION', 'Gestão de vendas, clientes, atendimento e pedidos em um só lugar.'),
];
