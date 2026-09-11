# Sutoorii Tickets — Integrações API v1

Data: 2026-09-11

## Objetivo

Criar a base definitiva para sistemas externos abrirem e acompanharem tickets no Sutoorii Tickets sem cadastrar empresas, usuários externos ou gestores dentro do Tickets. Cada cadastro em **Integrações** representa uma origem isolada e a chave da integração determina essa origem. O Estúdio França será o primeiro consumidor, mas a base é genérica.

## Decisões aprovadas

- Não haverá cadastro de empresa na lógica nova de integrações.
- Uma integração = uma origem isolada = uma chave própria.
- O nome da integração é apenas administrativo; a chave define a origem técnica.
- Uma mesma empresa pode usar vários sistemas, cada um com integração/chave distinta.
- O mesmo software pode ser usado por empresas/bancos diferentes; cada instalação recebe integração/chave distinta.
- Usuário externo comum vê e interage somente com tickets que ele próprio abriu naquela chave.
- Gestor do sistema vê e interage com todos os tickets daquela chave, e somente daquela chave.
- O Tickets confia no papel `manager` enviado pelo servidor autenticado da integração.
- A chave nunca vai para o navegador do usuário final.
- Notas internas, checklist, auditoria, colaboradores e dados administrativos nunca saem pela API/webhook.
- Número público oficial: `AAMM0000` (ano 2 dígitos + mês 2 dígitos + 4 dígitos aleatórios), com unicidade verificada.
- `external_reference` é técnico, opcional e invisível ao usuário; serve para correlação/idempotência e não substitui o número oficial.

## Modelo de domínio

### Integração

A tabela atual `systems` continua como entidade técnica e será apresentada na interface como **Integração**.

Campos principais:

- `id`
- `name`
- `base_url` opcional
- `department_id` padrão opcional
- `api_token_hash`
- `webhook_url` opcional
- `webhook_secret` criptografado em repouso
- `active`
- `last_api_activity_at`
- campos de diagnóstico do último webhook
- timestamps

O `company_id` existente passa a aceitar `null` e deixa de participar da lógica nova. A tabela `companies` permanece nesta entrega apenas para evitar migração destrutiva; não é usada no novo fluxo.

### Ticket externo

Tickets criados pela API terão:

- `origin = integration`
- `system_id` derivado exclusivamente da chave autenticada
- `company_id = null`
- `external_requester_id` vindo do contexto externo
- `requester_name` e `requester_email` fornecidos pelo servidor integrado
- `external_reference` técnico opcional
- departamento inicial igual ao departamento padrão da integração, quando configurado

A API nunca aceita `system_id` ou `company_id` enviados pelo cliente.

## Autenticação e contexto externo

### Chave da integração

Toda requisição v1 usa:

`Authorization: Bearer <chave-da-integracao>`

A chave é gerada com entropia criptográfica e prefixo `st_live_`. Ela aparece somente na criação/regeneração. O banco guarda apenas SHA-256 em `api_token_hash`.

Chave inexistente/revogada retorna `401`. Integração autenticada mas inativa retorna `403`.

### Contexto do usuário

Cada requisição autenticada informa:

- `X-External-User-Id`
- `X-External-User-Role: user|manager`

O papel é confiável porque vem do servidor que possui a chave. Isso não cria usuário ou privilégio interno no Sutoorii Tickets.

Nome/e-mail são enviados no corpo quando necessários. Ausência/valor inválido de contexto retorna `422`.

### Isolamento obrigatório

Toda consulta começa por `system_id = integração autenticada`.

- `user`: acrescenta `external_requester_id = X-External-User-Id`.
- `manager`: não filtra solicitante, mas continua limitado ao mesmo `system_id`.

Ticket fora desse escopo responde como `404`, sem revelar se existe em outra integração.

## API v1

Prefixo: `/api/v1`.

### Criar ticket

`POST /api/v1/tickets`

Corpo:

- `external_reference` opcional
- `requester_name` obrigatório
- `requester_email` opcional
- `title` obrigatório
- `description` obrigatório
- `priority`: `low|normal|high|urgent` (padrão `normal`)

Se `external_reference` já existir na mesma integração, a API devolve o ticket existente e não duplica. A mesma referência pode existir em integrações diferentes.

A combinação `(system_id, external_reference)` será única quando houver referência.

### Listar

`GET /api/v1/tickets`

Usuário comum recebe os próprios; gestor recebe todos da integração. Filtros v1: status, prioridade e paginação.

### Consultar

`GET /api/v1/tickets/{number}`

A rota usa o número oficial `AAMM0000` e sempre aplica isolamento antes de retornar dados.

### Comentário público

`POST /api/v1/tickets/{number}/comments`

Cria somente `visibility=public` e `source=integration`. A API não possui endpoint de nota interna.

Aceita `external_message_id` opcional. Internamente o valor é namespaced pela integração antes de preencher o `message_id` único, impedindo colisões entre integrações e duplicação em retry.

### Anexos

`POST /api/v1/tickets/{number}/attachments`

`GET /api/v1/tickets/{number}/attachments/{attachment}`

Somente tickets visíveis naquele contexto podem receber/baixar anexos. V1 aceita `jpg`, `jpeg`, `png`, `webp`, `pdf`, `txt`, `doc`, `docx`, `xls`, `xlsx` e `zip`, com máximo de 10 MB por arquivo. O armazenamento nunca é exposto; download passa por controlador autenticado e escopado.

Novos anexos externos usam a política de expiração do Tickets e devem ser removidos/indisponibilizados conforme `expires_at`.

### Fechar/reabrir

`POST /api/v1/tickets/{number}/close`

`POST /api/v1/tickets/{number}/reopen`

Usuário comum atua nos próprios tickets; gestor em qualquer ticket da integração. As transições respeitam o ciclo de vida do Tickets e não podem forçar estado inválido.

### Atividade pública

`GET /api/v1/tickets/{number}/activity`

Expõe apenas criação, comentários públicos, status público, resolução/fechamento/reabertura e anexos públicos aplicáveis. Nunca inclui notas internas, checklist, auditoria, responsáveis/participantes internos ou dados administrativos confidenciais.

## Webhooks de saída

Cada integração pode ter `webhook_url` e um `webhook_secret` próprio. O segredo precisa ser recuperável pelo servidor para assinatura, portanto será armazenado **criptografado em repouso**, nunca em texto puro nem em hash irreversível.

Eventos v1:

- `ticket.comment.created`
- `ticket.status.changed`
- `ticket.resolved`
- `ticket.closed`
- `ticket.reopened`

`ticket.created` não precisa ser enviado de volta quando a própria API acabou de criar o ticket, pois a resposta da criação já confirma o evento.

### Regra antiecho

Eventos recebidos pela API da própria integração não são enviados imediatamente de volta para ela. Webhooks servem para mudanças públicas ocorridas no Sutoorii Tickets depois da chamada externa, evitando loops e mensagens duplicadas.

Nota interna nunca cria webhook.

### Assinatura

Cada POST inclui:

- `X-Sutoorii-Event`
- `X-Sutoorii-Delivery`
- `X-Sutoorii-Timestamp`
- `X-Sutoorii-Signature`

A assinatura é HMAC-SHA256 do timestamp + `.` + corpo JSON bruto, usando o segredo da integração. O identificador de entrega é UUID.

### Entrega e retry

Persistir cada entrega antes do envio com integração, evento, payload, UUID, tentativas, estado, último status HTTP, último erro, próxima tentativa e data de entrega.

Primeira tentativa é imediata. Em falha, serão feitas até 5 tentativas totais, com atrasos de aproximadamente 1, 5, 15 e 60 minutos entre as tentativas seguintes. Após a quinta falha, fica como `failed` e pode ser reenviada manualmente.

A hospedagem não dependerá de daemon permanente. Haverá command idempotente `integrations:webhooks:process` para processar pendências por cron, além de reenvio manual no painel.

## Painel administrativo

Nova área **Integrações**, protegida por `integrations.manage`.

Lista:

- nome
- ativa/inativa
- departamento padrão
- webhook configurado ou não
- última atividade da API
- último estado de webhook

Cadastro/edição:

- nome
- URL base opcional
- departamento padrão opcional
- webhook URL opcional
- ativa/inativa

Ao criar uma integração, o sistema gera uma chave API e a exibe uma única vez. O segredo do webhook é gerado quando configurado/solicitado. Ações:

- regenerar chave (invalida a anterior imediatamente);
- gerar/regenerar segredo;
- ativar/desativar;
- visualizar entregas recentes;
- reenviar entrega falha.

Nem a chave nem o segredo aparecem integralmente depois da geração.

## Observabilidade e auditoria

Auditar:

- criação/edição da integração;
- ativação/desativação;
- geração/regeneração de chave;
- geração/regeneração do segredo.

Nunca gravar chave ou segredo em texto puro no audit log.

Atualizar `last_api_activity_at` após autenticação válida e indicadores do último webhook após cada tentativa.

## Migração segura

A entrega será aditiva:

1. tornar `systems.company_id` nullable;
2. adicionar `department_id`, observabilidade e campos necessários a `systems`;
3. armazenar `webhook_secret` com cast criptografado no modelo;
4. criar tabela `webhook_deliveries`;
5. criar unicidade/correlação por `(system_id, external_reference)`;
6. preservar `companies` e todos os dados existentes;
7. manter tickets internos inalterados.

Nenhuma migração apaga dados ou recria tabelas de produção.

## Erros e rate limit

JSON consistente:

- `401`: chave ausente/inválida;
- `403`: integração inativa;
- `404`: ticket inexistente ou fora do escopo;
- `409`: conflito não recuperável;
- `422`: validação/contexto externo;
- `429`: limite excedido.

Rate limit inicial: 120 requisições por minuto por integração. O valor fica configurável sem alterar o contrato da API.

Nunca retornar stack trace, SQL, paths internos ou dados de outra integração.

## Segurança de webhook

- HTTPS obrigatório em produção.
- Host do webhook deve resolver para endereço público em produção; bloquear loopback, link-local, redes privadas e metadata endpoints para reduzir SSRF.
- Redirecionamentos HTTP não podem contornar essa validação.
- Timeout curto e tamanho de resposta limitado; o corpo da resposta do destino não é armazenado integralmente.

## Testes obrigatórios — TDD

Antes da produção, cobrir ao menos:

1. chave válida autentica a integração correta;
2. chave inválida/revogada/inativa falha corretamente;
3. duas chaves nunca enxergam tickets uma da outra;
4. usuário comum vê somente tickets do próprio `external_user_id`;
5. gestor vê todos e somente os tickets daquela chave;
6. criação gera `AAMM0000`;
7. `external_reference` repetida na mesma integração não duplica;
8. mesma referência em integrações diferentes é permitida;
9. comentário externo é sempre público e idempotente quando houver `external_message_id`;
10. nota interna não aparece na API nem gera webhook;
11. comentário público interno gera webhook para a integração correta;
12. mudança pública de status gera webhook;
13. evento originado pela própria API não ecoa para a integração;
14. HMAC valida com corpo/timestamp corretos;
15. falha cria retry e registra diagnóstico;
16. retry bem-sucedido encerra pendência;
17. quinta falha deixa entrega como `failed` e reenvio manual funciona;
18. integração sem webhook não quebra fluxo do ticket;
19. regenerar chave invalida a antiga;
20. administração exige `integrations.manage`;
21. anexos respeitam escopo, MIME e 10 MB;
22. fechar/reabrir respeita ciclo e isolamento;
23. nenhuma resposta externa contém campos internos proibidos;
24. webhook bloqueia destinos privados/inseguros em produção.

Além dos novos testes, executar toda a suíte existente para evitar regressões em caixas, departamentos, permissões, lifecycle, login e deploy.

## Fora do escopo desta base

- Interface de suporte dentro do Estúdio França/Projeto Terra; será o primeiro consumidor em etapa posterior.
- Remoção definitiva da tabela `companies`.
- Cadastro de usuários externos no Tickets.
- Login de clientes externos diretamente no Tickets.
- E-mail criando ticket.

## Critério de pronto

A base está pronta quando um sistema externo de teste consegue:

1. autenticar por chave;
2. abrir ticket;
3. listar/consultar com regra usuário versus gestor;
4. comentar e anexar;
5. fechar/reabrir quando permitido;
6. receber mudanças internas públicas por webhook assinado;
7. observar/reprocessar falhas no painel;
8. comprovar isolamento total entre duas chaves;
9. administrar integrações no painel;
10. passar suíte completa e fluxo de deploy sem regressões.
