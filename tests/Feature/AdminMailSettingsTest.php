<?php

namespace Tests\Feature;

use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_settings_are_saved_with_encrypted_password_and_applied_at_runtime(): void
    {
        $service = app(MailSettings::class);

        $service->save([
            'host' => 'mail.example.test',
            'port' => 465,
            'username' => 'tickets@example.test',
            'password' => 'SenhaTeste#123',
            'encryption' => 'ssl',
            'from_address' => 'tickets@example.test',
            'from_name' => 'Tickets Teste',
        ]);

        $storedPassword = DB::table('settings')->where('key', 'mail.password_encrypted')->value('value');

        $this->assertNotNull($storedPassword);
        $this->assertNotSame('SenhaTeste#123', $storedPassword);
        $this->assertSame('SenhaTeste#123', Crypt::decryptString($storedPassword));
        $this->assertTrue($service->hasStoredPassword());

        $service->apply();

        $this->assertSame('mail.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('tickets@example.test', config('mail.mailers.smtp.username'));
        $this->assertSame('SenhaTeste#123', config('mail.mailers.smtp.password'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('tickets@example.test', config('mail.from.address'));
        $this->assertSame('Sutoorii Tickets', config('mail.from.name'));
    }

    public function test_mail_settings_keep_environment_configuration_when_database_has_no_mail_values(): void
    {
        config([
            'mail.mailers.smtp.host' => 'env-mail.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'env-user@example.test',
            'mail.mailers.smtp.password' => 'env-password',
            'mail.mailers.smtp.scheme' => null,
            'mail.from.address' => 'env-from@example.test',
            'mail.from.name' => 'Env Mail',
        ]);

        app(MailSettings::class)->apply();

        $this->assertSame('env-mail.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('env-password', config('mail.mailers.smtp.password'));
        $this->assertSame('env-from@example.test', config('mail.from.address'));
        $this->assertSame('Sutoorii Tickets', config('mail.from.name'));
    }
}
