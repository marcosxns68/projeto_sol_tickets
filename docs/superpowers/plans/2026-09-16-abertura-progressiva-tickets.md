# Abertura Progressiva de Tickets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tornar a abertura de tickets simples e progressiva, exibindo somente tipos e campos pertinentes às permissões do usuário e ao fluxo selecionado.

**Architecture:** A autorização continua no backend existente. A simplificação fica concentrada na view de criação e em JavaScript de apresentação, com campos ocultos também desabilitados para evitar envio de dados de outro fluxo. Testes Feature validam o HTML inicial por perfil de permissão e os contratos necessários para o comportamento progressivo.

**Tech Stack:** Laravel/PHP, Blade, JavaScript vanilla, PHPUnit Feature tests.

**Spec:** `docs/superpowers/specs/2026-09-16-abertura-progressiva-tickets-design.md`

## Global Constraints
- `tickets.create` permanece a permissão-base.
- `tickets.create_integration` apenas habilita o fluxo externo adicional.
- Sem permissão de integração, nenhum controle ou texto de integração deve ser renderizado.
- Campos comuns continuam funcionais e a autorização de backend não é afrouxada.
- Campos ocultos de fluxos não selecionados devem ficar desabilitados.

---

### Task 1: Contrato visual por permissão e fluxo

**Files:**
- Modify: `tests/Feature/TicketCreationV2Test.php`
- Modify: `tests/Feature/IntegrationTicketPermissionTest.php`

**Interfaces:**
- Consumes: permissões `tickets.create` e `tickets.create_integration`.
- Produces: testes que definem o HTML inicial esperado para usuários internos e usuários com integração.

- [ ] **Step 1: Escrever testes que falham**

Adicionar cenários que exijam:
```php
$response->assertDontSee('Tipo de chamado');
$response->assertDontSee('Empresa / sistema integrado');
$response->assertSee('Atribuição inicial (opcional)');
$response->assertSee('data-create-progressive', false);
```
para usuário somente interno; e, para usuário com integração:
```php
$response->assertSee('Tipo de chamado');
$response->assertSee('Minha equipe');
$response->assertSee('Empresa / cliente');
$response->assertSee('data-integration-section', false);
$response->assertSee('data-general-requester', false);
$response->assertSee('data-external-requester', false);
```

- [ ] **Step 2: Rodar CI da branch e confirmar RED**

Esperado: falhas somente nas novas expectativas de layout progressivo.

- [ ] **Step 3: Ajustar os testes antigos que descrevem o layout anterior**

Preservar verificações de segurança e de departamentos enviáveis; remover expectativas que obriguem os três pickers opcionais a estarem expandidos na tela principal.

- [ ] **Step 4: Commitar testes RED**

Commit: `test: definir abertura progressiva de tickets`.

---

### Task 2: Implementar view progressiva

**Files:**
- Modify: `resources/views/tickets/create.blade.php`

**Interfaces:**
- Consumes: `$canCreateIntegrationTicket`, `$departments`, `$integrations`, `old()` e o componente de busca de usuários existente.
- Produces: atributos `data-create-progressive`, `data-integration-section`, `data-general-requester`, `data-external-requester` e bloco `<details>` `Atribuição inicial (opcional)`.

- [ ] **Step 1: Simplificar o cabeçalho e destino**

Quando `$canCreateIntegrationTicket` for falso, renderizar apenas um `input type="hidden" name="source_mode" value="internal"` e não renderizar cards de tipo. Quando for verdadeiro, renderizar os dois cards sob `Tipo de chamado`.

- [ ] **Step 2: Separar o fluxo externo**

Colocar empresa/sistema e tipo de solicitante dentro de um bloco com `data-integration-section`. Colocar nome/e-mail manual em `data-general-requester` e a busca externa em `data-external-requester`.

- [ ] **Step 3: Reorganizar campos comuns**

Ordem principal: departamento, título, descrição, prioridade, prazo. Mover solicitante interno, responsável, colaboradores e seguidores para `<details>` com título `Atribuição inicial (opcional)`.

- [ ] **Step 4: Implementar sincronização de visibilidade e disabled**

Criar função JS:
```js
function setSectionState(section, visible) {
    if (!section) return;
    section.hidden = !visible;
    section.querySelectorAll('input, select, textarea, button').forEach(control => {
        if (!control.hasAttribute('data-keep-enabled')) control.disabled = !visible;
    });
}
```
Aplicar ao fluxo de integração, solicitante interno, manual e usuário externo. Campos do fluxo oculto não podem ser submetidos.

- [ ] **Step 5: Manter busca externa e busca interna existentes**

A busca externa só pode ser acionada quando `Usuário específico` estiver ativo. O autocomplete interno continua carregado para a seção opcional.

- [ ] **Step 6: Ajustar CSS responsivo**

Reduzir quantidade de cards simultâneos, usar uma coluna em mobile e manter a seção opcional visualmente leve.

- [ ] **Step 7: Rodar CI e confirmar GREEN**

Esperado: novos testes e suíte anterior passando.

- [ ] **Step 8: Commitar implementação**

Commit: `feat: simplificar abertura de tickets`.

---

### Task 3: Revisão e publicação

**Files:**
- Review: diff completo de `feature/abertura-progressiva-tickets` contra `main`.

**Interfaces:**
- Consumes: implementação e testes das tarefas anteriores.
- Produces: PR revisado, merge em `main` e deploy validado.

- [ ] **Step 1: Rodar suíte completa final**

Usar CI da branch e exigir `Executar testes` e `Validar rotas e estratégia de deploy` com sucesso.

- [ ] **Step 2: Revisar diff**

Confirmar que não houve mudança nas regras de autorização, que campos externos continuam protegidos e que não há credenciais ou alterações fora do escopo.

- [ ] **Step 3: Abrir PR e revisar novamente o patch**

Título: `feat: simplificar abertura de tickets`.

- [ ] **Step 4: Squash merge após revisão**

Mesclar somente com head SHA esperado.

- [ ] **Step 5: Acompanhar deploy**

Exigir sucesso nas etapas de publicação, migrations e `Verificar produção` antes de comunicar conclusão.
