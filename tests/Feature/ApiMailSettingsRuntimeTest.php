<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiMailSettingsRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_request_applies_database_mail_settings_before_integration_controller_runs(): void
    {
        $token = 'token-api-mail-runtime';
        $company = Company::create([
            'name' => 'Empresa teste de e-mail',
            'active' => true,
        ]);
        $integration = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => 'Integração teste de e-mail',
            'active' => true,
        ]);
        $integration->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        app(MailSettings::class)->save([
            'host' => 'smtp-banco.sutoorii.test',
            'port' => 465,
            'username' => 'tickets@sutoorii.test',
            'password' => 'segredo-runtime-api',
            'encryption' => 'ssl',
            'from_address' => 'tickets@sutoorii.test',
            'from_name' => 'Sutoorii Tickets',
        ]);

        config([
            'mail.mailers.smtp.host' => 'smtp-ambiente-inadequado.test',
            'mail.mailers.smtp.port' => 2525,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-External-User-Id' => '153',
            'X-External-User-Role' => 'user',
        ])->getJson('/api/v1/tickets')->assertOk();

        $this->assertSame('smtp-banco.sutoorii.test', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('tickets@sutoorii.test', config('mail.from.address'));
    }
}
