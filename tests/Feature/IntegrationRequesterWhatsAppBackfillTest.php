<?php

namespace Tests\Feature;

use App\Jobs\SendTicketWhatsAppAutomation;
use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Setting;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\IntegrationRequesterWhatsAppSync;
use App\Services\TicketNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationRequesterWhatsAppBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function system(string $name = 'Estúdio França'): ConnectedSystem
    {
        $company = Company::create(['name' => $name, 'active' => true]);
        $system = ConnectedSystem::create([
            'company_id' => $company->id,
            'name' => $name,
            'base_url' => 'https://93.184.216.34',
            'active' => true,
        ]);
        $system->issueWebhookSecret();

        return $system;
    }

    private function ticket(ConnectedSystem $system, string $externalId, ?string $whatsapp = null): Ticket
    {
        $status = Status::firstOrCreate(
            ['system_key' => 'new'],
            ['name' => 'Novo', 'category' => 'open', 'color' => '#6D28D9', 'position' => 0, 'active' => true]
        );

        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'integration',
            'title' => 'Chamado antigo',
            'description' => 'Problema descrito pelo cliente.',
            'priority' => 'normal',
            'status_id' => $status->id,
            'system_id' => $system->id,
            'external_requester_id' => $externalId,
            'requester_whatsapp' => $whatsapp,
        ]);
    }

    public function test_fills_all_legacy_tickets_of_exact_user_only_and_preserves_existing_numbers(): void
    {
        $system = $this->system();
        $anotherSystem = $this->system('Outro sistema');
        $oldOne = $this->ticket($system, 'estudio-franca-17');
        $oldTwo = $this->ticket($system, 'estudio-franca-17');
        $alreadySet = $this->ticket($system, 'estudio-franca-17', '5511988887777');
        $anotherUser = $this->ticket($system, 'estudio-franca-18');
        $anotherTenant = $this->ticket($anotherSystem, 'estudio-franca-17');

        Http::fake(['*' => Http::response(['data' => [[
            'id' => 'estudio-franca-17',
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'whatsapp' => '(15) 99999-8888',
        ]]], 200)]);

        $this->assertSame(2, app(IntegrationRequesterWhatsAppSync::class)->syncRequester($system, 'estudio-franca-17'));
        $this->assertSame('5515999998888', $oldOne->fresh()->requester_whatsapp);
        $this->assertSame('5515999998888', $oldTwo->fresh()->requester_whatsapp);
        $this->assertNotSame('5515999998888', $oldOne->fresh()->getRawOriginal('requester_whatsapp'));
        $this->assertSame('5511988887777', $alreadySet->fresh()->requester_whatsapp);
        $this->assertNull($anotherUser->fresh()->requester_whatsapp);
        $this->assertNull($anotherTenant->fresh()->requester_whatsapp);
        $this->assertSame(0, app(IntegrationRequesterWhatsAppSync::class)->syncRequester($system, 'estudio-franca-17'));
        Http::assertSentCount(1);
    }

    public function test_rejects_directory_mismatch_invalid_phone_and_non_matching_external_ids(): void
    {
        $system = $this->system();
        $old = $this->ticket($system, 'estudio-franca-19');
        Http::fake(['*' => Http::response(['data' => [[
            'id' => 'estudio-franca-20',
            'name' => 'Outra pessoa',
            'whatsapp' => '5515999998888',
        ]]], 200)]);
        $this->assertSame(0, app(IntegrationRequesterWhatsAppSync::class)->syncRequester($system, 'estudio-franca-19'));
        $this->assertNull($old->fresh()->requester_whatsapp);

        Http::fake(['*' => Http::response(['data' => [[
            'id' => 'estudio-franca-19',
            'name' => 'Cliente',
            'whatsapp' => 'numero-invalido',
        ]]], 200)]);
        $this->assertSame(0, app(IntegrationRequesterWhatsAppSync::class)->syncRequester($system, 'estudio-franca-19'));
        $this->assertNull($old->fresh()->requester_whatsapp);
        $this->assertSame(0, app(IntegrationRequesterWhatsAppSync::class)->syncRequester($system, 'estudio-franca-19-20'));
    }

    public function test_command_backfills_old_tickets_without_sending_retroactive_notifications(): void
    {
        Queue::fake();
        $system = $this->system();
        $old = $this->ticket($system, 'estudio-franca-21');
        Http::fake(['*' => Http::response(['data' => [[
            'id' => 'estudio-franca-21',
            'name' => 'Cliente',
            'whatsapp' => '5515999998888',
        ]]], 200)]);

        $this->artisan('tickets:backfill-requester-whatsapp', ['--limit' => 5])
            ->expectsOutputToContain('tickets_preenchidos=1')
            ->assertSuccessful();

        $this->assertSame('5515999998888', $old->fresh()->requester_whatsapp);
        Queue::assertNothingPushed();
        $this->assertSame(0, (int) Setting::getValue('backfill.estudio_franca_whatsapp.cursor', -1));
    }

    public function test_support_reply_can_recover_phone_before_dispatching_whatsapp_on_legacy_ticket(): void
    {
        Queue::fake();
        $system = $this->system();
        $ticket = $this->ticket($system, 'estudio-franca-22');
        Setting::setValue('whatsapp.automation.comment.enabled', true);
        $support = User::create([
            'name' => 'Suporte',
            'email' => 'suporte-teste@sutoorii.com',
            'email_verified_at' => now(),
            'password' => 'SenhaTeste123!',
            'active' => true,
        ]);
        Http::fake(['*' => Http::response(['data' => [[
            'id' => 'estudio-franca-22',
            'name' => 'Cliente',
            'whatsapp' => '5515999998888',
        ]]], 200)]);

        app(TicketNotifier::class)->publicCommentWhatsApp($ticket, $support, 123);

        $this->assertSame('5515999998888', $ticket->fresh()->requester_whatsapp);
        Queue::assertPushed(SendTicketWhatsAppAutomation::class, 1);
    }
}
