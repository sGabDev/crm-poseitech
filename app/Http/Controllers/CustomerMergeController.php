<?php

namespace App\Http\Controllers;

use App\Services\CustomerMerge;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerMergeController extends Controller
{
    public function __construct(private Tenant $t, private CustomerMerge $merger) {}

    public function index(Request $r, int $id)
    {
        $this->merger->authorize();
        $r->validate(['duplicate_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100']);
        $keep = $this->t->find('customers', $id);
        abort_if($keep->anonymized_at, 404);
        if ($r->filled('duplicate_id')) {
            return view('customer-merge', $this->merger->preview($id, (int) $r->input('duplicate_id')));
        }
        $candidates = $this->t->query('customers')->whereNull('anonymized_at')->where('id', '!=', $id);
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $candidates->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('document', 'like', $term));
        }

        return view('customer-merge', ['keep' => $keep, 'candidates' => $candidates->orderBy('name')->paginate(15)->withQueryString()]);
    }

    public function store(Request $r, int $id)
    {
        $this->merger->authorize();
        $d = $r->validate(['duplicate_id' => 'required|integer|min:1', 'version' => 'required|string|size:64', 'confirmation' => 'accepted', 'choices' => ['nullable', 'array:'.implode(',', array_keys(CustomerMerge::FIELDS))], 'choices.*' => ['required', Rule::in(['keep', 'duplicate'])]]);
        $this->merger->merge($id, (int) $d['duplicate_id'], $d['version'], $d['choices'] ?? []);

        return redirect('/customers/'.$id)->with('success', 'Clientes unidos. Histórico, dívidas, saldos e atendimentos reunidos no cadastro escolhido. Gere um novo link do portal, se necessário.');
    }
}
