<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\DepartmentMembershipNotification;
use App\Notifications\ResetPasswordPtBrNotification;
use App\Notifications\VerifyEmailPtBrNotification;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailLanguageAndDepartmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_email_is_explicitly_in_portuguese(): void
    {
        Notification::fake();
        $user = $this->user('Pessoa Verificação', 'verificacao@sutoorii.test');

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailPtBrNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertSame('Confirme seu e-mail — Sutoorii Tickets', $mail->subject);
            $this->assertSame('Olá, Pessoa Verificação!', $mail->greeting);
            $this->assertContains('Para concluir seu cadastro no Sutoorii Tickets, confirme seu endereço de e-mail.', $mail->introLines);
            $this->assertSame('Confirmar e-mail', $mail->actionText);
            $this->assertSame('Atenciosamente, Sutoorii Tickets', $mail->salutation);

            return true;
        });
    }

    public function test_password_reset_email_is_explicitly_in_portuguese(): void
    {
        Notification::fake();
        $user = $this->user('Pessoa Senha', 'senha@sutoorii.test');

        $user->sendPasswordResetNotification('token-de-teste');

        Notification::assertSentTo($user, ResetPasswordPtBrNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertSame('Redefinição de senha — Sutoorii Tickets', $mail->subject);
            $this->assertSame('Olá, Pessoa Senha!', $mail->greeting);
            $this->assertContains('Recebemos uma solicitação para redefinir a senha da sua conta no Sutoorii Tickets.', $mail->introLines);
            $this->assertSame('Redefinir senha', $mail->actionText);
            $this->assertSame('Atenciosamente, Sutoorii Tickets', $mail->salutation);

            return true;
        });
    }

    public function test_sender_name_is_always_sutoorii_tickets_even_when_another_name_is_supplied(): void
    {
        config(['mail.from.name' => 'Outro Nome']);

        $service = app(MailSettings::class);
        $service->save([
            'host' => 'mail.sutoorii.test',
            'port' => 465,
            'username' => 'tickets@sutoorii.test',
            'password' => 'SenhaTeste#123',
            'encryption' => 'ssl',
            'from_address' => 'tickets@sutoorii.test',
            'from_name' => 'Nome que deve ser ignorado',
        ]);
        $service->apply();

        $this->assertSame('Sutoorii Tickets', $service->values()['from_name']);
        $this->assertSame('Sutoorii Tickets', config('mail.from.name'));
    }

    public function test_sender_name_is_forced_even_without_database_mail_settings(): void
    {
        config(['mail.from.name' => 'Nome do ambiente']);

        app(MailSettings::class)->apply();

        $this->assertSame('Sutoorii Tickets', config('mail.from.name'));
    }

    public function test_user_receives_portuguese_summary_when_added_to_department(): void
    {
        Notification::fake();
        $admin = $this->departmentAdmin();
        $user = $this->user('Pessoa Departamento', 'departamento@sutoorii.test');
        $department = Department::create(['name' => 'Suporte', 'active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.departments.users.store', $department), [
                'user_id' => $user->id,
                'access_level' => 'edit',
            ])
            ->assertRedirect();

        Notification::assertSentTo($user, DepartmentMembershipNotification::class, function ($notification) use ($user, $department) {
            $mail = $notification->toMail($user);

            $this->assertSame('Você foi adicionado ao departamento Suporte', $mail->subject);
            $this->assertSame('Olá, Pessoa Departamento!', $mail->greeting);
            $this->assertContains('Você foi adicionado ao departamento Suporte no Sutoorii Tickets.', $mail->introLines);
            $this->assertContains('Nível de acesso: Editar tickets.', $mail->introLines);
            $this->assertContains('Esse nível inclui enviar, visualizar e editar tickets do departamento, respeitando as permissões gerais da sua conta.', $mail->introLines);
            $this->assertSame('Atenciosamente, Sutoorii Tickets', $mail->salutation);

            return true;
        });
    }

    private function departmentAdmin(): User
    {
        $permission = Permission::firstOrCreate(
            ['key' => 'departments.manage'],
            ['name' => 'Gerenciar departamentos', 'group' => 'departments']
        );
        $role = Role::create(['name' => 'Administrador Departamentos '.uniqid(), 'active' => true]);
        $role->permissions()->attach($permission->id);

        return User::create([
            'name' => 'Administrador',
            'email' => uniqid('admin-depto-').'@sutoorii.test',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'role_id' => $role->id,
            'active' => true,
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'active' => true,
        ]);
    }
}
