# Integrações e Etiquetas — Sutoorii Tickets

Data: 2026-09-11

## Objetivo

Permitir que sistemas externos da Sutoorii ou de terceiros abram tickets no Sutoorii Tickets usando uma chave de integração própria, sem exigir cadastro duplicado de empresa dentro do sistema de tickets. Cada integração representa uma instalação/sistema externo específico e pode aplicar etiquetas automaticamente aos tickets recebidos.

O primeiro consumidor previsto será o Estúdio França. O nome interno "Projeto Terra" não deve aparecer no sistema do cliente nem na API pública.

## Estado atual relevante

A base já possui:

- tabela `systems` e model `ConnectedSystem`;
- coluna `system_id` e `origin=integration` em `tickets`;
- tabela `labels` e relação N:N `label_ticket`;
- permissões `labels.manage`, `integrations.manage` e `tickets.manage_labels`;
- carregamento de labels nos tickets e caixas;
- endpoint `/api/v1/health`;
- deploy automático ao fazer push em `main`.

A implementação deve aproveitar essa estrutura e evitar refatorações não relacionadas.

## Arquitetura escolhida

### 1. Integrações por chave

Cada registro em `systems` representa uma integração específica. O nome é livre e serve para controle interno, por exemplo `Estúdio França`.

A integração não dependerá obrigatoriamente de uma empresa cadastrada. `company_id` passa a ser opcional para preservar compatibilidade com estruturas futuras sem obrigar o fluxo atual a cadastrar uma empresa.

A chave será gerada no painel administrativo com alta entropia e exibida em texto puro apenas no momento da criação ou regeneração. O banco armazenará apenas SHA-256 da chave em `api_token_hash`. O painel poderá manter somente um pequeno identificador seguro da chave para reconhecimento visual.

Regenerar a chave invalida imediatamente a anterior.

Campos administrativos previstos:

- nome;
- URL/base do sistema, opcional;
- ativo/inativo;
- etiquetas automáticas;
- indicação de último uso, quando disponível.

### 2. Etiquetas

Haverá um menu administrativo `Etiquetas` com CRUD simples:

- nome;
- cor;
- ativa/inativa;

Como a tabela atual não possui `active`, será adicionada essa coluna com padrão `true`.

Um ticket pode ter várias etiquetas. Usuários com `tickets.manage_labels` podem alterar as etiquetas diretamente no ticket.

As caixas/listagens poderão ser filtradas por etiqueta.

### 3. Etiquetas automáticas por integração

Será criada uma relação N:N entre `systems` e `labels` para definir etiquetas automáticas de cada integração.

Exemplo: a integração `Estúdio França` pode ter a etiqueta automática `Estúdio França`. Todo ticket recebido com essa chave recebe essa etiqueta, sem depender do sistema externo enviá-la.

O payload também poderá enviar etiquetas adicionais já existentes no Sutoorii Tickets. Etiquetas desconhecidas produzirão resposta de validação 422 em vez de serem criadas silenciosamente.

As etiquetas do payload serão combinadas com as etiquetas automáticas configuradas na integração, sem duplicação.

### 4. API de abertura de tickets

Novo endpoint:

`POST /api/v1/tickets`

Autenticação aceita:

- `Authorization: Bearer <chave>` como forma preferencial;
- `X-Integration-Key: <chave>` como alternativa de compatibilidade.

A chave identifica automaticamente o `system_id`; o cliente da API nunca informa esse ID.

Payload mínimo:

```json
{
  "title": "Problema ao finalizar contrato",
  "description": "Descrição do chamado",
  "requester_name": "Nome do usuário",
  "requester_email": "email@exemplo.com"
}
```

Campos opcionais:

- `priority`: `low`, `normal`, `high`, `urgent`;
- `external_reference`: identificador do ticket no sistema de origem;
- `external_requester_id`: identificador do usuário no sistema de origem;
- `labels`: lista de nomes de etiquetas já cadastradas.

Regras de criação:

- `origin = integration`;
- `system_id` obtido pela chave;
- status inicial igual ao usado para tickets internos (`new`);
- departamento e responsável ficam vazios para triagem manual;
- número do ticket continua sendo gerado pelo Sutoorii Tickets;
- integração inativa retorna 401/403 e não cria ticket;
- a API registra evento de criação com a origem da integração.

Resposta de sucesso: HTTP 201 com número, ID, status e URL interna do ticket.

### 5. Gestão de etiquetas dentro do ticket

Na tela de ticket haverá um bloco compacto de etiquetas mostrando chips coloridos e um controle de edição para quem possuir `tickets.manage_labels`.

O salvamento usa `sync` da relação N:N e registra evento de auditoria/histórico quando houver mudança.

Tickets antigos continuam funcionando normalmente e podem receber etiquetas manualmente.

### 6. Filtro por etiquetas

Nas caixas de tickets será adicionado filtro `Etiqueta`.

A filtragem será feita com `whereHas('labels')`, preservando os filtros atuais de status, prioridade, busca, atraso e responsável.

As etiquetas continuarão visíveis nos cards/linhas de ticket.

### 7. Administração

O menu Administração ganhará:

- `Etiquetas`, condicionado a `labels.manage`;
- `Integrações`, condicionado a `integrations.manage`.

Integrações terão as ações:

- cadastrar;
- editar nome/URL/status/etiquetas automáticas;
- gerar/regenerar chave;
- copiar a chave imediatamente após geração;
- ativar/inativar.

A chave completa nunca será recuperável depois que a tela de geração for fechada.

### 8. Segurança

- tokens não serão armazenados em texto puro;
- comparação será feita pelo hash SHA-256;
- middleware próprio resolverá a integração antes de chegar ao controller;
- endpoint terá rate limit;
- nenhuma integração poderá indicar arbitrariamente outro `system_id`;
- etiquetas só podem referenciar registros existentes e ativos;
- tickets externos não serão automaticamente atribuídos a departamento ou usuário nesta etapa.

### 9. Banco de dados

Nova migration incremental, sem alterar migrations já executadas, para:

- tornar `systems.company_id` nullable;
- adicionar `systems.token_hint` nullable;
- adicionar `systems.last_used_at` nullable;
- adicionar `labels.active` boolean default true;
- criar tabela pivot `label_system` com chaves estrangeiras e chave única composta.

A migration deve ser reversível.

### 10. Testes

Cobertura mínima:

- chave válida cria ticket externo;
- chave inválida não cria ticket;
- integração inativa não cria ticket;
- etiquetas automáticas são aplicadas;
- etiquetas adicionais existentes são aplicadas;
- etiqueta inexistente retorna 422;
- regeneração invalida a chave anterior;
- usuário sem permissão não acessa gestão de integrações/etiquetas;
- filtro por etiqueta retorna somente tickets correspondentes;
- edição manual de etiquetas exige `tickets.manage_labels`.

## Fora do escopo desta etapa

- roteamento automático por departamento;
- criação automática de etiquetas pela API;
- múltiplas chaves simultâneas por integração;
- webhooks de retorno ao sistema externo;
- sincronização bidirecional de comentários/status;
- cadastro automático de empresas.

Esses itens permanecem possíveis evoluções futuras sem necessidade de quebrar o contrato definido acima.
