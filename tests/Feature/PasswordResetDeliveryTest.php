<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordPtBrNotification;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class PasswordResetDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Pessoa Recuperação',
            'email' => 'recuperacao@sutoorii.test',
            'password' => 'SenhaTeste123!',
            'active' => true,
        ]);
    }

    public function test_existing_user_receives_portuguese_reset_notification_with_saved_smtp_settings(): void
    {
        Notification::fake();
        $user = $this->user();

        app(MailSettings::class)->save([
            'host' => 'mail.example.test',
            'port' => 465,
            'username' => 'tickets@example.test',
            'password' => 'senha-smtp-ficticia',
            'encryption' => 'ssl',
            'from_address' => 'tickets@example.test',
        ]);

        // Uma configuração antiga não pode desviar e-mails para log/array nem
        // deixar MAIL_URL ignorar as configurações SMTP do painel.
        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.url' => 'smtp://mail-antigo.example.test:2525',
        ]);

        $this->post('/esqueci-senha', ['email' => ' RECUPERACAO@sutoorii.test '])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPasswordPtBrNotification::class, 1);
        $this->assertSame('smtp', config('mail.default'));
        $this->assertNull(config('mail.mailers.smtp.url'));
        $this->assertSame('mail.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_nonexistent_address_gets_same_generic_message_without_email(): void
    {
        Notification::fake();

        $this->post('/esqueci-senha', ['email' => 'inexistente@sutoorii.test'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_smtp_transport_error_does_not_display_credentials_or_claim_success(): void
    {
        $this->user();

        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new RuntimeException('credencial-smtp-nao-exibir'));

        $response = $this->post('/esqueci-senha', ['email' => 'recuperacao@sutoorii.test'])
            ->assertRedirect()
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('success');

        $this->assertStringNotContainsString(
            'credencial-smtp-nao-exibir',
            json_encode($response->headers->all())
        );
        $this->assertStringNotContainsString(
            'credencial-smtp-nao-exibir',
            json_encode(session('errors')->all())
        );
    }
}
