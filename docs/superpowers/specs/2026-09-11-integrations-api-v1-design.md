# Sutoorii Tickets — Integrações API v1

Data: 2026-09-11

## Objetivo

Criar a base definitiva para que sistemas externos abram e acompanhem tickets no Sutoorii Tickets sem precisar cadastrar empresas, usuários externos ou gestores dentro do Tickets.

Cada integração cadastrada no Sutoorii Tickets representa uma origem isolada de tickets. A chave da integração identifica e isola essa origem.

O Estúdio França será o primeiro consumidor/piloto, mas a base deve ser genérica para qualquer sistema futuro.

## Decisões aprovadas

- Não haverá cadastro de empresa na lógica nova de integrações.
- Cada cadastro em **Integrações** representa uma origem independente e possui uma chave própria.
- O nome da integração é apenas administrativo; a chave determina a origem técnica.
- Uma mesma empresa pode ter vários sistemas, cada um com sua própria chave e isolamento.
- O mesmo software pode ser usado por várias empresas/bancos distintos; cada instalação recebe uma integração/chave diferente.
- Usuário externo comum vê apenas tickets que ele próprio abriu dentro daquela chave.
- Gestor do sistema vê todos os tickets pertencentes àquela chave, e somente àquela chave.
- O Sutoorii Tickets confia no papel `manager` enviado pelo servidor autenticado da integração.
- O navegador do usuário final nunca recebe a chave da API.
- Notas internas, checklist interno, auditoria, colaboradores internos e demais conteúdo administrativo nunca são expostos à API externa nem aos webhooks.
- O número público oficial do ticket permanece `AAMM0000`: ano com 2 dígitos + mês com 2 dígitos + 4 dígitos aleatórios, com verificação de unicidade.
- `external_reference` é um identificador técnico opcional/oculto, usado para idempotência e correlação com o sistema externo. Ele não substitui o número oficial do ticket.

## Modelo de domínio

### Integração

A tabela atual `systems` continua sendo a entidade técnica da integração. Ela será tratada na interface como **Integração**.

Campos principais:

- `id`
- `name`
- `base_url` opcional
- `department_id` padrão opcional
- `api_token_hash`
- `webhook_url` opcional
- `webhook_secret`
- `active`
- timestamps

O `company_id` existente deixa de ser obrigatório e não participa da lógica nova. A tabela `companies` não será apagada nesta entrega para evitar migração destrutiva em produção. Ela passa a ser legado não utilizado pelo fluxo novo.

### Ticket externo

Tickets criados por integração terão:

- `origin = integration`
- `system_id` obtido exclusivamente pela chave autenticada
- `company_id = null`
- `external_requester_id` vindo do contexto do usuário externo
- `requester_name` e `requester_email` vindos do sistema externo
- `external_reference` técnico opcional
- departamento inicial definido pela integração, quando configurado

A API nunca aceitará `system_id` ou `company_id` do cliente.

## Autenticação e contexto do usuário externo

### Autenticação da integração

Toda requisição da API v1 exige:

`Authorization: Bearer <chave-da-integracao>`

A chave real é gerada com entropia criptográfica e prefixo identificável, por exemplo `st_live_...`.

A chave é mostrada apenas no momento da criação/regeneração. No banco permanece somente SHA-256 em `api_token_hash`.

Integração inativa, chave inexistente ou chave revogada não acessa nenhum dado.

### Contexto do usuário

O servidor integrado informa em cada requisição autenticada:

- `X-External-User-Id`
- `X-External-User-Role: user|manager`

Nome e e-mail podem ser enviados no corpo quando necessários para criação/comentário.

O papel é confiável porque a requisição vem do servidor autenticado da integração. Isso não cria usuário nem privilégio permanente dentro do Sutoorii Tickets.

### Escopo obrigatório

Toda consulta parte obrigatoriamente de `system_id = integração autenticada`.

Depois:

- `user`: acrescenta `external_requester_id = X-External-User-Id`.
- `manager`: não acrescenta filtro de solicitante, mas continua limitado ao mesmo `system_id`.

Não existe operação da API externa que atravesse integrações.

## API v1

Prefixo: `/api/v1`

### Criar ticket

`POST /api/v1/tickets`

Campos aceitos:

- `external_reference` opcional
- `requester_name`
- `requester_email` opcional
- `title`
- `description`
- `priority`: `low|normal|high|urgent`
- `metadata` opcional para contexto técnico seguro

`external_user_id` e papel vêm do contexto autenticado da requisição, não de IDs internos do Tickets.

Se `external_reference` já existir para aquela integração, a API não cria duplicata e devolve o ticket existente.

A combinação `(system_id, external_reference)` deve ser única quando a referência estiver preenchida.

### Listar tickets

`GET /api/v1/tickets`

Retorna somente dados públicos da integração.

- usuário comum: próprios tickets;
- gestor: todos os tickets da integração.

Suporta filtros seguros por status, prioridade e paginação.

### Consultar ticket

`GET /api/v1/tickets/{number}`

O identificador público usado pela rota é o número oficial `AAMM0000`.

A consulta aplica o mesmo isolamento de integração + usuário/gestor antes de retornar dados.

### Criar comentário público

`POST /api/v1/tickets/{number}/comments`

Cria somente comentário `visibility=public` e `source=integration`.

Pode receber `external_message_id` opcional para evitar duplicação em retentativas. Internamente ele deve ser namespaced pela integração antes de usar o campo único `message_id` existente.

A API não possui endpoint para nota interna.

### Anexos

`POST /api/v1/tickets/{number}/attachments`

Aceita anexos somente em ticket que o usuário/gestor possa acessar.

Deve reutilizar as regras internas de armazenamento, tamanho, MIME e expiração. O retorno externo nunca revela caminho físico de armazenamento.

### Fechar e reabrir

`POST /api/v1/tickets/{number}/close`

`POST /api/v1/tickets/{number}/reopen`

A ação respeita as regras de ciclo de vida do Tickets. A API não poderá forçar transições inválidas.

### Atividade pública

`GET /api/v1/tickets/{number}/activity`

Retorna apenas eventos que façam sentido ao usuário externo:

- criação;
- comentários públicos;
- mudanças públicas de status;
- resolução/fechamento/reabertura;
- anexos públicos quando aplicável.

Nunca retorna notas internas, checklist, auditoria ou movimentações administrativas confidenciais.

## Webhooks de saída

Cada integração pode cadastrar `webhook_url` e possui um `webhook_secret` próprio.

Eventos iniciais:

- `ticket.created`
- `ticket.comment.created`
- `ticket.status.changed`
- `ticket.resolved`
- `ticket.closed`
- `ticket.reopened`

Somente eventos públicos originam webhook. Nota interna nunca origina entrega externa.

### Assinatura

Cada POST de webhook inclui timestamp e assinatura HMAC-SHA256 baseada no corpo bruto e no segredo da integração.

Cabeçalhos previstos:

- `X-Sutoorii-Event`
- `X-Sutoorii-Delivery`
- `X-Sutoorii-Timestamp`
- `X-Sutoorii-Signature`

O sistema receptor consegue validar origem e integridade sem conhecer a chave da API.

### Entrega e retry

Criar persistência de entregas de webhook com:

- integração
- evento
- payload
- identificador de entrega
- número de tentativas
- status
- último HTTP status
- último erro
- próxima tentativa
- entregue em

A primeira tentativa pode ser disparada imediatamente. Falhas entram em retry com atraso progressivo e limite definido.

Como a hospedagem atual não deve depender de daemon permanente, a implementação deve oferecer uma rotina/command idempotente de processamento de webhooks pendentes, compatível com execução por cron. O painel também terá ação de reenvio manual de entrega falha.

## Painel administrativo

Nova área **Integrações**, protegida por `integrations.manage`.

Lista:

- nome
- status
- departamento padrão
- webhook configurado ou não
- última comunicação da API
- último webhook/estado

Cadastro/edição:

- nome
- URL base opcional
- departamento padrão opcional
- webhook URL opcional
- ativa/inativa

Ações sensíveis:

- gerar chave;
- regenerar chave;
- gerar/regenerar segredo do webhook;
- desativar integração;
- reenviar webhook falho.

A chave API é exibida somente uma vez. Regenerar invalida imediatamente a anterior.

O segredo do webhook também deve ser protegido e não aparecer integralmente depois da geração.

## Observabilidade e auditoria

Registrar na auditoria administrativa:

- integração criada/editada;
- ativada/desativada;
- chave gerada/regenerada;
- segredo de webhook regenerado.

Nunca gravar a chave API em texto puro no audit log.

Atualizar `last_api_activity_at` em chamadas autenticadas e `last_webhook_*` conforme entregas para diagnóstico no painel.

## Migração segura

Esta entrega deve ser aditiva e compatível com produção:

1. tornar `systems.company_id` nullable;
2. adicionar `department_id` e campos de observabilidade necessários em `systems`;
3. criar tabela de entregas de webhook;
4. adicionar índice/constraint para correlação externa por integração;
5. preservar `companies` e dados existentes sem exclusão destrutiva;
6. tickets internos existentes permanecem inalterados.

Nenhuma migração deve exigir recriar tabelas existentes ou apagar dados de produção.

## Tratamento de erros

Padrão JSON consistente:

- `401`: chave ausente/inválida;
- `403`: integração inativa ou ação sem escopo;
- `404`: ticket inexistente ou não visível para aquele contexto — sem revelar se existe em outra integração;
- `409`: conflito/idempotência não recuperável;
- `422`: validação;
- `429`: reservado para rate limiting.

Erros não devem incluir stack trace, SQL, paths internos ou dados de outra integração.

## Segurança adicional

- Chave API nunca armazenada em texto puro.
- Comparação de tokens por hash.
- Rate limiting por integração na API v1.
- Webhook somente para HTTPS em produção, salvo ambiente explicitamente local/teste.
- URLs de webhook validadas para evitar destinos inválidos; a implementação deve impedir abuso óbvio de SSRF contra endereços locais/metadata quando estiver em produção.
- Payloads externos passam por validação e limites de tamanho.
- `metadata` é armazenado/retornado somente se aprovado pelo schema da API; não deve virar depósito irrestrito de dados sensíveis.

## Testes obrigatórios

Aplicar TDD. Antes de produção, cobrir pelo menos:

1. chave válida autentica a integração correta;
2. chave inválida/revogada/inativa falha;
3. duas chaves nunca enxergam tickets uma da outra;
4. usuário comum vê somente tickets do próprio `external_user_id`;
5. gestor vê todos e somente os tickets daquela chave;
6. criação gera número no padrão `AAMM0000`;
7. `external_reference` repetida na mesma integração não duplica ticket;
8. mesma `external_reference` em integrações diferentes é permitida;
9. comentário externo só pode ser público;
10. nota interna não aparece na API nem gera webhook;
11. comentário público interno gera webhook para a integração correta;
12. mudança de status pública gera webhook;
13. assinatura HMAC valida corretamente;
14. falha de webhook cria retry e registra erro;
15. retry bem-sucedido encerra pendência;
16. integração sem webhook não falha o fluxo de ticket;
17. regenerar chave invalida a anterior;
18. endpoints de administração exigem `integrations.manage`;
19. anexos respeitam isolamento e validações;
20. fechar/reabrir respeita ciclo de vida e isolamento;
21. nenhuma resposta externa contém campos internos proibidos.

Executar a suíte completa existente além dos novos testes para garantir que Minha Caixa, departamentos, permissões, lifecycle, login e deploy não regrediram.

## Fora do escopo desta base

- Interface de suporte dentro do Estúdio França/Projeto Terra. Essa será a primeira integração consumidora em uma etapa posterior.
- Migração/remoção definitiva da tabela `companies`.
- Cadastro de usuários externos dentro do Sutoorii Tickets.
- Login de clientes externos diretamente no Sutoorii Tickets.
- E-mail criando ticket.

## Critério de pronto

A base estará pronta quando for possível, a partir de um sistema externo de teste:

1. autenticar com uma chave de integração;
2. abrir ticket;
3. listar/consultar respeitando usuário versus gestor;
4. comentar e anexar;
5. fechar/reabrir quando permitido;
6. receber respostas/status por webhook assinado;
7. observar e reenviar falhas pelo painel;
8. confirmar isolamento total entre duas chaves;
9. gerenciar integrações pelo painel administrativo;
10. passar a suíte completa e o fluxo de deploy existente sem regressões.
