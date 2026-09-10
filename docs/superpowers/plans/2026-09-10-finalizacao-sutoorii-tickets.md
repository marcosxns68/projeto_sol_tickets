# Sutoorii Tickets Finalization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finalizar o Sutoorii Tickets como help desk operacional completo, preservando a base Laravel atual e implementando caixas, gestão do ticket, histórico, permissões individuais, administração, integrações, notificações, recorrência, anexos e ciclo de vida.

**Architecture:** A base Laravel 12 existente será mantida. A implementação será incremental, com migrations aditivas para preservar a instalação atual, autorização centralizada por permissões efetivas, controladores pequenos por responsabilidade e serviços para histórico/auditoria/webhooks. A interface Blade atual será reorganizada sem introduzir framework frontend novo.

**Tech Stack:** PHP 8.2, Laravel 12, Blade, MySQL/MariaDB em produção, SQLite em memória nos testes, PHPUnit 11, CSS/JavaScript nativos, GitHub Actions + FTPS.

**Spec:** `docs/superpowers/specs/2026-09-10-sutoorii-tickets-finalizacao-design.md`

## Global Constraints

- Reaproveitar banco, autenticação, PWA, modelos e deploy existentes sempre que corretos.
- Não apagar nem recriar dados atuais.
- Departamento e responsável são independentes.
- Minha Caixa contém responsável, colaborador ou seguidor e exclui por padrão Resolvido, Fechado e Cancelado.
- Caixa de departamento contém os tickets do departamento e também exclui por padrão Resolvido, Fechado e Cancelado.
- Criador não mantém visibilidade após o ticket sair de seu departamento, salvo vínculo como responsável, colaborador, seguidor ou permissão global.
- Encaminhamento remove responsável, muda para status de sistema `forwarded` e registra histórico.
- Assumir ticket encaminhado muda automaticamente para status de sistema `in_progress`.
- Ticket já atribuído só pode ser reatribuído por quem possuir `tickets.reassign`.
- Histórico operacional fica no ticket; auditoria administrativa fica em módulo separado.
- Cargo fornece permissões padrão; override individual `allow`/`deny` prevalece sobre o cargo; ausência de override significa herança.
- Nenhuma função administrativa depende do nome do cargo.
- Comentário público de ticket integrado pode gerar webhook; nota interna nunca sai do Sutoorii Tickets.
- Vídeos não são aceitos como anexo.
- Não deve existir deploy que dependa de execução manual de SQL.

---

## File Structure

Arquivos principais a criar/modificar ao longo do plano:

- `database/migrations/2026_09_10_000002_finalize_ticket_core.php`: schema aditivo de permissões individuais, histórico do ticket e campos estáveis de status/checklist.
- `app/Models/UserPermissionOverride.php`, `app/Models/TicketEvent.php`, `app/Models/Attachment.php`, `app/Models/AuditLog.php`, `app/Models/Setting.php`, `app/Models/Recurrence.php`: modelos ausentes.
- `app/Services/TicketEventRecorder.php`, `app/Services/AuditLogger.php`, `app/Services/IntegrationWebhookService.php`: efeitos transversais isolados.
- `app/Http/Middleware/RequirePermission.php`, `app/Http/Middleware/AuthenticateIntegration.php`: autorização web/API.
- `app/Http/Controllers/TicketBoxController.php`: Minha Caixa e caixa de departamento.
- `app/Http/Controllers/TicketController.php`: criação, exibição e edição de campos básicos.
- `app/Http/Controllers/TicketAssignmentController.php`: assumir e reatribuir.
- `app/Http/Controllers/TicketRoutingController.php`: encaminhar entre departamentos.
- `app/Http/Controllers/TicketParticipantController.php`: colaboradores e seguidores.
- `app/Http/Controllers/TicketCommentController.php`: comentários públicos e notas internas.
- `app/Http/Controllers/TicketChecklistController.php`: checklist.
- `app/Http/Controllers/TicketAttachmentController.php`: upload/download/remoção de anexos.
- `app/Http/Controllers/TicketLifecycleController.php`: solicitar conclusão, resolver, fechar, cancelar, lixeira/restauração.
- `app/Http/Controllers/Admin/*`: usuários, cargos, departamentos, status, etiquetas, integrações, configurações e auditoria.
- `app/Http/Controllers/Api/V1/IntegrationTicketController.php`: API de sistemas conectados.
- `resources/views/boxes/*`, `resources/views/tickets/show.blade.php`, `resources/views/admin/*`, `resources/views/layouts/app.blade.php`, `public/css/app.css`: interface final.
- `tests/Feature/*`: testes de regressão e regras de negócio.
- `.github/workflows/deploy.yml`, `deploy/extract.php`: testes antes do deploy e migrations automáticas depois da extração.

---

### Task 1: Base de testes e deploy determinístico

**Files:**
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/Feature/SmokeTest.php`
- Modify: `.github/workflows/deploy.yml`
- Modify: `deploy/extract.php`
- Create/generated: `composer.lock`

**Interfaces:**
- Produces: comando `php artisan test` funcional em SQLite em memória.
- Produces: deploy que executa `migrate --force` após extrair a versão.

- [ ] **Step 1: Criar o teste de fumaça**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_health_endpoint_is_available(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'service' => 'Sutoorii Tickets']);
    }
}
```

- [ ] **Step 2: Configurar PHPUnit para SQLite em memória**

`phpunit.xml` deve definir `APP_ENV=testing`, `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync` e `MAIL_MAILER=array`.

- [ ] **Step 3: Criar `tests/TestCase.php`**

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
}
```

- [ ] **Step 4: Gerar e versionar `composer.lock`**

Run: `composer update --no-interaction --prefer-dist`

Expected: `composer.lock` criado e `composer install` passa a instalar versões determinísticas.

- [ ] **Step 5: Rodar o teste e confirmar PASS**

Run: `php artisan test tests/Feature/SmokeTest.php`

Expected: 1 teste passando.

- [ ] **Step 6: Fazer o workflow testar antes de empacotar**

Adicionar `pdo_sqlite` às extensões e uma etapa antes de `Compactar versão de produção`:

```yaml
      - name: Executar testes
        run: php artisan test
```

- [ ] **Step 7: Executar migrations automaticamente no deploy**

Depois de extrair o ZIP em `deploy/extract.php`, inicializar o Laravel e chamar:

```php
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
```

Se `Artisan::call` retornar código diferente de `0`, responder HTTP 500 e não declarar sucesso.

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml tests composer.lock .github/workflows/deploy.yml deploy/extract.php
git commit -m "chore: adicionar testes e migrations automaticas no deploy"
```

---

### Task 2: Schema aditivo, status estáveis e permissões efetivas

**Files:**
- Create: `database/migrations/2026_09_10_000002_finalize_ticket_core.php`
- Create: `app/Models/UserPermissionOverride.php`
- Create: `app/Models/TicketEvent.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/Role.php`
- Modify: `app/Models/Status.php`
- Modify: `app/Models/Ticket.php`
- Modify: `app/Models/ChecklistItem.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `app/Http/Middleware/RequirePermission.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Feature/PermissionTest.php`

**Interfaces:**
- Produces: `User::hasPermission(string $key): bool` com override individual.
- Produces: `Status::system(string $key): ?Status`.
- Produces: middleware `permission:<key>`.
- Produces: relação `Ticket::events()`.

- [ ] **Step 1: Escrever testes de precedência de permissão**

Cobrir três casos em `PermissionTest`: cargo permite + override nega = false; cargo nega + override permite = true; sem override = valor do cargo.

```php
$this->assertFalse($userWithExplicitDeny->hasPermission('tickets.reassign'));
$this->assertTrue($userWithExplicitAllow->hasPermission('tickets.reassign'));
$this->assertTrue($userUsingRole->hasPermission('tickets.create'));
```

- [ ] **Step 2: Rodar os testes e confirmar FAIL**

Run: `php artisan test tests/Feature/PermissionTest.php`

Expected: falha porque a tabela/modelo de override ainda não existe.

- [ ] **Step 3: Criar migration aditiva**

A migration deve:

```php
Schema::table('roles', fn (Blueprint $t) => $t->boolean('active')->default(true)->after('protected'));
Schema::table('statuses', fn (Blueprint $t) => $t->string('system_key')->nullable()->unique()->after('name'));
Schema::table('checklist_items', fn (Blueprint $t) => $t->boolean('required')->default(true)->after('text'));

Schema::create('user_permission_overrides', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
    $t->enum('effect', ['allow', 'deny']);
    $t->timestamps();
    $t->unique(['user_id', 'permission_id']);
});

Schema::create('ticket_events', function (Blueprint $t) {
    $t->id();
    $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
    $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
    $t->string('event');
    $t->json('data')->nullable();
    $t->timestamps();
    $t->index(['ticket_id', 'created_at']);
});
```

A mesma migration deve atribuir `system_key` aos status existentes: `new`, `forwarded`, `in_progress`, `resolved`, `closed`, `cancelled`; criar `Encaminhado` se não existir; e inserir as novas permissões granulares sem remover permissões atuais.

- [ ] **Step 4: Implementar precedência no `User`**

```php
public function permissionOverrides()
{
    return $this->hasMany(UserPermissionOverride::class);
}

public function hasPermission(string $key): bool
{
    $permission = Permission::where('key', $key)->first();
    if (!$permission) return false;

    $override = $this->permissionOverrides()->where('permission_id', $permission->id)->value('effect');
    if ($override === 'allow') return true;
    if ($override === 'deny') return false;

    return $this->role?->permissions()->whereKey($permission->id)->exists() ?? false;
}
```

- [ ] **Step 5: Registrar middleware**

`RequirePermission` deve abortar 403 quando `!$request->user()?->hasPermission($permission)` e `bootstrap/app.php` deve registrar alias `permission`.

- [ ] **Step 6: Rodar testes**

Run: `php artisan test tests/Feature/PermissionTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database app bootstrap tests/Feature/PermissionTest.php
git commit -m "feat: adicionar permissoes individuais e historico de tickets"
```

---

### Task 3: Minha Caixa, caixas de departamento e filtros reais

**Files:**
- Create: `app/Http/Controllers/TicketBoxController.php`
- Modify: `app/Models/Ticket.php`
- Modify: `routes/web.php`
- Create: `resources/views/boxes/index.blade.php`
- Modify: `resources/views/dashboard.blade.php`
- Create: `tests/Feature/TicketVisibilityTest.php`
- Create: `tests/Feature/TicketBoxTest.php`

**Interfaces:**
- Produces: `Ticket::scopeVisibleTo(Builder $query, User $user)` corrigido.
- Produces: `Ticket::scopeMyBox(Builder $query, User $user)`.
- Produces: `Ticket::scopeActiveForBox(Builder $query)`.
- Produces: GET `/minha-caixa` e GET `/departamentos/{department}/tickets`.

- [ ] **Step 1: Escrever testes de visibilidade**

Casos obrigatórios:

```php
// criador sem vínculo e ticket em outro departamento não enxerga
$this->actingAs($creator)->get(route('tickets.show', $ticket))->assertForbidden();

// membro do departamento com tickets.view_department enxerga
$this->actingAs($departmentUser)->get(route('tickets.show', $ticket))->assertOk();

// colaborador e seguidor enxergam mesmo fora do departamento
$this->actingAs($collaborator)->get(route('tickets.show', $ticket))->assertOk();
$this->actingAs($follower)->get(route('tickets.show', $ticket))->assertOk();
```

- [ ] **Step 2: Escrever testes de Minha Caixa**

Responsável, colaborador e seguidor devem aparecer uma única vez; ticket apenas criado não aparece; status com `system_key` `resolved`, `closed` ou `cancelled` não aparece sem filtro e aparece com `status=closed`/equivalente.

- [ ] **Step 3: Implementar scopes**

`visibleTo` deve permitir `tickets.view_all`, responsável, participante ou ticket do próprio departamento quando houver `tickets.view_department`. Remover a regra atual que concede acesso permanente ao `creator_id`.

`myBox` deve usar somente `assignee_id` ou `participants.user_id`.

- [ ] **Step 4: Implementar filtros do controller**

Aceitar `relation=assignee|collaborator|follower`, `status`, `priority`, `unassigned=1`, `overdue=1` e `q`. Sem status explícito, aplicar `activeForBox()`.

- [ ] **Step 5: Criar view única de caixas**

Usar links reais com query string, preservar filtros e mostrar departamento, responsável, prazo, prioridade e status. `dashboard.blade.php` passa a redirecionar/usar a Minha Caixa em vez dos botões decorativos atuais.

- [ ] **Step 6: Rodar testes**

Run: `php artisan test tests/Feature/TicketVisibilityTest.php tests/Feature/TicketBoxTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TicketBoxController.php app/Models/Ticket.php routes/web.php resources/views tests/Feature/TicketVisibilityTest.php tests/Feature/TicketBoxTest.php
git commit -m "feat: implementar minha caixa e caixas de departamento"
```

---

### Task 4: Operações de responsável, encaminhamento e participantes

**Files:**
- Create: `app/Services/TicketEventRecorder.php`
- Create: `app/Http/Controllers/TicketAssignmentController.php`
- Create: `app/Http/Controllers/TicketRoutingController.php`
- Create: `app/Http/Controllers/TicketParticipantController.php`
- Modify: `app/Http/Controllers/TicketController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/TicketAssignmentTest.php`
- Create: `tests/Feature/TicketRoutingTest.php`
- Create: `tests/Feature/TicketParticipantTest.php`

**Interfaces:**
- Produces: `TicketEventRecorder::record(Ticket $ticket, ?User $actor, string $event, array $data = []): TicketEvent`.
- Produces: POST `/tickets/{ticket}/assumir`.
- Produces: PATCH `/tickets/{ticket}/responsavel`.
- Produces: POST `/tickets/{ticket}/encaminhar`.
- Produces: POST/DELETE de colaboradores e seguidores.

- [ ] **Step 1: Escrever testes para assumir e reatribuir**

Garantir que membro do departamento com `tickets.assume` assume ticket sem responsável; membro de outro departamento recebe 403; ticket já atribuído exige `tickets.reassign`; assumir ticket `forwarded` altera para `in_progress`.

- [ ] **Step 2: Escrever testes de encaminhamento**

```php
$this->assertSame($targetDepartment->id, $ticket->fresh()->department_id);
$this->assertNull($ticket->fresh()->assignee_id);
$this->assertSame('forwarded', $ticket->fresh()->status->system_key);
$this->assertDatabaseHas('ticket_events', ['ticket_id' => $ticket->id, 'event' => 'forwarded']);
```

- [ ] **Step 3: Escrever testes de participantes**

Adicionar/remover colaborador e seguidor exige `tickets.manage_participants`; vínculo deve refletir na Minha Caixa imediatamente.

- [ ] **Step 4: Implementar `TicketEventRecorder`**

```php
public function record(Ticket $ticket, ?User $actor, string $event, array $data = []): TicketEvent
{
    return $ticket->events()->create([
        'actor_id' => $actor?->id,
        'event' => $event,
        'data' => $data,
    ]);
}
```

- [ ] **Step 5: Implementar assumir/reatribuir**

`assume` valida departamento, ticket sem responsável e permissão; `reassign` valida permissão e usuário ativo. Registrar `assumed` ou `reassigned` com IDs e nomes antigo/novo.

- [ ] **Step 6: Implementar encaminhamento**

Executar em transação: registrar departamento/responsável antigos, atualizar `department_id`, zerar `assignee_id`, atribuir status `Status::system('forwarded')` e registrar evento com motivo opcional.

- [ ] **Step 7: Implementar participantes**

Usar `syncWithoutDetaching` para inclusão e `detach` para remoção, com `type=collaborator|follower` e flags de notificação. Registrar eventos `participant_added`, `participant_changed`, `participant_removed`.

- [ ] **Step 8: Rodar testes**

Run: `php artisan test tests/Feature/TicketAssignmentTest.php tests/Feature/TicketRoutingTest.php tests/Feature/TicketParticipantTest.php`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app routes tests/Feature/TicketAssignmentTest.php tests/Feature/TicketRoutingTest.php tests/Feature/TicketParticipantTest.php
git commit -m "feat: implementar atribuicao encaminhamento e participantes"
```

---

### Task 5: Edição do ticket, comentários e linha do tempo

**Files:**
- Modify: `app/Http/Controllers/TicketController.php`
- Create: `app/Http/Controllers/TicketCommentController.php`
- Modify: `app/Models/Comment.php`
- Modify: `app/Models/Ticket.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tickets/show.blade.php`
- Create: `tests/Feature/TicketUpdateTest.php`
- Create: `tests/Feature/TicketCommentTest.php`

**Interfaces:**
- Produces: PATCH `/tickets/{ticket}` funcional.
- Produces: POST `/tickets/{ticket}/comentarios` com `visibility=public|internal`.
- Produces: coleção `timeline` ordenada por `created_at` combinando eventos e comentários.

- [ ] **Step 1: Testar edição de campos**

Cobrir título/descrição, status, prioridade e prazo com permissões correspondentes. Cada alteração deve criar evento contendo valor antigo e novo.

- [ ] **Step 2: Testar comentários**

Colaborador com `tickets.comment` cria comentário; seguidor recebe 403; nota interna exige `tickets.internal_note`; comentário público e nota interna ficam distintos no banco.

- [ ] **Step 3: Implementar update por campo e permissão**

Não usar uma permissão genérica para tudo. Antes de alterar cada grupo, verificar `tickets.edit`, `tickets.change_status`, `tickets.change_priority` e `tickets.change_due_date` conforme o payload recebido.

- [ ] **Step 4: Implementar comentários**

```php
$comment = $ticket->comments()->create([
    'user_id' => $request->user()->id,
    'visibility' => $data['visibility'],
    'body' => $data['body'],
    'source' => 'web',
]);
```

Registrar `comment_created` no histórico apenas como metadado resumido, sem duplicar o corpo na tabela de eventos.

- [ ] **Step 5: Montar timeline**

No `show`, carregar `events.actor`, `comments.user` e mesclar itens com chave `kind=event|comment`, ordenando cronologicamente do mais antigo para o mais novo.

- [ ] **Step 6: Reestruturar a tela do ticket**

Cabeçalho com status/prioridade; painel lateral com Departamento, Responsável, prazo, participantes e etiquetas; centro com descrição, composer de comentário/nota interna e timeline. Ações só aparecem quando autorizadas.

- [ ] **Step 7: Rodar testes**

Run: `php artisan test tests/Feature/TicketUpdateTest.php tests/Feature/TicketCommentTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app routes resources/views/tickets/show.blade.php tests/Feature/TicketUpdateTest.php tests/Feature/TicketCommentTest.php
git commit -m "feat: completar edicao comentarios e timeline do ticket"
```

---

### Task 6: Checklist e anexos privados

**Files:**
- Create: `app/Models/Attachment.php`
- Create: `app/Http/Controllers/TicketChecklistController.php`
- Create: `app/Http/Controllers/TicketAttachmentController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tickets/show.blade.php`
- Create: `tests/Feature/TicketChecklistTest.php`
- Create: `tests/Feature/TicketAttachmentTest.php`

**Interfaces:**
- Produces: CRUD de checklist e alternância de conclusão.
- Produces: upload privado, download autorizado e remoção lógica de anexos.

- [ ] **Step 1: Testar checklist**

Adicionar item, ordenar, concluir/desmarcar e remover exige `tickets.manage_checklist`; concluir item registra `completed_by`/`completed_at`; cada ação registra evento.

- [ ] **Step 2: Testar anexos**

Usar `Storage::fake('local')`; PDF/imagem devem subir; `video/mp4` deve falhar; usuário sem visibilidade do ticket não pode baixar; remoção define `deleted_at` e registra evento.

- [ ] **Step 3: Implementar checklist**

Ao marcar concluído:

```php
$item->update([
    'completed' => true,
    'completed_by' => $request->user()->id,
    'completed_at' => now(),
]);
```

Ao desmarcar, zerar `completed_by` e `completed_at`.

- [ ] **Step 4: Implementar upload**

Validar máximo a partir de `Setting::get('attachments.max_mb', 20)`, negar MIME iniciado por `video/`, salvar em `storage/app/private/tickets/{ticket_id}` e preencher `expires_at` conforme `attachments.retention_days`.

- [ ] **Step 5: Implementar download seguro**

Não expor URL direta do storage. A rota deve carregar o anexo, validar `Ticket::visibleTo($user)` e retornar `Storage::disk($attachment->disk)->download(...)`.

- [ ] **Step 6: Atualizar a tela do ticket**

Adicionar checklist interativo e lista de anexos com autor, tamanho, data e ações permitidas.

- [ ] **Step 7: Rodar testes**

Run: `php artisan test tests/Feature/TicketChecklistTest.php tests/Feature/TicketAttachmentTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app routes resources/views/tickets/show.blade.php tests/Feature/TicketChecklistTest.php tests/Feature/TicketAttachmentTest.php
git commit -m "feat: adicionar checklist e anexos privados"
```

---

### Task 7: Ciclo de vida, lixeira, atraso, notificações e recorrência

**Files:**
- Create: `app/Models/Setting.php`
- Create: `app/Models/Recurrence.php`
- Create: `app/Http/Controllers/TicketLifecycleController.php`
- Create: `app/Notifications/TicketNotification.php`
- Create: `app/Console/Commands/ProcessTicketRecurrences.php`
- Create: `app/Console/Commands/TicketMaintenance.php`
- Modify: `routes/web.php`
- Modify: `routes/console.php`
- Create: `resources/views/notifications/index.blade.php`
- Create: `tests/Feature/TicketLifecycleTest.php`
- Create: `tests/Feature/TicketRecurrenceTest.php`

**Interfaces:**
- Produces: solicitar conclusão, resolver, fechar, cancelar, lixeira, restaurar e excluir definitivamente.
- Produces: notificações de banco para eventos do ticket.
- Produces: comandos `tickets:process-recurrences` e `tickets:maintenance`.

- [ ] **Step 1: Testar conclusão e checklist**

Responsável com `tickets.complete` conclui; colaborador com `tickets.request_completion` somente solicita; checklist obrigatório pendente bloqueia resolução; seguidor não conclui.

- [ ] **Step 2: Testar lixeira**

`trash` preenche `trashed_at`; `restore` zera; `forceDelete` exige permissão própria; itens na lixeira não aparecem nas caixas.

- [ ] **Step 3: Implementar ciclo de vida**

Usar `Status::system('resolved')`, `closed` e `cancelled`; preencher `completed_at` em resolução/fechamento e registrar cada evento.

- [ ] **Step 4: Implementar notificações**

`TicketNotification` deve persistir `ticket_id`, `ticket_number`, `event`, `title`, `actor_name` e `url`. Disparar para responsável/participantes conforme preferências, evitando notificar o próprio ator.

- [ ] **Step 5: Implementar recorrência**

`ProcessTicketRecurrences` busca recorrências ativas vencidas, cria novo ticket com novo número, copia campos essenciais/checklist, registra `recurrence_created` no novo ticket e avança `next_run_at` conforme frequência/intervalo.

- [ ] **Step 6: Implementar manutenção**

`TicketMaintenance` deve sincronizar etiqueta de sistema `Atrasada` para tickets vencidos e ativos, remover a etiqueta quando não aplicável, expirar anexos e excluir definitivamente tickets na lixeira após `trash.retention_days` quando configurado.

- [ ] **Step 7: Agendar comandos**

Em `routes/console.php`:

```php
Schedule::command('tickets:maintenance')->hourly();
Schedule::command('tickets:process-recurrences')->everyFifteenMinutes();
```

- [ ] **Step 8: Rodar testes**

Run: `php artisan test tests/Feature/TicketLifecycleTest.php tests/Feature/TicketRecurrenceTest.php`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app routes resources/views/notifications tests/Feature/TicketLifecycleTest.php tests/Feature/TicketRecurrenceTest.php
git commit -m "feat: implementar ciclo de vida notificacoes e recorrencia"
```

---

### Task 8: Administração de usuários, cargos, permissões, departamentos, status e etiquetas

**Files:**
- Create: `app/Services/AuditLogger.php`
- Create: `app/Models/AuditLog.php`
- Create: `app/Http/Controllers/Admin/UserController.php`
- Create: `app/Http/Controllers/Admin/RoleController.php`
- Create: `app/Http/Controllers/Admin/DepartmentController.php`
- Create: `app/Http/Controllers/Admin/StatusController.php`
- Create: `app/Http/Controllers/Admin/LabelController.php`
- Create: `app/Http/Controllers/Admin/AuditController.php`
- Create: `resources/views/admin/users/*`
- Create: `resources/views/admin/roles/*`
- Create: `resources/views/admin/departments/*`
- Create: `resources/views/admin/statuses/*`
- Create: `resources/views/admin/labels/*`
- Create: `resources/views/admin/audit/index.blade.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/AdminPermissionTest.php`
- Create: `tests/Feature/UserPermissionOverrideTest.php`

**Interfaces:**
- Produces: CRUD administrativo protegido somente por permissões.
- Produces: edição tri-state `inherit|allow|deny` por usuário.
- Produces: `AuditLogger::record(User $actor, Model $auditable, string $event, array $old, array $new, ?string $justification = null): AuditLog`.

- [ ] **Step 1: Testar acesso administrativo por permissão e não por cargo**

Um usuário de cargo comum com `departments.manage` deve acessar gestão de departamentos; um usuário chamado “Gestor” sem a permissão deve receber 403.

- [ ] **Step 2: Testar overrides pela interface**

POST/PATCH deve criar `allow`, criar `deny` ou apagar o override quando seleção for `inherit`.

- [ ] **Step 3: Implementar usuários**

Tela lista nome/e-mail/cargo/departamento/ativo; edição permite trocar cargo/departamento, ativar/inativar e configurar overrides. Antes de remover a última capacidade administrativa, verificar se permanecerá ao menos um usuário ativo com `users.manage` e `permissions.manage` efetivos.

- [ ] **Step 4: Implementar cargos**

CRUD com `active`, seleção de permissões padrão via `permissions()->sync($ids)` e auditoria de alterações.

- [ ] **Step 5: Implementar departamentos/status/etiquetas**

Departamentos: criar/editar/ativar/desativar e listar membros. Status: editar nome/cor/categoria/ordem/ativo sem permitir apagar `system_key`; etiquetas: CRUD preservando etiqueta `Atrasada` de sistema contra exclusão indevida.

- [ ] **Step 6: Implementar auditoria separada**

`AuditLogger` deve ser usado somente em alterações administrativas. `AuditController@index` deve filtrar por usuário, evento e período e exigir `audit.view`.

- [ ] **Step 7: Rodar testes**

Run: `php artisan test tests/Feature/AdminPermissionTest.php tests/Feature/UserPermissionOverrideTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin app/Models/AuditLog.php app/Services/AuditLogger.php resources/views/admin routes/web.php tests/Feature/AdminPermissionTest.php tests/Feature/UserPermissionOverrideTest.php
git commit -m "feat: adicionar administracao completa e auditoria"
```

---

### Task 9: Painel de integrações, API e webhooks

**Files:**
- Create: `app/Http/Middleware/AuthenticateIntegration.php`
- Create: `app/Http/Controllers/Admin/IntegrationController.php`
- Create: `app/Http/Controllers/Api/V1/IntegrationTicketController.php`
- Create: `app/Services/IntegrationWebhookService.php`
- Modify: `app/Models/ConnectedSystem.php`
- Modify: `app/Models/Company.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php`
- Modify: `routes/web.php`
- Create: `resources/views/admin/integrations/index.blade.php`
- Create: `resources/views/admin/integrations/form.blade.php`
- Create: `tests/Feature/IntegrationApiTest.php`
- Create: `tests/Feature/IntegrationWebhookTest.php`

**Interfaces:**
- Produces: bearer token por sistema armazenado somente como SHA-256.
- Produces: POST `/api/v1/tickets`, GET `/api/v1/tickets/{number}`, PATCH `/api/v1/tickets/{number}`, POST `/api/v1/tickets/{number}/comments`.
- Produces: assinatura `X-Sutoorii-Signature: sha256=<hmac>` para webhooks.

- [ ] **Step 1: Testar autenticação e isolamento**

Token válido do Sistema A cria/consulta seus tickets; token inválido retorna 401; Sistema B recebe 404 ao consultar ticket do Sistema A.

- [ ] **Step 2: Testar comentário público e nota interna**

API pode criar comentário público do solicitante; rota API não aceita `visibility=internal`. Nota interna criada pela interface nunca dispara webhook.

- [ ] **Step 3: Implementar geração de token**

Gerar token com `st_` + `Str::random(48)`, exibir uma única vez e persistir `hash('sha256', $token)` em `api_token_hash`. Regeneração invalida o anterior.

- [ ] **Step 4: Implementar middleware de integração**

Extrair bearer token, buscar `ConnectedSystem::where('api_token_hash', hash('sha256', $token))->where('active', true)`, anexar o sistema ao request e negar 401 quando não encontrado.

- [ ] **Step 5: Implementar API**

Na criação, forçar `origin=integration`, `system_id` do token autenticado, `company_id` correspondente e aceitar `external_reference`, requester e departamento inicial autorizado. Todas as consultas/updates devem incluir `where('system_id', $system->id)`.

- [ ] **Step 6: Implementar webhooks**

Enviar JSON com `event`, `ticket.number`, `ticket.external_reference`, `status`, timestamp e dados públicos. Assinar o corpo bruto com `hash_hmac('sha256', $body, $system->webhook_secret)`. Timeout curto e exceções devem ser logadas sem derrubar a alteração principal do ticket.

- [ ] **Step 7: Ligar eventos públicos ao webhook**

Disparar em mudança de status, comentário público, resolução, fechamento e cancelamento. Não disparar corpo de nota interna.

- [ ] **Step 8: Rodar testes**

Run: `php artisan test tests/Feature/IntegrationApiTest.php tests/Feature/IntegrationWebhookTest.php`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app routes bootstrap resources/views/admin/integrations tests/Feature/IntegrationApiTest.php tests/Feature/IntegrationWebhookTest.php
git commit -m "feat: implementar integracoes api e webhooks"
```

---

### Task 10: Navegação, configurações e acabamento visual

**Files:**
- Create: `app/Http/Controllers/Admin/SettingsController.php`
- Create: `resources/views/admin/settings/edit.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/boxes/index.blade.php`
- Modify: `resources/views/tickets/create.blade.php`
- Modify: `resources/views/tickets/show.blade.php`
- Modify: `public/css/app.css`
- Create: `tests/Feature/NavigationTest.php`

**Interfaces:**
- Produces: menu adaptado às permissões.
- Produces: configurações de retenção/anexos/lixeira.
- Produces: interface responsiva de help desk.

- [ ] **Step 1: Testar menu por permissão**

Usuário sem permissões administrativas não vê links de administração; usuário com apenas `integrations.manage` vê Integrações, mas não Usuários/Cargos/Auditoria.

- [ ] **Step 2: Implementar configurações**

Tela protegida por `settings.manage` para `attachments.max_mb`, `attachments.retention_days`, `trash.retention_days` e demais valores usados pelos serviços implementados.

- [ ] **Step 3: Reestruturar layout**

Desktop: sidebar com Minha Caixa, departamento do usuário, Novo Ticket, Notificações e Administração condicional. Mobile: topbar compacta + navegação adequada sem cobrir conteúdo.

- [ ] **Step 4: Refinar caixas**

Filtros devem indicar estado ativo, permitir busca e mostrar contagem. Cards devem destacar status, prioridade, departamento, responsável e vencimento sem excesso visual.

- [ ] **Step 5: Refinar tela do ticket**

Ações de assumir/encaminhar/reatribuir ficam próximas dos respectivos campos. Comentário público e Nota interna usam seletor evidente. Histórico automático recebe aparência diferente de mensagens humanas.

- [ ] **Step 6: Rodar suíte completa**

Run: `php artisan test`

Expected: todos os testes PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/SettingsController.php resources/views public/css/app.css tests/Feature/NavigationTest.php
git commit -m "feat: finalizar navegacao configuracoes e interface"
```

---

### Task 11: Verificação final, compatibilidade da instalação atual e publicação

**Files:**
- Modify if needed: `README.md`
- Modify if needed: `DEPLOY.md`
- Modify if needed: `public/install.php`
- Verify: `.github/workflows/deploy.yml`
- Verify: all application files touched by Tasks 1–10

**Interfaces:**
- Produces: versão pronta para merge em `main` e deploy automático.

- [ ] **Step 1: Testar banco vazio**

Run:

```bash
php artisan migrate:fresh --seed
php artisan test
```

Expected: instalação nova completa, status/permissions criados e suíte verde.

- [ ] **Step 2: Testar cenário de upgrade**

Criar banco apenas com migration core `2026_09_09_000001`, seed inicial e dados de exemplo; depois executar `php artisan migrate --force`. Confirmar que usuários/tickets existentes continuam presentes e as novas tabelas/campos são acrescentados.

- [ ] **Step 3: Verificar instalador**

Confirmar que `public/install.php` executa todas as migrations atuais em uma instalação nova e não depende de SQL manual.

- [ ] **Step 4: Rodar análise de rotas**

Run: `php artisan route:list`

Confirmar ausência de colisões e presença das rotas de caixas, ticket, administração e API.

- [ ] **Step 5: Rodar suíte final**

Run: `php artisan test`

Expected: PASS sem falhas.

- [ ] **Step 6: Fazer revisão de segurança**

Confirmar por testes/manualmente: nota interna não sai em webhook/API pública; download de anexo exige visibilidade; token nunca volta após criação/regeneração; usuários sem permissão recebem 403; sistema não fica sem administrador efetivo.

- [ ] **Step 7: Atualizar documentação operacional**

Documentar cron necessário para `php artisan schedule:run` a cada minuto na hospedagem e exemplos de autenticação da API sem registrar tokens reais.

- [ ] **Step 8: Commit final da documentação**

```bash
git add README.md DEPLOY.md public/install.php
git commit -m "docs: finalizar operacao e upgrade do Sutoorii Tickets"
```

- [ ] **Step 9: Merge e deploy**

Somente depois de todos os testes passarem, integrar `feature/finalizacao-sutoorii-tickets` em `main`. Acompanhar o GitHub Actions até o passo de extração/migration terminar com sucesso e então validar em produção: login, Minha Caixa, caixa de departamento, criação/abertura/edição de ticket, comentário, encaminhamento, assumir, administração e health da API.
