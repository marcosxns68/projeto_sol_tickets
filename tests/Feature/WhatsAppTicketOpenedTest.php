<?php

namespace Tests\Feature;

use App\Jobs\SendTicketOpenedWhatsApp;
use App\Models\Company;
use App\Models\ConnectedSystem;
use App\Models\Setting;
use App\Models\Status;
use App\Models\Ticket;
use App\Services\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTicketOpenedTest extends TestCase
{
    use RefreshDatabase;

    private function initialTicketStatus(): Status
    {
        return Status::create(['name'=>'Novo','system_key'=>'new','category'=>'open','color'=>'#6D28D9','position'=>0,'active'=>true]);
    }

    private function connected(): void
    {
        app(WhatsAppConnection::class)->save([
            'base_url'=>'https://evolution.example.test',
            'instance'=>'sutoorii-tickets',
            'api_key'=>'chave-teste'
        ]);
    }

    public function test_opening_an_integration_ticket_queues_exactly_one_whatsapp_confirmation_for_its_requester(): void
    {
        Bus::fake();
        $this->initialTicketStatus();
        $company = Company::create(['name'=>'Estúdio França','active'=>true]);
        $integration = ConnectedSystem::create(['company_id'=>$company->id,'name'=>'Estúdio França','active'=>true]);
        $integration->forceFill(['api_token_hash'=>hash('sha256','token-franca')])->save();

        $headers = ['Authorization'=>'Bearer token-franca','X-External-User-Id'=>'153','X-External-User-Role'=>'user'];
        $payload = [
            'external_reference'=>'pedido-100',
            'requester_name'=>'João',
            'requester_email'=>'joao@example.test',
            'requester_whatsapp'=>'(15) 99999-8888',
            'title'=>'Falha no sistema',
            'description'=>'Descrição',
            'priority'=>'normal',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/tickets',$payload);
        $first->assertCreated();
        $this->assertSame('5515999998888',Ticket::firstOrFail()->requester_whatsapp);
        Bus::assertDispatched(SendTicketOpenedWhatsApp::class,1);
        $this->withHeaders($headers)->postJson('/api/v1/tickets',$payload)->assertOk();
        Bus::assertDispatched(SendTicketOpenedWhatsApp::class,1);
    }

    // Valida o texto enviado e impede o reenvio ao executar a tarefa novamente.
    public function test_confirmation_has_exact_text_and_is_not_sent_twice_for_same_ticket(): void
    {
        $this->connected();
        Http::fake([
            'evolution.example.test/message/sendText/sutoorii-tickets'=>Http::response(['key'=>['id'=>'message-1']],201),
        ]);
        $ticket = Ticket::create([
            'number'=>'26091234','origin'=>'integration','title'=>'Falha','description'=>'Descrição','priority'=>'normal',
            'status_id'=>$this->initialTicketStatus()->id,'requester_name'=>'João',
            'requester_whatsapp'=>'5515999998888',
        ]);
        $job = new SendTicketOpenedWhatsApp($ticket->id);
        $job->handle(app(WhatsAppConnection::class));
        $job->handle(app(WhatsAppConnection::class));

        Http::assertSentCount(1);
        Http::assertSent(fn($request)=>$request->url()==='https://evolution.example.test/message/sendText/sutoorii-tickets'
            && $request->hasHeader('apikey','chave-teste')
            && $request['number']==='5515999998888'
            && $request['text']==="Sutoorii Tickets\n\nSeu ticket de número 26091234 foi aberto com sucesso.");
        $this->assertNotNull($ticket->fresh()->whatsapp_opened_sent_at);
    }

    public function test_no_message_is_queued_without_requester_whatsapp_and_invalid_number_is_rejected(): void
    {
        $this->initialTicketStatus();
        $company=Company::create(['name'=>'Empresa','active'=>true]);
        $integration=ConnectedSystem::create(['company_id'=>$company->id,'name'=>'Sistema','active'=>true]);
        $integration->forceFill(['api_token_hash'=>hash('sha256','token')])->save();
        $headers=['Authorization'=>'Bearer token','X-External-User-Id'=>'10','X-External-User-Role'=>'user'];
        $payload=['requester_name'=>'João','requester_email'=>'joao@example.test','title'=>'Erro','description'=>'Descrição'];
        Bus::fake();
        $this->withHeaders($headers)->postJson('/api/v1/tickets',$payload)->assertCreated();
        Bus::assertNotDispatched(SendTicketOpenedWhatsApp::class);
        $this->withHeaders($headers)->postJson('/api/v1/tickets',array_merge($payload,['requester_whatsapp'=>'15abc']))
            ->assertUnprocessable()->assertJsonValidationErrors('requester_whatsapp');
    }
}
