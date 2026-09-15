# Configuração de E-mail pelo Super Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que o Super Admin configure e teste o SMTP do Sutoorii Tickets pela interface, com senha criptografada no banco e fallback para `.env`.

**Architecture:** Criar um serviço `MailSettings` responsável por persistir, descriptografar e aplicar a configuração SMTP em runtime. Um controller administrativo cuidará da tela, salvamento e envio de teste; um middleware aplicará a configuração efetiva antes das requisições autenticadas e de autenticação que podem enviar e-mail.

**Tech Stack:** Laravel 12, PHP 8.2, tabela `settings`, `Crypt`, `Mail`, Blade, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-15-configuracao-email-super-admin-design.md`

## Global Constraints

- A senha SMTP nunca pode ser exibida, auditada em claro ou commitada.
- Acesso exige simultaneamente `users.manage` e `permissions.manage`.
- Sem configuração persistida, manter o SMTP vindo do `.env`.
- Não criar migration nova.
- O envio de teste deve ter rate limit.

---

### Task 1: Serviço de configuração SMTP

**Files:**
- Create: `app/Services/MailSettings.php`
- Test: `tests/Feature/AdminMailSettingsTest.php`

**Interfaces:**
- Produces: `MailSettings::values(): array`, `MailSettings::save(array $data): void`, `MailSettings::apply(): void`, `MailSettings::hasStoredPassword(): bool`.

- [ ] **Step 1: Write the failing test**

Criar testes que salvem host/porta/usuário/remetente/senha, confirmem que a senha não está em claro na tabela `settings`, confirmem que `apply()` altera `config('mail.mailers.smtp.*')`, e confirmem fallback para `.env` sem registros no banco.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminMailSettingsTest`
Expected: FAIL porque `MailSettings` ainda não existe.

- [ ] **Step 3: Write minimal implementation**

Implementar `MailSettings` usando `DB::table('settings')`, `Crypt::encryptString()` e `Crypt::decryptString()`. `save()` só substitui a senha quando `password` não estiver vazio. `apply()` deve configurar `mail.mailers.smtp.host`, `port`, `username`, `password`, `scheme` e `mail.from`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AdminMailSettingsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit: `feat: adicionar serviço de configuração smtp`

### Task 2: Página administrativa e permissões

**Files:**
- Create: `app/Http/Controllers/Admin/MailSettingsController.php`
- Create: `resources/views/admin/settings/mail.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/AdminMailSettingsTest.php`

**Interfaces:**
- Consumes: `MailSettings` da Task 1.
- Produces: rotas `admin.settings.mail.edit`, `admin.settings.mail.update`, `admin.settings.mail.test`.

- [ ] **Step 1: Write the failing test**

Adicionar testes para: Super Admin abrir a tela; administrador parcial receber 403; formulário nunca conter a senha armazenada; salvar configuração; auditoria sem senha em claro.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminMailSettingsTest`
Expected: FAIL por rotas/controller/view inexistentes.

- [ ] **Step 3: Write minimal implementation**

Criar controller com verificação das duas permissões, validação de host, porta 1-65535, encryption `ssl|tls|none`, usuário opcional, senha opcional, remetente válido e nome. Registrar auditoria `mail.settings.updated` apenas com campos não sensíveis e `password_changed` booleano. Adicionar link `E-mail` na Administração somente para Super Admin completo.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AdminMailSettingsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit: `feat: adicionar configuração de email ao super admin`

### Task 3: Aplicação automática e envio de teste

**Files:**
- Create: `app/Http/Middleware/ApplyMailSettings.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Http/Controllers/Admin/MailSettingsController.php`
- Test: `tests/Feature/AdminMailSettingsTest.php`

**Interfaces:**
- Consumes: `MailSettings::apply()`.
- Produces: configuração SMTP ativa antes dos fluxos de cadastro, recuperação, verificação e painel administrativo.

- [ ] **Step 1: Write the failing test**

Adicionar teste de middleware que comprove aplicação em uma requisição e teste de `Mail::fake()` para o botão de envio de teste. Testar também que a rota de teste possui throttle.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminMailSettingsTest`
Expected: FAIL porque middleware/teste ainda não existem.

- [ ] **Step 3: Write minimal implementation**

Registrar middleware global web que chama `MailSettings::apply()` apenas quando a tabela `settings` estiver disponível. No controller, aplicar settings e usar `Mail::raw()` para enviar uma mensagem simples ao endereço informado. Capturar `Throwable`, registrar erro sem credenciais e retornar mensagem amigável. Aplicar `throttle:3,1` na rota de teste.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AdminMailSettingsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit: `feat: aplicar smtp dinamico e teste de envio`

### Task 4: Verificação completa e publicação

**Files:**
- No new production files.

- [ ] **Step 1: Run full test suite**

Run: `php artisan test`
Expected: zero failures.

- [ ] **Step 2: Validate routes**

Run: `php artisan route:list`
Expected: três rotas administrativas de e-mail presentes.

- [ ] **Step 3: Review diff for secrets**

Confirmar que nenhum valor de senha fornecido pelo usuário aparece no diff ou no histórico da branch.

- [ ] **Step 4: Create PR and merge after green CI**

Abrir PR para `main`, revisar diff e aguardar CI verde antes do merge.

- [ ] **Step 5: Verify production deploy**

Confirmar workflow de deploy verde, SHA publicado, `/up` e página inicial respondendo.
