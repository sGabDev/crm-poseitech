@can('manage-company')<section class="card no-print">
<h2>Resumo por e-mail</h2>
<form method="post" action="{{ url('/reports/email') }}">@csrf @foreach(request()->only('period','from','to') as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach<button class="secondary">Enviar resumo para {{ auth()->user()->email }}</button>
</form>
</section>@endcan
