# Departamentos, Solicitantes e Notificações Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar acessos multi-departamento, fluxo simplificado de criação, papéis de solicitante/participantes, portal do solicitante e notificações por e-mail.

**Architecture:** Criar uma pivot `department_user_access` com nível `send|view|edit` e `follow_department`, centralizar decisões de acesso em serviços/modelos e preservar `users.department_id` apenas como compatibilidade. Criar um serviço único de notificações de ticket, busca dinâmica de usuários e portal assinado do solicitante manual, reutilizando os campos de integração existentes.

**Tech Stack:** Laravel/PHP, Eloquent, Blade, Notifications/Mail, URLs assinadas, PHPUnit Feature Tests.

**Spec:** `docs/superpowers/specs/2026-09-16-departamentos-notificacoes-solicitantes-design.md`

## Global Constraints

- Usuário pode se associar a vários departamentos.
- Níveis progressivos: `send`, `view`, `edit`.
- `follow_department` só pode ficar ativo em `view`/`edit`.
- Criador não ganha visibilidade por ser criador.
- Solicitante sempre vê os próprios tickets, inclusive fechados, e pode comentar publicamente.
- Responsável/colaborador/seguidor usam busca dinâmica.
- Gestor da integração vê e comenta todos os tickets do próprio sistema.
- Notificações não devem duplicar e-mails para o mesmo endereço.
- Notas internas nunca notificam solicitante externo.
- Migração deve preservar o acesso histórico de `users.department_id`.

---

### Task 1: Modelo de acesso multi-departamento

**Files:**
- Create: `database/migrations/2026_09_16_000005_add_department_access_and_requester_user.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/Department.php`
- Modify: `app/Models/Ticket.php`
- Create: `app/Services/DepartmentAccess.php`
- Test: `tests/Feature/DepartmentAccessV2Test.php`

**Interfaces:**
- `DepartmentAccess::canSend(User $user, Department|int $department): bool`
- `DepartmentAccess::canView(User $user, Department|int $department): bool`
- `DepartmentAccess::canEdit(User $user, Department|int $department): bool`
- `DepartmentAccess::sendableIds(User $user): array`
- `DepartmentAccess::viewableIds(User $user): array`

- [ ] Escrever testes que comprovem `send` sem leitura, `view` com leitura, `edit` progressivo, acesso legado migrado e acompanhamento desligado ao rebaixar para `send`.
- [ ] Executar CI e confirmar falha pela ausência da nova tabela/relações.
- [ ] Criar a migration e relacionamentos Eloquent.
- [ ] Implementar `DepartmentAccess` e atualizar `Ticket::visibleTo()`/`myBox()` para departamento e solicitante.
- [ ] Executar testes e suíte relacionada até ficar verde.

### Task 2: Tela operacional de Departamentos e busca interna

**Files:**
- Modify: `app/Http/Controllers/Admin/DepartmentController.php`
- Create: `app/Http/Controllers/UserDirectoryController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/departments/index.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `public/css/responsive-admin.css`
- Test: `tests/Feature/DepartmentWorkspaceV2Test.php`

**Interfaces:**
- `GET /usuarios/buscar?q=` retorna no máximo 20 usuários ativos `{id,name,email}`.
- Associação administrativa recebe `user_id` e `access_level`.
- Acompanhamento próprio recebe boolean `follow_department` e só aceita `view|edit`.

- [ ] Escrever testes de listagem/resumo, associação, auto acompanhamento e proteção de contagens para `send`.
- [ ] Confirmar RED no CI.
- [ ] Implementar rotas, ações e tela expansível de departamentos.
- [ ] Adicionar busca dinâmica de usuários e menu Departamentos para usuários associados.
- [ ] Executar testes e regressões de UI.

### Task 3: Criação simplificada e participantes por autocomplete

**Files:**
- Modify: `app/Http/Controllers/TicketController.php`
- Modify: `app/Http/Controllers/TicketAssignmentController.php`
- Modify: `app/Http/Controllers/TicketParticipantController.php`
- Modify: `app/Http/Controllers/TicketRoutingController.php`
- Modify: `resources/views/tickets/create.blade.php`
- Modify: `resources/views/tickets/show.blade.php`
- Create: `public/js/user-autocomplete.js`
- Test: `tests/Feature/TicketCreationV2Test.php`

**Interfaces:**
- Criação aceita `requester_name`, `requester_email`, `requester_user_id`, `assignee_id`, `collaborator_ids[]`, `follower_ids[]`.
- Sistema integrado aceita usuário externo selecionado ou solicitante manual e tenta correspondência exata de e-mail no diretório.
- Criador só mantém acesso se outra regra (`follower`, colaborador, responsável, solicitante, departamento ou view_all) permitir.

- [ ] Escrever testes para criador sem acesso posterior, criador como seguidor, destino autorizado e vínculo por e-mail de integração.
- [ ] Confirmar RED.
- [ ] Implementar controller e UI simplificada “Minha equipe / Uma empresa/cliente”.
- [ ] Trocar selects de responsável/participantes por autocomplete em criação e detalhe.
- [ ] Atualizar assumir/reatribuir/encaminhar para respeitar `DepartmentAccess` e disparar eventos necessários.
- [ ] Executar testes da criação, visibilidade, assignment e routing.

### Task 4: Notificações de abertura, funções, comentários e alterações

**Files:**
- Create: `app/Notifications/TicketActivityNotification.php`
- Create: `app/Services/TicketNotifier.php`
- Modify: `app/Http/Controllers/TicketController.php`
- Modify: `app/Http/Controllers/TicketCommentController.php`
- Modify: `app/Http/Controllers/TicketAssignmentController.php`
- Modify: `app/Http/Controllers/TicketParticipantController.php`
- Modify: `app/Http/Controllers/TicketRoutingController.php`
- Modify: `app/Http/Controllers/TicketLifecycleController.php`
- Modify: `app/Http/Controllers/Api/V1/IntegrationTicketController.php`
- Modify: `resources/views/tickets/show.blade.php`
- Test: `tests/Feature/TicketNotificationsV2Test.php`

**Interfaces:**
- `TicketNotifier::opened(Ticket $ticket, ?User $creator): void`
- `TicketNotifier::roleChanged(Ticket $ticket, User $target, string $role, bool $added): void`
- `TicketNotifier::departmentEvent(Ticket $ticket, string $event, ?User $actor = null): void`
- `TicketNotifier::comment(Ticket $ticket, User $actor, array $groups): void`
- `TicketNotifier::requesterChanged(Ticket $ticket, ?User $actor, string $summary): void`

- [ ] Escrever testes de deduplicação, confirmação ao criador/solicitante, notificações automáticas de função, acompanhamento de departamento e caixas de comentário.
- [ ] Confirmar RED.
- [ ] Implementar Notification e serviço central com deduplicação por e-mail e exclusão do ator.
- [ ] Integrar controllers e checkboxes, mantendo `notify_requester` marcado por padrão em alterações públicas.
- [ ] Executar testes de notificação e regressões.

### Task 5: Portal assinado do solicitante e integração

**Files:**
- Create: `app/Http/Controllers/RequesterPortalController.php`
- Create: `resources/views/requester/index.blade.php`
- Create: `resources/views/requester/show.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Services/TicketNotifier.php`
- Modify: `app/Http/Controllers/Api/V1/IntegrationTicketController.php`
- Test: `tests/Feature/RequesterPortalTest.php`
- Test: `tests/Feature/IntegrationApiV1SafeTest.php`

**Interfaces:**
- Links assinados usam `requester_email` normalizado como parâmetro.
- Portal lista tickets por `requester_email` sem filtrar concluídos.
- Portal exibe apenas comentários públicos e POST assinado permite comentário público.
- Manager da integração mantém visão/comentário de todos os tickets do `system_id`; usuário comum continua limitado ao `external_requester_id`.

- [ ] Escrever testes de acesso assinado, rejeição de assinatura inválida, listagem de fechados e comentário público.
- [ ] Confirmar RED.
- [ ] Implementar portal e links nas notificações.
- [ ] Garantir integração manager/user e solicitante manual/vinculado.
- [ ] Executar testes do portal e API.

### Task 6: Filtros, UI final, regressões e publicação

**Files:**
- Modify: `app/Http/Controllers/TicketBoxController.php`
- Modify: `resources/views/boxes/index.blade.php`
- Modify: `resources/views/tickets/show.blade.php`
- Modify: `public/css/app.css`
- Modify: `public/css/responsive-shell.css`
- Tests: suíte completa `php artisan test` via GitHub Actions

**Interfaces:**
- Minha Caixa expõe filtro de departamento apenas com departamentos `view|edit` do usuário.
- Caixa de departamento exige `view|edit` ou `tickets.view_all`.
- Controles de edição no ticket respeitam nível do departamento mais permissão geral.

- [ ] Escrever/ajustar testes de filtro e autorização final.
- [ ] Confirmar RED se houver comportamento ainda ausente.
- [ ] Implementar filtros e acabamento responsivo.
- [ ] Rodar suíte completa no CI e revisar diff do PR.
- [ ] Corrigir qualquer regressão até CI verde.
- [ ] Abrir PR, revisar escopo, fazer squash merge em `main` e acompanhar o workflow de deploy até a verificação de produção ficar verde.