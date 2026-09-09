<?php
namespace App\Http\Controllers;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
class AuthController extends Controller {
 public function login(){return view('auth.login');} public function register(){return view('auth.register');}
 public function authenticate(Request $r){$data=$r->validate(['email'=>['required','email'],'password'=>['required']]); if(!Auth::attempt($data,$r->boolean('remember'))) return back()->withErrors(['email'=>'E-mail ou senha inválidos.'])->onlyInput('email'); $r->session()->regenerate(); if(!Auth::user()->active){Auth::logout(); return back()->withErrors(['email'=>'Esta conta está inativa.']);} return redirect()->intended(route('dashboard'));}
 public function store(Request $r){$data=$r->validate(['name'=>['required','string','max:120'],'email'=>['required','email','ends_with:@sutoorii.com','unique:users'],'password'=>['required','confirmed',Password::min(8)->mixedCase()->numbers()]]); $data['role_id']=Role::where('name','Usuário interno')->value('id'); $user=User::create($data); event(new Registered($user)); Auth::login($user); return redirect()->route('verification.notice');}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect()->route('login');}
}
