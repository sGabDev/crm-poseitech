<?php

return [
    'required' => 'O campo :attribute é obrigatório.', 'email' => 'Informe um e-mail válido em :attribute.', 'unique' => 'Este valor de :attribute já está cadastrado.',
    'min' => ['string' => ':attribute deve ter pelo menos :min caracteres.', 'numeric' => ':attribute deve ser no mínimo :min.', 'array' => ':attribute deve conter pelo menos :min item.'],
    'max' => ['string' => ':attribute deve ter no máximo :max caracteres.', 'numeric' => ':attribute deve ser no máximo :max.', 'array' => ':attribute deve conter no máximo :max itens.', 'file' => ':attribute deve ter no máximo :max KB.'],
    'numeric' => ':attribute deve ser um número.', 'integer' => ':attribute deve ser inteiro.', 'date' => 'Informe uma data válida em :attribute.',
    'in' => 'O valor de :attribute é inválido.', 'exists' => 'O registro de :attribute não existe.', 'confirmed' => 'A confirmação de :attribute não confere.',
    'after_or_equal' => ':attribute deve ser uma data igual ou posterior a :date.', 'before' => ':attribute deve ser anterior a :date.',
    'uuid' => 'O identificador de :attribute é inválido.', 'boolean' => ':attribute deve ser verdadeiro ou falso.', 'image' => ':attribute deve ser uma imagem.', 'mimes' => ':attribute deve ser do tipo: :values.',
    'password' => ['letters' => 'A senha deve conter letras.', 'numbers' => 'A senha deve conter números.'],
    'attributes' => ['name' => 'nome', 'company' => 'empresa', 'password' => 'senha', 'email' => 'e-mail', 'amount' => 'valor', 'due_date' => 'vencimento', 'quantity' => 'quantidade', 'price' => 'preço', 'cost' => 'custo', 'description' => 'descrição', 'items' => 'itens', 'from' => 'data inicial', 'to' => 'data final'],
];
