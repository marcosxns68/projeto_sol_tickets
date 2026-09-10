<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
class PasswordResetController extends Controller {
 public function requestForm(){return view('auth.forgot-password');}
 public function sendLink(Request $request){$request->validate(['email'=>['required','email','ends_with:@sutoorii.com']]);$status=Password::sendResetLink($request->only('email'));return $status===Password::RESET_LINK_SENT?back()->with('success','Enviamos um link de redefinição para o seu e-mail.'):back()->withErrors(['email'=>'Não foi possível enviar o link. Confira o e-mail informado.']);}
 public function resetForm(Request $request,string $token){return view('auth.reset-password',['token'=>$token,'email'=>$request->query('email')]);}
 public function reset(Request $request){$data=$request->validate(['token'=>['required'],'email'=>['required','email','ends_with:@sutoorii.com'],'password'=>['required','confirmed',PasswordRule::min(10)->mixedCase()->numbers()]]);$status=Password::reset($data,function(User $user,string $password):void{$user->forceFill(['password'=>$password,'remember_token'=>Str::random(60)])->save();event(new PasswordReset($user));});return $status===Password::PASSWORD_RESET?redirect()->route('login')->with('success','Senha redefinida. Você já pode entrar.'):back()->withErrors(['email'=>'O link é inválido ou expirou. Solicite um novo.']);}
}
