# Sutoorii Tickets Finalization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finalizar o Sutoorii Tickets como help desk operacional completo, preservando a base Laravel atual e implementando caixas, gestão do ticket, histórico, permissões individuais, administração, integrações, notificações, recorrência, anexos e ciclo de vida.

**Architecture:** A base Laravel 12 existente será mantida. A implementação será incremental, com migrations aditivas para preservar a instalação atual, autorização centralizada por permissões efetivas, controladores pequenos por responsabilidade e serviços separados para histórico, auditoria e webhooks. A interface Blade atual será reorganizada sem introduzir framework frontend novo.

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
- Histórico operacional fica dentro do ticket; auditoria administrativa fica em módulo separado.
- Cargo fornece permissões padrão; override individual `allow`/`deny` prevalece sobre o cargo; ausência de override significa herança.
- Nenhuma função administrativa depende do nome do cargo.
- Comentário público de ticket integrado pode gerar webhook; nota interna nunca sai do Sutoorii Tickets.
- Vídeos não são aceitos como anexo.
- Não deve existir deploy que dependa de execução manual de SQL.
- Status automáticos usam `system_key`, permitindo editar o nome visível sem quebrar os fluxos internos.

## Permission Keys

As rotas e ações devem usar estas permissões efetivas, sem autorização baseada em nome de cargo:

`tickets.create`, `tickets.view_department`, `tickets.view_all`, `tickets.edit`, `tickets.forward`, `tickets.assume`, `tickets.reassign`, `tickets.change_status`, `tickets.change_priority`, `tickets.change_due_date`, `tickets.manage_participants`, `tickets.manage_labels`, `tickets.comment`, `tickets.internal_note`, `tickets.manage_checklist`, `tickets.manage_attachments`, `tickets.request_completion`, `tickets.resolve`, `tickets.close`, `tickets.cancel`, `tickets.trash`, `tickets.restore`, `tickets.force_delete`, `tickets.recurrence`, `users.manage`, `roles.manage`, `permissions.manage`, `departments.manage`, `statuses.manage`, `labels.manage`, `integrations.manage`, `audit.view`, `settings.manage`.

As permissões antigas permanecem no banco para compatibilidade, mas os novos controladores usam as chaves acima. A migration de upgrade deve anexar todas as novas permissões ao cargo `Super Admin` existente para não reduzir o acesso atual.

O cargo padrão `Usuário interno` deve receber: `tickets.create`, `tickets.view_department`, `tickets.forward`, `tickets.assume`, `tickets.change_status`, `tickets.comment`, `tickets.internal_note`, `tickets.manage_checklist`, `tickets.manage_attachments`, `tickets.request_completion` e `tickets.resolve`.

O cargo padrão `Gestor` deve ser criado quando ausente e receber todas as permissões operacionais de tickets, `tickets.view_all`, `users.manage`, `departments.manage`, `statuses.manage`, `labels.manage` e `audit.view`. Outras permissões administrativas continuam livremente concedíveis por cargo ou por usuário.

---

## File Structure

- `database/migrations/2026_09_10_000002_finalize_ticket_core.php`: schema aditivo, status estáveis, permissões e migração dos papéis existentes.
- `app/Models/UserPermissionOverride.php`, `app/Models/TicketEvent.php`, `app/Models/Attachment.php`, `app/Models/AuditLog.php`, `app/Models/Setting.php`, `app/Models/Recurrence.php`: modelos ausentes.
- `app/Services/TicketEventRecorder.php`, `app/Services/AuditLogger.php`, `app/Services/IntegrationWebhookService.php`: efeitos transversais isolados.
- `app/Http/Middleware/RequirePermission.php`, `app/Http/Middleware/AuthenticateIntegration.php`: autorização web/API.
- `app/Http/Controllers/TicketBoxController.php`: Minha Caixa e caixa de departamento.
- `app/Http/Controllers/TicketController.php`: criação, exibição e edição de campos básicos.
- `app/Http/Controllers/TicketAssignmentController.php`: assumir e reatribuir.
- `app/Http/Controllers/TicketRoutingController.php`: encaminhar.
- `app/Http/Controllers/TicketParticipantController.php`: colaboradores e seguidores.
- `app/Http/Controllers/TicketLabelController.php`: etiquetas no ticket.
- `app/Http/Controllers/TicketCommentController.php`: comentários públicos e notas internas.
- `app/Http/Controllers/TicketChecklistController.php`: checklist.
- `app/Http/Controllers/TicketAttachmentController.php`: anexos.
- `app/Http/Controllers/TicketLifecycleController.php`: conclusão/cancelamento/lixeira.
- `app/Http/Controllers/TicketRecurrenceController.php`: configuração de recorrência.
- `app/Http/Controllers/TicketTrashController.php`: listagem da lixeira.
- `app/Http/Controllers/NotificationController.php`: central de notificações.
- `app/Http/Controllers/Admin/*`: usuários, cargos, departamentos, status, etiquetas, integrações, configurações e auditoria.
- `app/Http/Controllers/Api/V1/IntegrationTicketController.php`: API de sistemas conectados.
- `resources/views/boxes/*`, `resources/views/tickets/show.blade.php`, `resources/views/admin/*`, `resources/views/notifications/*`, `resources/views/trash/*`, `resources/views/layouts/app.blade.php`, `public/css/app.css`: interface final.
- `tests/Feature/*`: regressão e regras de negócio.
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
- Produces: `php artisan test` funcional em SQLite em memória.
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
        $this->getJson('/api/v1/health')->assertOk()->assertJson([
            'status' => 'ok',
            'service' => 'Sutoorii Tickets',
        ]);
    }
}
```

- [ ] **Step 2: Configurar PHPUnit**

`phpunit.xml` deve definir `APP_ENV=testing`, `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync` e `MAIL_MAILER=array`.

- [ ] **Step 3: Criar `tests/TestCase.php`**

```php
<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
abstract class TestCase extends BaseTestCase {}
```

- [ ] **Step 4: Gerar `composer.lock`**

Run: `composer update --no-interaction --prefer-dist`

Expected: `composer.lock` criado.

- [ ] **Step 5: Rodar teste**

Run: `php artisan test tests/Feature/SmokeTest.php`

Expected: PASS.

- [ ] **Step 6: Testar antes de empacotar no GitHub Actions**

Adicionar `pdo_sqlite` às extensões e antes de compactar:

```yaml
      - name: Executar testes
        run: php artisan test
```

- [ ] **Step 7: Executar migrations no deploy**

Depois da extração em `deploy/extract.php`:

```php
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$exitCode = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
if ($exitCode !== 0) {
    http_response_code(500);
    exit('Falha ao atualizar o banco.');
}
```

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
- Produces: `User::hasPermission(string $key): bool`.
- Produces: `Status::system(string $key): ?Status`.
- Produces: middleware `permission:<key>`.
- Produces: `Ticket::events()`.

- [ ] **Step 1: Testar precedência**

```php
$this->assertFalse($userWithExplicitDeny->hasPermission('tickets.reassign'));
$this->assertTrue($userWithExplicitAllow->hasPermission('tickets.reassign'));
$this->assertTrue($userUsingRole->hasPermission('tickets.create'));
```

- [ ] **Step 2: Confirmar FAIL antes da implementação**

Run: `php artisan test tests/Feature/PermissionTest.php`

Expected: FAIL pela ausência do override.

- [ ] **Step 3: Criar migration aditiva**

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

A migration deve criar/atualizar exatamente estes `system_key`: `new`, `forwarded`, `in_progress`, `resolved`, `closed`, `cancelled`. `Encaminhado` deve ser criado com categoria `open` se não existir. Deve inserir todas as Permission Keys desta especificação, anexá-las ao `Super Admin`, criar `Gestor` se ausente com o conjunto definido acima e atualizar as permissões padrão de `Usuário interno` sem apagar overrides futuros.

- [ ] **Step 4: Implementar precedência no `User`**

```php
public function permissionOverrides() { return $this->hasMany(UserPermissionOverride::class); }
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

- [ ] **Step 5: Implementar status estável**

```php
public static function system(string $key): ?self
{
    return static::where('system_key', $key)->first();
}
```

- [ ] **Step 6: Registrar middleware**

`RequirePermission` aborta 403 se a permissão efetiva não existir; registrar alias `permission` em `bootstrap/app.php`.

- [ ] **Step 7: Rodar testes**

Run: `php artisan test tests/Feature/PermissionTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

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
- Produces: `Ticket::scopeVisibleTo`, `scopeMyBox`, `scopeActiveForBox`.
- Produces: GET `/minha-caixa` e GET `/departamentos/{department}/tickets`.

- [ ] **Step 1: Testar visibilidade**

```php
$this->actingAs($creator)->get(route('tickets.show', $ticket))->assertForbidden();
$this->actingAs($departmentUser)->get(route('tickets.show', $ticket))->assertOk();
$this->actingAs($collaborator)->get(route('tickets.show', $ticket))->assertOk();
$this->actingAs($follower)->get(route('tickets.show', $ticket))->assertOk();
```

- [ ] **Step 2: Testar Minha Caixa**

Responsável, colaborador e seguidor aparecem uma vez; ticket apenas criado não aparece; `resolved`, `closed` e `cancelled` não aparecem sem filtro e reaparecem quando o status é solicitado explicitamente.

- [ ] **Step 3: Implementar scopes**

`visibleTo` permite `tickets.view_all`, responsável, participante ou ticket do próprio departamento quando houver `tickets.view_department`. Remover `creator_id` como passe permanente. `myBox` usa somente responsável/participantes.

- [ ] **Step 4: Implementar filtros**

Aceitar `relation=assignee|collaborator|follower`, `status`, `priority`, `unassigned=1`, `overdue=1` e `q`. Sem `status`, aplicar `activeForBox()`.

- [ ] **Step 5: Criar view de caixas**

Filtros são links/formulários reais, preservam query string e exibem departamento, responsável, prazo, prioridade e status.

- [ ] **Step 6: Rodar testes**

Run: `php artisan test tests/Feature/TicketVisibilityTest.php tests/Feature/TicketBoxTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TicketBoxController.php app/Models/Ticket.php routes/web.php resources/views tests/Feature/TicketVisibilityTest.php tests/Feature/TicketBoxTest.php
git commit -m "feat: implementar minha caixa e caixas de departamento"
```

---

### Task 4: Responsável, encaminhamento, participantes e etiquetas

**Files:**
- Create: `app/Services/TicketEventRecorder.php`
- Create: `app/Http/Controllers/TicketAssignmentController.php`
- Create: `app/Http/Controllers/TicketRoutingController.php`
- Create: `app/Http/Controllers/TicketParticipantController.php`
- Create: `app/Http/Controllers/TicketLabelController.php`
- Modify: `app/Http/Controllers/TicketController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/TicketAssignmentTest.php`
- Create: `tests/Feature/TicketRoutingTest.php`
- Create: `tests/Feature/TicketParticipantTest.php`
- Create: `tests/Feature/TicketLabelTest.php`

**Interfaces:**
- Produces: `TicketEventRecorder::record(Ticket $ticket, ?User $actor, string $event, array $data = []): TicketEvent`.
- Produces: POST `/tickets/{ticket}/assumir`, PATCH `/tickets/{ticket}/responsavel`, POST `/tickets/{ticket}/encaminhar` e rotas de participantes/etiquetas.

- [ ] **Step 1: Testar assumir/reatribuir**

Membro do departamento com `tickets.assume` assume ticket sem responsável; outro departamento recebe 403; ticket já atribuído exige `tickets.reassign`; assumir `forwarded` muda para `in_progress`.

- [ ] **Step 2: Testar encaminhamento**

```php
$this->assertSame($targetDepartment->id, $ticket->fresh()->department_id);
$this->assertNull($ticket->fresh()->assignee_id);
$this->assertSame('forwarded', $ticket->fresh()->status->system_key);
$this->assertDatabaseHas('ticket_events', ['ticket_id' => $ticket->id, 'event' => 'forwarded']);
```

- [ ] **Step 3: Testar participantes e etiquetas**

Adicionar/remover colaborador/seguidor exige `tickets.manage_participants`; adicionar/remover etiqueta exige `tickets.manage_labels`; toda ação gera evento.

- [ ] **Step 4: Implementar recorder**

```php
public function record(Ticket $ticket, ?User $actor, string $event, array $data = []): TicketEvent
{
    return $ticket->events()->create(['actor_id' => $actor?->id, 'event' => $event, 'data' => $data]);
}
```

- [ ] **Step 5: Implementar assumir e reatribuir**

`assume` exige mesmo departamento, ticket sem responsável e `tickets.assume`. `reassign` exige `tickets.reassign` e destinatário ativo. Registrar IDs/nomes antigo/novo.

- [ ] **Step 6: Implementar encaminhamento**

Em transação: atualizar departamento, zerar responsável, aplicar `Status::system('forwarded')` e registrar origem/destino/responsável removido/motivo.

- [ ] **Step 7: Implementar participantes e etiquetas**

Participantes usam `syncWithoutDetaching`/`detach`. Etiquetas usam `labels()->syncWithoutDetaching([$labelId])`/`detach($labelId)`. Registrar eventos específicos.

- [ ] **Step 8: Ajustar criação**

Usuário comum cria por padrão no próprio departamento. Escolha direta de outro departamento só é aceita com `tickets.forward` ou `tickets.view_all`; se o criador não possuir visibilidade após a criação, redirecionar para Minha Caixa com confirmação em vez de abrir um ticket que resultaria em 403.

- [ ] **Step 9: Rodar testes**

Run: `php artisan test tests/Feature/TicketAssignmentTest.php tests/Feature/TicketRoutingTest.php tests/Feature/TicketParticipantTest.php tests/Feature/TicketLabelTest.php`

Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app routes tests/Feature/TicketAssignmentTest.php tests/Feature/TicketRoutingTest.php tests/Feature/TicketParticipantTest.php tests/Feature/TicketLabelTest.php
git commit -m "feat: implementar atribuicao encaminhamento participantes e etiquetas"
```

---

### Task 5: Edição, comentários e linha do tempo

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
- Produces: PATCH `/tickets/{ticket}`.
- Produces: POST `/tickets/{ticket}/comentarios` com `visibility=public|internal`.
- Produces: `timeline` combinando eventos/comentários.

- [ ] **Step 1: Testar edição granular**

Título/descrição exigem `tickets.edit`; status `tickets.change_status`; prioridade `tickets.change_priority`; prazo `tickets.change_due_date`. Cada alteração gera evento com valor antigo/novo.

- [ ] **Step 2: Testar comentários**

Colaborador com `tickets.comment` comenta; seguidor cujo único vínculo é follower recebe 403; nota interna exige `tickets.internal_note`; comentário público e nota interna permanecem distintos.

- [ ] **Step 3: Implementar update**

Aplicar autorização por campo recebido e registrar somente campos realmente alterados.

- [ ] **Step 4: Implementar comentários**

```php
$comment = $ticket->comments()->create([
    'user_id' => $request->user()->id,
    'visibility' => $data['visibility'],
    'body' => $data['body'],
    'source' => 'web',
]);
```

Registrar `comment_created` apenas com `comment_id`/visibilidade, sem duplicar o corpo em `ticket_events`.

- [ ] **Step 5: Montar timeline**

Carregar `events.actor`, `comments.user` e mesclar com `kind=event|comment`, ordenando por `created_at` e `id`.

- [ ] **Step 6: Reestruturar a tela**

Cabeçalho com status/prioridade; lateral com departamento/responsável/prazo/participantes/etiquetas; centro com descrição, composer público/interno e timeline. Botões condicionados às permissões.

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
- Create: `app/Models/Setting.php`
- Create: `app/Http/Controllers/TicketChecklistController.php`
- Create: `app/Http/Controllers/TicketAttachmentController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/tickets/show.blade.php`
- Create: `tests/Feature/TicketChecklistTest.php`
- Create: `tests/Feature/TicketAttachmentTest.php`

**Interfaces:**
- Produces: CRUD/ordenação/conclusão de checklist.
- Produces: upload privado, download autorizado e remoção lógica de anexos.

- [ ] **Step 1: Testar checklist**

`manage_checklist` controla adicionar/ordenar/concluir/desmarcar/remover. Conclusão registra `completed_by` e `completed_at`.

- [ ] **Step 2: Testar anexos**

Com `Storage::fake('local')`, PDF/imagem sobem; `video/mp4` falha; usuário sem visibilidade não baixa; remoção preenche `deleted_at`.

- [ ] **Step 3: Implementar checklist**

```php
$item->update([
    'completed' => true,
    'completed_by' => $request->user()->id,
    'completed_at' => now(),
]);
```

Ao desmarcar, zerar `completed_by` e `completed_at`. Todas as ações registram histórico.

- [ ] **Step 4: Implementar `Setting`**

Criar métodos `Setting::getValue(string $key, mixed $default = null)` e `Setting::setValue(string $key, mixed $value): void` sobre a tabela `settings` existente.

- [ ] **Step 5: Implementar upload/download**

Tamanho máximo = `attachments.max_mb` (padrão 20); retenção = `attachments.retention_days` (padrão 90); negar qualquer MIME iniciado por `video/`; salvar em `storage/app/private/tickets/{ticket_id}`. Download passa obrigatoriamente por controller e `visibleTo`.

- [ ] **Step 6: Atualizar a tela do ticket**

Adicionar checklist interativo e anexos com autor/tamanho/data/ações.

- [ ] **Step 7: Rodar testes**

Run: `php artisan test tests/Feature/TicketChecklistTest.php tests/Feature/TicketAttachmentTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app routes resources/views/tickets/show.blade.php tests/Feature/TicketChecklistTest.php tests/Feature/TicketAttachmentTest.php
git commit -m "feat: adicionar checklist e anexos privados"
```

---

### Task 7: Ciclo de vida, lixeira, notificações e recorrência

**Files:**
- Create: `app/Models/Recurrence.php`
- Create: `app/Http/Controllers/TicketLifecycleController.php`
- Create: `app/Http/Controllers/TicketTrashController.php`
- Create: `app/Http/Controllers/TicketRecurrenceController.php`
- Create: `app/Http/Controllers/NotificationController.php`
- Create: `app/Notifications/TicketNotification.php`
- Create: `app/Console/Commands/ProcessTicketRecurrences.php`
- Create: `app/Console/Commands/TicketMaintenance.php`
- Modify: `routes/web.php`
- Modify: `routes/console.php`
- Create: `resources/views/trash/index.blade.php`
- Create: `resources/views/notifications/index.blade.php`
- Modify: `resources/views/tickets/show.blade.php`
- Create: `tests/Feature/TicketLifecycleTest.php`
- Create: `tests/Feature/TicketRecurrenceTest.php`
- Create: `tests/Feature/NotificationTest.php`

**Interfaces:**
- Produces: solicitar conclusão, resolver, fechar, cancelar, lixeira, restaurar e excluir definitivamente.
- Produces: CRUD de recorrência no ticket.
- Produces: central de notificações com marcar lida/todas lidas.
- Produces: comandos `tickets:process-recurrences` e `tickets:maintenance`.

- [ ] **Step 1: Testar ciclo de vida**

Responsável com `tickets.resolve` resolve; colaborador com `tickets.request_completion` só solicita; checklist obrigatório pendente bloqueia resolução; `tickets.close` e `tickets.cancel` controlam as respectivas transições.

- [ ] **Step 2: Testar lixeira**

`tickets.trash` preenche `trashed_at`; `tickets.restore` restaura; `tickets.force_delete` exclui definitivamente; itens da lixeira não aparecem nas caixas.

- [ ] **Step 3: Implementar ciclo de vida**

Usar `Status::system('resolved')`, `closed` e `cancelled`; preencher `completed_at` em resolução/fechamento; registrar todos os eventos.

- [ ] **Step 4: Implementar lixeira**

GET `/lixeira` lista apenas tickets visíveis com `trashed_at` preenchido. Restore/force-delete usam permissões específicas.

- [ ] **Step 5: Implementar notificações**

`TicketNotification` persiste `ticket_id`, `ticket_number`, `event`, `title`, `actor_name`, `url`. `NotificationController` lista notificações do usuário, marca uma como lida e possui ação para marcar todas como lidas. Notificar responsável/participantes conforme flags, sem notificar o próprio ator.

- [ ] **Step 6: Implementar configuração de recorrência**

`TicketRecurrenceController` exige `tickets.recurrence`, aceita `frequency=daily|weekly|monthly`, `interval>=1`, `weekdays` quando semanal, `next_run_at`, `ends_at` opcional e `active`. A seção aparece dentro do ticket.

- [ ] **Step 7: Implementar processamento de recorrência**

Criar novo ticket com novo número, copiar título/descrição/prioridade/departamento/checklist, deixar responsável vazio, registrar `recurrence_created` e avançar `next_run_at`.

- [ ] **Step 8: Implementar manutenção**

`TicketMaintenance` sincroniza etiqueta `Atrasada`, expira anexos e remove definitivamente tickets em lixeira depois de `trash.retention_days` (padrão 30).

- [ ] **Step 9: Agendar**

```php
Schedule::command('tickets:maintenance')->hourly();
Schedule::command('tickets:process-recurrences')->everyFifteenMinutes();
```

- [ ] **Step 10: Rodar testes**

Run: `php artisan test tests/Feature/TicketLifecycleTest.php tests/Feature/TicketRecurrenceTest.php tests/Feature/NotificationTest.php`

Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add app routes resources/views/trash resources/views/notifications resources/views/tickets/show.blade.php tests/Feature/TicketLifecycleTest.php tests/Feature/TicketRecurrenceTest.php tests/Feature/NotificationTest.php
git commit -m "feat: implementar ciclo de vida notificacoes e recorrencia"
```

---

### Task 8: Administração e auditoria

**Files:**
- Create: `app/Services/AuditLogger.php`
- Create: `app/Models/AuditLog.php`
- Create: `app/Http/Controllers/Admin/UserController.php`
- Create: `app/Http/Controllers/Admin/RoleController.php`
- Create: `app/Http/Controllers/Admin/DepartmentController.php`
- Create: `app/Http/Controllers/Admin/StatusController.php`
- Create: `app/Http/Controllers/Admin/LabelController.php`
- Create: `app/Http/Controllers/Admin/AuditController.php`
- Create: `resources/views/admin/users/index.blade.php`
- Create: `resources/views/admin/users/edit.blade.php`
- Create: `resources/views/admin/roles/index.blade.php`
- Create: `resources/views/admin/roles/edit.blade.php`
- Create: `resources/views/admin/departments/index.blade.php`
- Create: `resources/views/admin/departments/edit.blade.php`
- Create: `resources/views/admin/statuses/index.blade.php`
- Create: `resources/views/admin/statuses/edit.blade.php`
- Create: `resources/views/admin/labels/index.blade.php`
- Create: `resources/views/admin/labels/edit.blade.php`
- Create: `resources/views/admin/audit/index.blade.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/AdminPermissionTest.php`
- Create: `tests/Feature/UserPermissionOverrideTest.php`

**Interfaces:**
- Produces: CRUD administrativo baseado em permissões.
- Produces: tri-state `inherit|allow|deny` por usuário.
- Produces: `AuditLogger::record(...)` para alterações administrativas.

- [ ] **Step 1: Testar acesso por permissão, não por cargo**

Usuário de cargo comum com `departments.manage` acessa departamentos; usuário chamado Gestor sem a permissão recebe 403.

- [ ] **Step 2: Testar overrides**

Seleção `allow` cria/atualiza allow; `deny` cria/atualiza deny; `inherit` remove a linha de override.

- [ ] **Step 3: Implementar usuários**

Lista nome/e-mail/cargo/departamento/ativo; edição altera cargo/departamento/ativo e overrides. Bloquear operação que deixe zero usuários ativos com `users.manage` e `permissions.manage` efetivos.

- [ ] **Step 4: Implementar cargos**

CRUD com `active` e `permissions()->sync($ids)`. Todas as mudanças entram em auditoria.

- [ ] **Step 5: Implementar departamentos/status/etiquetas**

Departamentos: CRUD/ativo/membros. Status: nome/cor/categoria/ordem/ativo; `system_key` não é editável nem removível pela interface. Etiqueta `Atrasada` de sistema não pode ser apagada.

- [ ] **Step 6: Implementar auditoria**

`AuditLogger` grava `user_id`, morph, evento, old/new, IP e justificativa. `AuditController` filtra usuário/evento/período e exige `audit.view`. Não gravar eventos operacionais de ticket nesta tabela.

- [ ] **Step 7: Rodar testes**

Run: `php artisan test tests/Feature/AdminPermissionTest.php tests/Feature/UserPermissionOverrideTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin app/Models/AuditLog.php app/Services/AuditLogger.php resources/views/admin routes/web.php tests/Feature/AdminPermissionTest.php tests/Feature/UserPermissionOverrideTest.php
git commit -m "feat: adicionar administracao completa e auditoria"
```

---

### Task 9: Integrações, API e webhooks

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
- Produces: bearer token por sistema armazenado como SHA-256.
- Produces: POST `/api/v1/tickets`, GET/PATCH `/api/v1/tickets/{number}`, POST `/api/v1/tickets/{number}/comments`.
- Produces: `X-Sutoorii-Signature: sha256=<hmac>`.

- [ ] **Step 1: Testar autenticação/isolamento**

Token A só acessa tickets do Sistema A; inválido retorna 401; Sistema B recebe 404 para ticket do A.

- [ ] **Step 2: Testar comentários da API**

API cria apenas comentário público de origem integrada; `visibility=internal` é rejeitado. Nota interna web nunca dispara webhook.

- [ ] **Step 3: Implementar token**

Gerar `st_`.Str::random(48), exibir uma vez e salvar `hash('sha256', $token)`. Regenerar invalida o anterior.

- [ ] **Step 4: Implementar middleware**

Hash do bearer token busca `ConnectedSystem` ativo e injeta `integrationSystem` no request; falha retorna 401.

- [ ] **Step 5: Implementar API**

Criação força `origin=integration`, `system_id`, `company_id`, aceita `external_reference`, requester e departamento. Leitura/alteração sempre inclui `where('system_id', $system->id)`.

- [ ] **Step 6: Implementar webhooks**

Enviar corpo com evento, ticket number/external_reference, status, timestamp e dados públicos. Assinar `hash_hmac('sha256', $body, $webhook_secret)`. Falha de rede é logada e não desfaz a alteração principal.

- [ ] **Step 7: Disparar eventos públicos**

Status, comentário público, resolução, fechamento e cancelamento podem gerar webhook. Nota interna nunca é serializada no payload.

- [ ] **Step 8: Rodar testes**

Run: `php artisan test tests/Feature/IntegrationApiTest.php tests/Feature/IntegrationWebhookTest.php`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app routes bootstrap resources/views/admin/integrations tests/Feature/IntegrationApiTest.php tests/Feature/IntegrationWebhookTest.php
git commit -m "feat: implementar integracoes api e webhooks"
```

---

### Task 10: Configurações, navegação e acabamento visual

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
- Produces: menu por permissões e layout completo de help desk.
- Produces: configurações `attachments.max_mb`, `attachments.retention_days`, `trash.retention_days`.

- [ ] **Step 1: Testar menu por permissão**

Sem permissão administrativa, links admin não aparecem. Com somente `integrations.manage`, só a opção administrativa Integrações aparece.

- [ ] **Step 2: Implementar configurações**

Tela `settings.manage` valida: `attachments.max_mb` inteiro 1–100; `attachments.retention_days` inteiro 1–3650; `trash.retention_days` inteiro 1–3650. Registrar mudanças em auditoria.

- [ ] **Step 3: Reestruturar layout**

Desktop: sidebar com Minha Caixa, caixa do departamento, Novo Ticket, Notificações, Lixeira quando autorizada e Administração condicional. Mobile: topbar/menu compacto sem cobrir conteúdo.

- [ ] **Step 4: Refinar caixas**

Busca/filtros funcionais com estado ativo e contadores; cards mostram status, prioridade, departamento, responsável e vencimento.

- [ ] **Step 5: Refinar ticket**

Assumir/encaminhar/reatribuir próximos aos campos; Comentário público/Nota interna com seletor evidente; eventos automáticos visualmente diferentes de mensagens.

- [ ] **Step 6: Rodar suíte completa**

Run: `php artisan test`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/SettingsController.php resources/views public/css/app.css tests/Feature/NavigationTest.php
git commit -m "feat: finalizar navegacao configuracoes e interface"
```

---

### Task 11: Verificação final, upgrade e publicação

**Files:**
- Modify: `README.md`
- Modify: `DEPLOY.md`
- Modify: `public/install.php`
- Verify: `.github/workflows/deploy.yml`
- Verify: aplicação completa das Tasks 1–10

**Interfaces:**
- Produces: instalação nova e upgrade da instalação atual sem SQL manual.

- [ ] **Step 1: Testar banco vazio**

```bash
php artisan migrate:fresh --seed
php artisan test
```

Expected: instalação nova completa e suíte verde.

- [ ] **Step 2: Testar upgrade**

Em SQLite de teste, executar somente a migration core `2026_09_09_000001`, semear usuários/tickets representativos, executar a migration final e confirmar que os registros continuam presentes e novos campos/tabelas foram adicionados.

- [ ] **Step 3: Atualizar instalador**

Garantir que `public/install.php` execute todas as migrations e seeders atuais, sem SQL manual, e continue bloqueado depois da instalação concluída.

- [ ] **Step 4: Verificar rotas**

Run: `php artisan route:list`

Confirmar caixas, tickets, administração, notificações, lixeira e API sem colisões.

- [ ] **Step 5: Rodar suíte final**

Run: `php artisan test`

Expected: PASS.

- [ ] **Step 6: Revisar segurança**

Verificar: nota interna não sai em webhook/API; anexos exigem visibilidade; token puro aparece só na criação/regeneração; 403 nas ações sem permissão; nenhum usuário consegue remover a última capacidade administrativa da instalação.

- [ ] **Step 7: Atualizar documentação**

`README.md` deve descrever módulos/caixas/permissões/API em alto nível. `DEPLOY.md` deve documentar que migrations são automáticas e registrar o cron de hospedagem `php artisan schedule:run` a cada minuto, sem incluir credenciais ou tokens reais.

- [ ] **Step 8: Commit final**

```bash
git add README.md DEPLOY.md public/install.php
git commit -m "docs: finalizar operacao e upgrade do Sutoorii Tickets"
```

- [ ] **Step 9: Integrar e publicar**

Somente após toda a suíte passar, integrar `feature/finalizacao-sutoorii-tickets` em `main`. Acompanhar o GitHub Actions até extração e migration concluírem. Em produção validar login, Minha Caixa, caixa de departamento, criação/edição, comentário, nota interna, encaminhamento, assumir, reatribuição, checklist, anexo, administração, notificações, lixeira e `/api/v1/health`.
