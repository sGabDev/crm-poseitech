<form method="post" action="{{ url('/password/change') }}" class="stack">@csrf
<label>Senha atual<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>Nova senha<input type="password" name="password" autocomplete="new-password" minlength="10" required></label>
<label>Confirme a nova senha<input type="password" name="password_confirmation" autocomplete="new-password" minlength="10" required></label>
<p class="muted">Use pelo menos 10 caracteres, incluindo letras e números.</p>
<button>Alterar minha senha</button>
</form>
