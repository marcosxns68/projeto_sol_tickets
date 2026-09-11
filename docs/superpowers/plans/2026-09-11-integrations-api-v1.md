# Sutoorii Tickets Integrations API v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar a base genérica de integrações do Sutoorii Tickets com uma chave por origem, API v1 isolada por chave, painel administrativo e webhooks assinados com retry.

**Architecture:** A entidade técnica existente `systems` passa a representar uma Integração e deixa de depender de empresa. Um middleware resolve a integração a partir do Bearer token e injeta o contexto externo; todos os endpoints partem obrigatoriamente desse `system_id`. Webhooks de saída ficam persistidos em uma fila própria processada de forma idempotente por command/cron, sem depender de daemon.

**Tech Stack:** Laravel 12/PHP 8.2, Eloquent, Blade, SQLite nos testes, MySQL em produção, Laravel HTTP client, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-11-integrations-api-v1-design.md`

## Global Constraints

- Não cadastrar empresa na lógica nova de integrações.
- Uma integração = uma chave = um escopo isolado de tickets.
- Usuário comum vê só os próprios tickets; `manager` vê todos daquela chave.
- Número público continua `AAMM0000`.
- Nota interna nunca é exposta por API/webhook.
- Chave API só é mostrada em texto puro no momento de criação/regeneração; banco guarda SHA-256.
- Webhook usa segredo separado e assinatura HMAC-SHA256.
- Migrações são aditivas e não apagam dados existentes.
- Aplicar TDD e validar CI antes de promover para `main`.

---

### Task 1: Banco, modelos e autenticação da integração

**Files:**
- Create: `database/migrations/2026_09_11_000004_add_integrations_api_foundation.php`
- Modify: `app/Models/ConnectedSystem.php`
- Modify: `app/Models/Ticket.php`
- Create: `app/Models/WebhookDelivery.php`
- Create: `app/Http/Middleware/AuthenticateIntegration.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/IntegrationApiAuthenticationTest.php`

**Interfaces:**
- Produces: request attributes `integration`, `external_user_id`, `external_user_role`.
- Produces: `ConnectedSystem::issueApiToken(): string`, `ConnectedSystem::matchesToken(string): bool`.
- Produces: `Ticket::scopeForExternalActor(Builder, ConnectedSystem, string, string)`.

- [ ] Write failing tests for valid/invalid/revoked/inactive keys, user-vs-manager scoping, `company_id` no longer required, and uniqueness of `(system_id, external_reference)`.
- [ ] Push tests and confirm CI fails for missing middleware/schema.
- [ ] Add additive migration: `systems.company_id` nullable, `department_id`, activity/webhook observability fields, encrypted webhook secret storage field, ticket metadata JSON, composite unique index, and `webhook_deliveries`.
- [ ] Add model casts/relations/token helpers and middleware that hashes the Bearer token, rejects inactive integration, validates `X-External-User-Id` and `X-External-User-Role`, and updates `last_api_activity_at`.
- [ ] Register middleware alias `integration` in `bootstrap/app.php`.
- [ ] Run CI and require green for Task 1.

### Task 2: Painel administrativo de Integrações

**Files:**
- Create: `app/Http/Controllers/Admin/IntegrationController.php`
- Create: `resources/views/admin/integrations/index.blade.php`
- Create: `resources/views/admin/integrations/form.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/AdminIntegrationTest.php`

**Interfaces:**
- Consumes: `integrations.manage`, `ConnectedSystem::issueApiToken()`.
- Produces routes under `/admin/integracoes` for list/create/edit/update/token rotation/webhook-secret rotation/manual retry.

- [ ] Write failing tests for permission gating, create/edit/disable, one-time API token display, token rotation invalidating old token, webhook secret rotation without exposing stored plaintext, and navigation visibility.
- [ ] Confirm RED in CI.
- [ ] Implement controller with audit entries that never store plaintext credentials.
- [ ] Implement clean responsive Blade screens matching the current admin visual system.
- [ ] Add permission-gated navigation item `Integrações`.
- [ ] Run CI and require green for Task 2.

### Task 3: API v1 de tickets

**Files:**
- Create: `app/Http/Controllers/Api/V1/TicketController.php`
- Create: `app/Http/Controllers/Api/V1/TicketCommentController.php`
- Create: `app/Http/Controllers/Api/V1/TicketLifecycleController.php`
- Create: `app/Http/Controllers/Api/V1/TicketActivityController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/IntegrationTicketApiTest.php`

**Interfaces:**
- Endpoints: `POST/GET /api/v1/tickets`, `GET /api/v1/tickets/{number}`, `POST .../comments`, `POST .../close`, `POST .../reopen`, `GET .../activity`.
- All endpoints consume middleware attributes from Task 1.

- [ ] Write failing tests for create/list/show isolation, manager visibility, idempotent `external_reference`, `AAMM0000`, public comments only, lifecycle rules, activity filtering, 404 non-disclosure and validation JSON.
- [ ] Confirm RED in CI.
- [ ] Implement API resource payloads without internal notes/checklist/audit/assignee private details.
- [ ] Implement create using integration default department and status `new`.
- [ ] Implement namespaced external message id and public activity feed.
- [ ] Add per-integration throttling at 120 requests/minute.
- [ ] Run CI and require green for Task 3.

### Task 4: Anexos externos

**Files:**
- Create: `app/Http/Controllers/Api/V1/TicketAttachmentController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/IntegrationAttachmentApiTest.php`

**Interfaces:**
- Endpoint: `POST /api/v1/tickets/{number}/attachments`.
- Reuses existing attachment table/storage and never returns disk/path.

- [ ] Write failing tests for authorization, allowed MIME/size, stored metadata, and response not leaking physical path.
- [ ] Confirm RED in CI.
- [ ] Implement validated upload to the configured private/public storage convention already used by the project.
- [ ] Return only public attachment metadata/identifier.
- [ ] Run CI and require green for Task 4.

### Task 5: Webhooks assinados e retry

**Files:**
- Create: `app/Services/WebhookDispatcher.php`
- Create: `app/Services/WebhookDeliveryProcessor.php`
- Create: `app/Console/Commands/ProcessIntegrationWebhooks.php`
- Modify: `routes/console.php`
- Modify: `app/Http/Controllers/TicketCommentController.php`
- Modify: `app/Http/Controllers/TicketLifecycleController.php`
- Test: `tests/Feature/IntegrationWebhookTest.php`

**Interfaces:**
- Produces `WebhookDispatcher::queue(Ticket $ticket, string $event, array $payload): ?WebhookDelivery`.
- Produces command `tickets:webhooks-process` safe for cron/re-entry.

- [ ] Write failing tests for public comment/status webhook creation, internal note suppression, HMAC headers, HTTPS/local-network validation, retry scheduling, max 5 attempts, success marking and no webhook echo for actions that originate from the same integration API.
- [ ] Confirm RED in CI.
- [ ] Implement queued persistence and processor using Laravel HTTP client.
- [ ] Sign raw JSON with `X-Sutoorii-Event`, `X-Sutoorii-Delivery`, `X-Sutoorii-Timestamp`, `X-Sutoorii-Signature`.
- [ ] Implement progressive retry and manual requeue support.
- [ ] Hook public internal-UI events into dispatcher; never hook internal notes.
- [ ] Run CI and require green for Task 5.

### Task 6: Integração final, documentação e produção

**Files:**
- Modify: `docs/superpowers/specs/2026-09-11-integrations-api-v1-design.md` only if implementation requires a documented ruling.
- Create/Modify: tests above as needed for regressions.

- [ ] Run full `php artisan test` and `php artisan route:list` in CI.
- [ ] Verify branch diff against `main` contains only intended integration work and docs/tests.
- [ ] Verify migration is additive and deploy bootstrap will execute it.
- [ ] Fast-forward `main` only after all CI checks are green.
- [ ] Verify the `main` deploy workflow completes successfully before reporting production ready.
