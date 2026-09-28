<div class="actions separated">
<form method="post" action="{{ url('/staff/'.$person->id.'/action') }}">@csrf<input type="hidden" name="action" value="{{ $person->active?'deactivate':'activate' }}"><button class="secondary">{{ $person->active?'Desativar usuário':'Reativar usuário' }}</button></form>
<form method="post" action="{{ url('/staff/'.$person->id.'/action') }}" data-confirm="Excluir este usuário da equipe? O acesso será revogado e o histórico preservado.">@csrf<input type="hidden" name="action" value="delete"><button class="secondary">Excluir usuário</button></form>
</div>
