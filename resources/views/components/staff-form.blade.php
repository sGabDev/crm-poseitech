<form method="post" action="{{ url('/staff'.($person ? '/'.$person->id : '')) }}" class="form-grid">@csrf<label>Nome<input name="name" value="{{ $person->name ?? '' }}" required>
</label>
<label>E-mail<input type="email" name="email" value="{{ $person->email ?? '' }}" required>
</label>
<label>Senha {{ $person ? '(em branco mantém a atual)' : '' }}<input type="password" name="password" minlength="10" autocomplete="new-password" @required(!$person)>
</label>
<label>Perfil<select name="role">
<option value="staff" @selected(($person->role ?? '')==='staff')>Funcionário</option>
<option value="admin" @selected(($person->role ?? '')==='admin')>Administrador da empresa</option>
</select>
</label>
<label class="check">
<input type="checkbox" name="active" value="1" @checked($person->active ?? true)>Usuário ativo</label>
<div class="full permission-grid">@foreach(config('poseitech.modules') as $key=>$label)<div>
<strong>{{ $label }}</strong>
<div class="actions">@foreach(['read'=>'Visualizar','write'=>'Alterar'] as $action=>$title)<label class="check">
<input type="checkbox" name="permissions[]" value="{{ $key.'.'.$action }}" @checked(in_array($key.'.'.$action,$person->permissions ?? []))>{{ $title }}</label>@endforeach</div>
</div>@endforeach</div>
<button>Salvar usuário</button>
</form>
