<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $r->merge(['email' => Str::lower(trim((string) $r->input('email')))]);
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.hash('sha256', Str::lower($data['email']).'|'.$r->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Aguarde um minuto.']);
        }
        if (! Auth::attempt($data + ['active' => true], $r->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();

        return redirect()->intended($r->user()->role === 'super' ? '/admin' : '/dashboard');
    }

    public function register(Request $r)
    {
        abort_if(DB::table('platform_settings')->where('key', 'registration_enabled')->value('value') === '0', 403, 'Cadastros públicos indisponíveis. Contate a PoseiTech.');
        $r->merge(['email' => Str::lower(trim((string) $r->input('email')))]);
        $d = $r->validate(['company' => 'required|string|max:160', 'name' => 'required|string|max:160', 'email' => 'required|email|max:180|unique:users',
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $user = DB::transaction(function () use ($d) {
            $plan = Plan::orderBy('price')->firstOrFail();
            $company = Company::create(['plan_id' => $plan->id, 'name' => $d['company'], 'slug' => Str::slug($d['company']).'-'.Str::lower(Str::random(8)),
                'modules' => array_values(array_intersect(config('poseitech.defaults'), $plan->modules)), 'subscription_until' => now()->addDays((int) (DB::table('platform_settings')->where('key', 'trial_days')->value('value') ?? 14))]);

            return User::create(['company_id' => $company->id, 'role' => 'admin', 'name' => $d['name'], 'email' => Str::lower($d['email']), 'password' => $d['password']]);
        });
        Auth::login($user);
        $r->session()->regenerate();

        return redirect('/dashboard');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('success', 'Se o e-mail estiver cadastrado, você receberá as instruções de recuperação.');
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Link inválido ou expirado. Solicite outro.']);
        }

        return redirect('/login')->with('success', 'Senha atualizada.');
    }
}
