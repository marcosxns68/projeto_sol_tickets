# Tickets Internos para Integrações Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que operadores internos criem tickets gerais ou destinados a usuários de sistemas integrados, com seleção remota de usuários e sincronização por webhook.

**Architecture:** O Sutoorii Tickets continua sendo a fonte oficial do ticket. Tickets internos podem carregar `system_id`; quando destinados a pessoa específica, também carregam `external_requester_id`, `requester_name` e `requester_email`. Um serviço backend consulta o diretório do sistema integrado por HMAC sem expor segredo ao navegador. O Estúdio França implementa o primeiro endpoint compatível.

**Tech Stack:** Laravel/PHP no Sutoorii Tickets; PHP/MySQL no Estúdio França; HTTP/HMAC-SHA256; GitHub Actions para testes e deploy.

**Spec:** `docs/superpowers/specs/2026-09-12-tickets-internos-para-integracoes-design.md`

## Global Constraints

- O Sutoorii Tickets permanece a fonte oficial do chamado; não duplicar tickets em outro banco.
- Não sincronizar permanentemente a base de usuários externos.
- Segredos nunca podem chegar ao JavaScript nem aparecer em logs/auditoria.
- Chamados gerais devem funcionar mesmo se o diretório de usuários estiver indisponível.
- Usuários comuns só podem ver tickets cujo `external_requester_id` seja o próprio ID; gestores da integração veem todos os tickets do `system_id`.
- Tickets internos vinculados a integração devem participar dos webhooks públicos relevantes.
- Notificação por e-mail de criação de ticket permanece fora desta etapa.

---

### Task 1: Cobertura RED no Sutoorii Tickets

**Files:**
- Create: `tests/Feature/InternalIntegrationTicketsTest.php`
- Inspect/modify only after RED: `app/Http/Controllers/TicketController.php`, `app/Services/IntegrationWebhookDispatcher.php`

**Interfaces:**
- Produces tests for general integration ticket, external-user ticket, remote directory lookup, default labels/department and webhook eligibility.

- [ ] Escrever testes de feature criando integração ativa, operador com `tickets.create`, status inicial e departamento.
- [ ] Testar que ticket geral interno salva `origin=internal`, `system_id` preenchido e `external_requester_id=null`.
- [ ] Testar que ticket direcionado salva ID/nome/e-mail do usuário externo somente após validação backend.
- [ ] Testar que integração inativa é rejeitada.
- [ ] Testar que departamento e etiquetas padrão da integração são aplicados ao ticket interno vinculado.
- [ ] Testar que o dispatcher aceita ticket `origin=internal` quando há `system_id` e continua ignorando ticket puramente interno.
- [ ] Executar `php artisan test --filter=InternalIntegrationTicketsTest` e confirmar falhas causadas pela ausência da funcionalidade.

### Task 2: Serviço de diretório remoto e rota interna

**Files:**
- Create: `app/Services/IntegrationUserDirectory.php`
- Create: `app/Http/Controllers/IntegrationUserDirectoryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/InternalIntegrationTicketsTest.php`

**Interfaces:**
- Produces `IntegrationUserDirectory::search(ConnectedSystem $system, string $query): array` e `IntegrationUserDirectory::find(ConnectedSystem $system, string $externalId): ?array`.
- Endpoint interno autenticado devolve somente `id`, `name`, `email`.

- [ ] Implementar assinatura HMAC-SHA256 com método, caminho, query canônica e timestamp usando o segredo já compartilhado da integração.
- [ ] Usar HTTP backend com timeout curto e tratamento de indisponibilidade sem expor conteúdo sensível.
- [ ] Validar formato da resposta e limitar a no máximo 20 usuários.
- [ ] Criar rota autenticada para busca, exigindo `tickets.create`, integração ativa e texto mínimo de busca.
- [ ] Fazer os testes de busca passarem.

### Task 3: Formulário e criação de ticket vinculado

**Files:**
- Modify: `app/Http/Controllers/TicketController.php`
- Modify: `resources/views/tickets/create.blade.php`
- Modify/create CSS/JS local apenas se necessário para o autocomplete.
- Test: `tests/Feature/InternalIntegrationTicketsTest.php`

**Interfaces:**
- Form fields: `source_mode`, `system_id`, `integration_target`, `external_requester_id`.
- `integration_target` values: `integration` ou `external_user`.

- [ ] Carregar integrações ativas no formulário.
- [ ] Exibir modo Interno/Integração; quando Integração, exibir integração + chamado geral/usuário específico.
- [ ] Implementar busca sob demanda após 2 caracteres, debounce aproximado de 300 ms e máximo de 20 resultados.
- [ ] No backend, revalidar o usuário selecionado pelo diretório remoto antes de salvar.
- [ ] Para ticket geral, manter campos externos nulos.
- [ ] Para ticket de usuário, persistir ID, nome e e-mail retornados pelo sistema externo.
- [ ] Aplicar departamento e etiquetas padrão da integração.
- [ ] Registrar no evento de criação integração, tipo de destino e usuário externo quando houver.
- [ ] Rodar os testes e manter GREEN.

### Task 4: Webhooks para tickets internos vinculados

**Files:**
- Modify: `app/Services/IntegrationWebhookDispatcher.php`
- Review call sites: controllers de atualização, comentário público e ciclo de vida.
- Test: `tests/Feature/InternalIntegrationTicketsTest.php`

**Interfaces:**
- Dispatcher passa a depender de `system_id` + integração ativa/configurada, não de `origin=integration`.

- [ ] Alterar o critério de elegibilidade do dispatcher.
- [ ] Confirmar que notas internas nunca são despachadas.
- [ ] Confirmar por teste que ticket interno vinculado gera webhook e ticket interno sem integração não gera.

### Task 5: Endpoint de usuários no Estúdio França

**Files:**
- Create in `marcosxns68/projeto_terra`: `api/sutoorii/users.php`
- Reuse existing DB/bootstrap and Sutoorii Tickets integration configuration.
- Add/update a lightweight verification test/script if the repository has testing infrastructure; otherwise validate syntax and endpoint contract in CI/deploy checks.

**Interfaces:**
- `GET /api/sutoorii/users.php?search={texto}` e consulta por ID quando `id={externalId}`.
- Headers required: `X-Sutoorii-Timestamp`, `X-Sutoorii-Signature`.
- Response: `{ "data": [{"id":"...","name":"...","email":"..."}] }`.

- [ ] Criar branch própria no Estúdio França.
- [ ] Validar timestamp dentro da janela acordada e assinatura HMAC com comparação constante.
- [ ] Pesquisar apenas usuários ativos por nome/e-mail; usar limite 20 e consultas parametrizadas.
- [ ] Permitir busca exata por ID para revalidação do ticket.
- [ ] Não retornar telefone, documento, senha ou outros dados.
- [ ] Validar sintaxe PHP e contrato de resposta.

### Task 6: Verificação integrada e publicação

**Files:**
- Review all changed files in both repositories.

- [ ] Rodar suite completa do Sutoorii Tickets e `php artisan route:list --json`.
- [ ] Revisar diff para segredos e nomes internos indevidos.
- [ ] Abrir PR(s), verificar GitHub Actions e corrigir qualquer regressão.
- [ ] Fazer merge somente com CI verde.
- [ ] Confirmar deploy do Sutoorii Tickets e do Estúdio França.
- [ ] Confirmar versão/health checks disponíveis em cada workflow.
- [ ] Reportar ao usuário os links e o comportamento final testado.