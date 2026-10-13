<?php

namespace App\Services;

use App\Http\Controllers\CatalogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogRegistration
{
    public static function profile(Request $r, int $company): ?array
    {
        $profile = $r->session()->get('catalog_profile_'.$company);

        return $profile && hash_equals($profile['visitor_hash'] ?? '', CatalogController::visitor($r, $company)) ? $profile : null;
    }

    public static function validCpf(string $value): bool
    {
        $cpf = preg_replace('/\D/', '', $value);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($digit = 9; $digit < 11; $digit++) {
            $sum = 0;
            for ($i = 0; $i < $digit; $i++) {
                $sum += (int) $cpf[$i] * ($digit + 1 - $i);
            }
            $check = (10 * $sum) % 11;
            if ((int) $cpf[$digit] !== ($check === 10 ? 0 : $check)) {
                return false;
            }
        }

        return true;
    }

    public function save(Request $r, Tenant $t): array
    {
        $d = $r->validate(['name' => 'required|string|min:2|max:160', 'document' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
            if (! self::validCpf($value)) {
                $fail('Informe um CPF válido.');
            }
        }], 'email' => 'required|email|max:180', 'phone' => 'required|string|max:30|regex:/^[+()\d\s-]{10,30}$/', 'birthday' => 'nullable|date|before:today', 'address' => 'required|string|min:5|max:255', 'district' => 'required|string|max:100', 'city' => 'required|string|max:100']);
        $d['document'] = preg_replace('/\D/', '', $d['document']);
        $id = DB::transaction(function () use ($d, $t) {
            $t->lock();
            $existing = $t->query('customers')->where('document_normalized', $d['document'])->whereNull('anonymized_at')->orderBy('id')->first();
            if ($existing) {
                // CPF matching never discloses or overwrites existing identity/contact fields.
                $t->update('customers', $existing->id, array_intersect_key($d, array_flip(['address', 'district', 'city'])));

                return $existing->id;
            }
            $t->limit('customers');

            return $t->insert('customers', $d + ['source' => 'Catálogo online']);
        }, 3);
        $profile = $d + ['customer_id' => $id, 'visitor_hash' => CatalogController::visitor($r, $t->id())];
        $r->session()->put('catalog_profile_'.$t->id(), $profile);

        return $profile;
    }
}
