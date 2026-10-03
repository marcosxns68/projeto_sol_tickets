# Departamentos em Árvore Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformar a tela de Departamentos em uma árvore compacta com pastas/subpastas, preservando integralmente a tela atual do ticket, o modelo de autorização por departamento e os canais atuais de notificação.

**Architecture:** O departamento permanece como fronteira de autorização e notificação. Uma nova entidade `TicketFolder` organiza tickets recursivamente dentro do departamento sem ACL própria; `Ticket.folder_id` é opcional. O acompanhamento sai da pivot de membership para `department_subscriptions`, permitindo Super Admin/usuários com acesso global seguirem caixas sem associação artificial, com backfill compatível dos seguidores atuais.

**Tech Stack:** Laravel/PHP 8.2+, Eloquent, Blade, JavaScript/CSS nativos, MySQL/MariaDB em produção, SQLite em memória nos testes, GitHub Actions para CI/deploy.

**Spec:** `docs/superpowers/specs/2026-10-02-departamentos-arvore-design.md`

## Global Constraints

- Departamento continua sendo a unidade de autorização e notificação; pastas nunca terão ACL própria.
- Triagem permanece protegida e não aceita pastas nesta versão.
- Tickets existentes e integrações externas sem `folder_id` continuam válidos na raiz do departamento.
- A tela completa atual do ticket não pode ser substituída por painel simplificado.
- Alterar somente pasta dentro do mesmo departamento não dispara evento/notificação de encaminhamento.
- As cores de prioridade na árvore são: baixa `#2F855A`, normal `#6D28D9`, alta `#D97706`, urgente `#DC2626`, com informação acessível além da cor.
- Acesso deve ser apresentado como `Nível 1 — Enviar`, `Nível 2 — Visualizar`, `Nível 3 — Editar`, mantendo internamente `send/view/edit`.
- Migrations devem ser aditivas/compatíveis e o backfill não pode perder preferências atuais.

## Review Focus

- Pasta recebida por request pertence a outro departamento: rejeitar sem modificar o ticket.
- Tentativa de criar ciclo de subpastas: rejeitar inclusive ciclos indiretos.
- Super Admin sem linha em `department_user_access`: pode acompanhar e receber canais sem ganhar membership falso.
- Ticket muda de departamento com `folder_id` antigo: limpar pasta ou aceitar apenas pasta explicitamente válida no novo departamento.
- Usuário com nível `send`: pode criar/enviar ticket, mas não pode inspecionar árvore de tickets nem forçar operações de pasta por URL.

---

### Task 1: Persistência de departamentos, pastas e tickets

**Files:**
- Create: `database/migrations/2026_10_02_000001_add_description_to_departments_table.php`
- Create: `database/migrations/2026_10_02_000002_create_ticket_folders_table.php`
- Create: `database/migrations/2026_10_02_000003_add_folder_id_to_tickets_table.php`
- Create: `app/Models/TicketFolder.php`
- Modify: `app/Models/Department.php`
- Modify: `app/Models/Ticket.php`
- Test: `tests/Feature/TicketFolderStructureTest.php`
- Test: `tests/Feature/DeploymentMigrationTest.php`
- Test: `tests/Feature/ProductionRollbackMigrationTest.php`

**Interfaces:**
- Produces: `Department::folders()`, `Ticket::folder()`, `TicketFolder::department()`, `TicketFolder::parent()`, `TicketFolder::children()`, `TicketFolder::tickets()`.
- Produces: nullable `tickets.folder_id` and optional `departments.description`.

- [ ] **Step 1: Write failing persistence tests** for root folder, subfolder, `folder_id = null`, same-department FK semantics, migration up/down and preserving existing tickets.
- [ ] **Step 2: Run targeted tests** with `php artisan test --filter=TicketFolderStructureTest` and confirm failure before schema/model code.
- [ ] **Step 3: Add additive migrations and Eloquent relationships**; keep legacy ticket rows rooted with `folder_id = null` and use null-on-delete semantics for folder references without implementing folder deletion UI.
- [ ] **Step 4: Add model-level helper validation boundary** so folder ownership can be checked centrally by later controllers; ensure recursive relations do not auto-load unbounded trees.
- [ ] **Step 5: Run persistence + migration tests** and confirm green.
- [ ] **Step 6: Commit** `feat: add ticket folder persistence`.

### Task 2: Subscription model independent of department membership

**Files:**
- Create: `database/migrations/2026_10_02_000004_create_department_subscriptions_table.php`
- Create: `app/Models/DepartmentSubscription.php`
- Create: `app/Services/DepartmentSubscriptions.php`
- Modify: `app/Services/DepartmentAccess.php`
- Modify: `app/Http/Controllers/Admin/DepartmentController.php`
- Modify: `app/Services/DepartmentNotifications.php`
- Modify: `app/Services/TicketNotifier.php`
- Modify: `app/Jobs/SendDepartmentWebPush.php`
- Modify: `app/Jobs/SendDepartmentWhatsApp.php`
- Test: `tests/Feature/DepartmentNotificationChannelsTest.php`
- Test: `tests/Feature/DepartmentAccessV2Test.php`
- Test: `tests/Feature/DepartmentSubscriptionMigrationTest.php`

**Interfaces:**
- Produces service methods `DepartmentSubscriptions::for(User $user, Department $department): ?DepartmentSubscription`, `save(User $user, Department $department, bool $email, bool $whatsapp, bool $push): DepartmentSubscription`, `followedDepartmentIds(User $user): array`.
- Consumes: `DepartmentAccess::canView()` as the authorization source; does not create `department_user_access` rows.

- [ ] **Step 1: Write failing backfill and authorization tests** proving old `follow_department` rows preserve channels/`last_seen_at`, Super Admin follows without membership, and non-viewer gets 403.
- [ ] **Step 2: Run targeted subscription/notification tests** and confirm expected failures.
- [ ] **Step 3: Add `department_subscriptions` migration with unique `(user_id, department_id)` and idempotent backfill** from existing eligible pivot rows; leave legacy columns intact for rollback compatibility.
- [ ] **Step 4: Implement `DepartmentSubscriptions` service and switch follow/mark-seen reads/writes** to subscriptions while authorization stays in `DepartmentAccess`.
- [ ] **Step 5: Switch e-mail/database/WhatsApp/Web Push recipient discovery** to subscriptions and preserve de-duplication against assignee/participants/requester.
- [ ] **Step 6: Run DepartmentAccess, DepartmentNotificationChannels, PwaWebPush, AssigneeReplyWhatsApp and notification regression tests**.
- [ ] **Step 7: Commit** `feat: decouple department subscriptions from membership`.

### Task 3: Folder creation and secure tree data

**Files:**
- Create: `app/Http/Controllers/TicketFolderController.php`
- Create: `app/Services/TicketFolderTree.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Admin/DepartmentController.php`
- Test: `tests/Feature/TicketFolderManagementTest.php`
- Test: `tests/Feature/DepartmentWorkspaceV2Test.php`

**Interfaces:**
- Produces: `TicketFolderTree::forDepartment(Department $department): Collection` ordered by `position`, then name.
- Produces routes to create folders in a department/parent context; server validates department ownership and prevents Triagem/cycles.
- Consumes: `DepartmentAccess::canEdit()` for folder management and `DepartmentAccess::canView()` for tree visibility.

- [ ] **Step 1: Write failing request tests** for root/subfolder creation, cross-department parent, Triagem prohibition, send-only denial, view-only denial for mutation, edit/global manage success, and cycle protection in any update path exposed.
- [ ] **Step 2: Run targeted folder-management tests** and confirm failures.
- [ ] **Step 3: Implement folder controller/service/routes** with explicit department validation and no destructive delete endpoint.
- [ ] **Step 4: Expand department index query/view-model** to supply root tickets, recursive folders, counts, subscription state and permission flags without N+1 queries for each row.
- [ ] **Step 5: Run folder + workspace tests** and confirm green.
- [ ] **Step 6: Commit** `feat: add secure department folder tree`.

### Task 4: Compact department tree UI and department administration

**Files:**
- Modify: `resources/views/admin/departments/index.blade.php`
- Modify: `resources/views/admin/departments/edit.blade.php`
- Modify: `app/Http/Controllers/Admin/DepartmentController.php`
- Modify: `public/css/app.css` or the existing department-specific stylesheet location used by the project
- Test: `tests/Feature/DepartmentWorkspaceV2Test.php`
- Test: `tests/Feature/AdminDepartmentRoleTest.php`
- Test: `tests/Feature/AdminVisualConsistencyTest.php`

**Interfaces:**
- Consumes tree/subscription data from Tasks 2–3.
- Produces UI behavior: row click expands; `+` menu exposes exactly `Nova tarefa`/`Nova pasta`; `⋯` exposes actions by authorization.

- [ ] **Step 1: Write failing UI assertions** for compact hierarchy, `+ Novo departamento`, absence of the removed helper sentence, description text, `+` menu labels, `⋯` actions, access-level copy and priority accessibility labels/classes.
- [ ] **Step 2: Run targeted view tests** and confirm failures.
- [ ] **Step 3: Add department `description` validation/persistence** to store/update and render it truncated below the name; keep Triagem protected from edit.
- [ ] **Step 4: Replace card-heavy department presentation with compact recursive tree markup** preserving existing navigation, PWA shell and responsive behavior.
- [ ] **Step 5: Implement compact `+` and `⋯` menus** so action clicks never toggle expansion; `Pessoas e acessos` shows the Nível 1/2/3 hierarchy and `Acompanhar e notificações` uses subscriptions.
- [ ] **Step 6: Render ticket priority indicator with exact colors plus `aria-label`/tooltip text**; preserve existing operational order for tickets in each level.
- [ ] **Step 7: Add mobile CSS** with reduced indentation and secondary text truncation/omission; keep controls usable without large cards.
- [ ] **Step 8: Run workspace/admin/mobile visual regression tests**.
- [ ] **Step 9: Commit** `feat: render compact department tree`.

### Task 5: Create tasks in context and move tickets between folders

**Files:**
- Modify: `app/Http/Controllers/TicketController.php`
- Modify: `app/Http/Controllers/TicketRoutingController.php`
- Modify: `resources/views/tickets/create.blade.php`
- Modify: `resources/views/tickets/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TicketCreationV2Test.php`
- Test: `tests/Feature/TicketRoutingTest.php`
- Test: `tests/Feature/TicketWorkspaceTest.php`
- Test: `tests/Feature/TicketFolderRoutingTest.php`

**Interfaces:**
- Consumes: optional request `folder_id`; `null` means `Raiz do departamento`.
- Produces: existing ticket show route unchanged; `Atendimento` accepts department + assignee + optional folder in one operation.

- [ ] **Step 1: Write failing tests** proving normal ticket creation stays root-only by default, contextual `+ Nova tarefa` can preselect folder, same-department folder moves do not change routing status or emit department-entered notifications, cross-department moves validate the new folder, and invalid folder requests leave the ticket untouched.
- [ ] **Step 2: Run targeted ticket creation/routing tests** and confirm failures.
- [ ] **Step 3: Add optional folder support to ticket creation** without requiring integrations or the normal form to submit it; reject folders outside the chosen department.
- [ ] **Step 4: Extend routing update atomically**: same department + folder change updates only `folder_id`; department change clears old folder unless a valid target folder is explicitly supplied; Triagem always forces `folder_id = null`.
- [ ] **Step 5: Preserve notification semantics** so folder-only moves record organization history if desired but never call department `entered` or requester-forwarded notifications.
- [ ] **Step 6: Add `Pasta / subpasta` to the existing `Atendimento` disclosure on full ticket page** with explicit `Raiz do departamento`; do not remove or redesign any existing ticket sections/actions.
- [ ] **Step 7: Ensure tree ticket links use existing `tickets.show`** and no reduced ticket view exists.
- [ ] **Step 8: Run TicketWorkspace/Lifecycle/Comment/Attachment/Checklist/Participant/WhatsApp regression tests relevant to the full page**.
- [ ] **Step 9: Commit** `feat: move tickets within department folders`.

### Task 6: Integration and notification compatibility

**Files:**
- Modify only if required by failing tests: `app/Http/Controllers/Api/*`, `app/Services/DepartmentNotifications.php`, `app/Services/TicketNotifier.php`
- Test: `tests/Feature/IntegrationApiV1SafeTest.php`
- Test: `tests/Feature/InternalIntegrationTicketsTest.php`
- Test: `tests/Feature/DepartmentNotificationChannelsTest.php`
- Test: `tests/Feature/PwaWebPushTest.php`
- Test: `tests/Feature/EmailLanguageAndDepartmentNotificationTest.php`

**Interfaces:**
- Existing integration payloads remain unchanged; `folder_id` is not required or exposed as mandatory.
- Department notification event keys and de-duplication remain compatible.

- [ ] **Step 1: Add/extend regression tests** proving integrated tickets without folder land at department root, existing list/comment APIs still work, legacy subscribers still receive selected channels after backfill, and overlapping roles do not duplicate alerts.
- [ ] **Step 2: Run integration + notification tests**; make only compatibility fixes demonstrated by failures.
- [ ] **Step 3: Re-run all notification-channel tests** including WhatsApp and Web Push jobs.
- [ ] **Step 4: Commit** `test: preserve integrations and department alerts` (plus minimal fixes if needed).

### Task 7: Full verification, PR, merge and production deploy

**Files:**
- Modify: feature branch documentation only if verification uncovers an implementation-specific operational note.

**Interfaces:**
- Consumes all prior tasks.
- Produces a mergeable feature branch with additive migrations and green CI.

- [ ] **Step 1: Run full suite** with `php artisan test`; expected: all tests pass.
- [ ] **Step 2: Run syntax/static checks used by repository CI**, including PHP syntax, Blade compilation and service-worker/JS checks already defined by workflow.
- [ ] **Step 3: Inspect diff** for accidental removal of current ticket sections, legacy integration fields, notification templates or Triagem guards.
- [ ] **Step 4: Open PR** summarizing schema additions, backfill, permissions, tree UX and regression coverage.
- [ ] **Step 5: Wait for CI and fix any failure on the feature branch**; do not merge red CI.
- [ ] **Step 6: Merge only after green CI** to `main`, triggering the repository's current `Build e deploy` workflow.
- [ ] **Step 7: Verify production workflow conclusion is `success`** and inspect the deployed Departamentos page plus one real ticket route for the preserved full-ticket layout.
- [ ] **Step 8: Report deployment result and any external-runtime item that cannot be proven from GitHub alone**.
