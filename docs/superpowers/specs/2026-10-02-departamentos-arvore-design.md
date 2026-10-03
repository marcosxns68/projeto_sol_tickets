# Sutoorii Tickets — Departamentos em árvore, pastas e acompanhamento

Data: 02/10/2026

## Objetivo

Evoluir a tela de Departamentos para uma navegação compacta em árvore, semelhante a um explorador de pastas, sem descaracterizar o Sutoorii Tickets nem reduzir a tela completa atual do ticket.

O departamento continua sendo o limite de permissão e de notificações. Pastas e subpastas servem apenas para organização interna dos tickets e herdam integralmente o acesso do departamento.

## Escopo aprovado

1. Departamentos aparecem como linhas compactas expansíveis, adequadas a desktop e celular.
2. Um departamento pode conter tickets diretamente na raiz, pastas e subpastas recursivas.
3. Um ticket pode ficar na raiz do departamento ou em qualquer pasta/subpasta.
4. A criação normal de ticket continua pedindo apenas o departamento. O ticket nasce na raiz do departamento.
5. O botão `+` de um departamento ou pasta abre um pequeno menu com `Nova tarefa` e `Nova pasta`. Quando usado dentro de uma pasta, ambos são criados naquele nível.
6. Ao abrir um ticket, a tela continua sendo a tela completa atual do sistema. A única ampliação estrutural é a possibilidade de alterar a pasta/subpasta dentro de `Atendimento`.
7. A linha do ticket na árvore mostra uma bolinha cuja cor corresponde à prioridade.
8. O topo da página de Departamentos passa a ter `+ Novo departamento`, não `+ Novo ticket`.
9. O menu `⋯` do departamento reúne acompanhamento/notificações, pessoas e acessos, visualização dos tickets e edição do departamento.
10. Departamentos passam a ter descrição editável, além de nome e status ativo.
11. A explicação solta no rodapé da árvore (`clique no nome do departamento...`) não será exibida.
12. A hierarquia de acesso ao departamento ficará explícita como `Nível 1 — Enviar`, `Nível 2 — Visualizar`, `Nível 3 — Editar`.
13. Super Admin e qualquer usuário que tenha direito real de visualizar o departamento podem acompanhar a caixa sem precisar ser adicionados artificialmente como membros do departamento.
14. Acompanhamento continua com canais independentes de E-mail, WhatsApp e Push.

## Princípios de compatibilidade

- Departamento continua sendo a unidade de autorização.
- Pastas não terão ACL, cargos, membros ou permissões próprias.
- Quem tem acesso a um departamento tem o mesmo nível de acesso em todas as pastas e subpastas dele.
- Permissões do cargo continuam limitando o que a pessoa pode fazer dentro de um ticket. `Editar` no departamento não concede ações que o cargo não possui.
- Integrações externas continuam podendo criar tickets apenas informando departamento; nenhuma integração existente será obrigada a conhecer `folder_id`.
- Tickets atuais permanecem válidos e ficam na raiz do respectivo departamento até serem movidos.
- A tela completa atual do ticket, comentários, histórico, etiquetas, checklist, anexos, recorrência, participantes, ciclo de vida e notificações permanecem preservados.
- A Triagem continua sendo a caixa de sistema protegida. Nesta primeira versão ela permanece sem organização por subpastas para não alterar as regras especiais de roteamento; seus tickets ficam na raiz da Triagem.

## Modelo de dados

### `departments`

Adicionar:

- `description` — texto opcional.

O nome continua obrigatório e único conforme a regra atual. A descrição é opcional e poderá ser exibida de forma discreta/truncada abaixo do nome na árvore.

### `ticket_folders`

Nova tabela:

- `id`
- `department_id` — FK obrigatória para o departamento proprietário
- `parent_id` — FK opcional para outra pasta do mesmo departamento
- `name`
- `position` — inteiro para ordenação futura/manual, padrão 0
- timestamps

Regras:

- uma subpasta só pode apontar para uma pasta do mesmo departamento;
- ciclos são proibidos;
- pastas da Triagem não podem ser criadas;
- a exclusão destrutiva de pastas não entra neste pacote; não haverá risco de apagar tickets por exclusão de pasta.

### `tickets`

Adicionar:

- `folder_id` — FK opcional para `ticket_folders`.

Regras:

- `folder_id = null` significa ticket na raiz do departamento;
- a pasta escolhida deve pertencer ao mesmo `department_id` do ticket;
- ao trocar o ticket de departamento, a pasta atual é descartada e o ticket vai para a raiz do novo departamento, a menos que o usuário selecione explicitamente uma pasta válida do novo departamento na mesma operação;
- se um fluxo existente não enviar pasta, o comportamento permanece exatamente como hoje.

### `department_subscriptions`

Criar uma tabela separada para acompanhamento, evitando usar associação ao departamento como requisito para receber alertas:

- `id`
- `user_id`
- `department_id`
- `notify_email`
- `notify_whatsapp`
- `notify_push`
- `last_seen_at`
- timestamps
- índice único em `(user_id, department_id)`.

A migration fará backfill das preferências atuais existentes em `department_user_access` para não perder nenhum seguidor, canal ou referência de leitura. As colunas antigas de acompanhamento podem permanecer temporariamente para compatibilidade/rollback, mas o novo serviço de acompanhamento passa a ler e gravar na tabela de assinaturas.

Isso permite que Super Admin ou usuário com permissão global de visualização acompanhe um departamento sem criar uma associação falsa de membro.

## Regras de acesso ao departamento

A interface deve explicar claramente:

- **Nível 1 — Enviar:** pode enviar/criar tickets para o departamento, mas não visualizar a caixa.
- **Nível 2 — Visualizar:** inclui Enviar e permite visualizar a caixa, tickets e acompanhar/notificar-se sobre o departamento.
- **Nível 3 — Editar:** inclui os níveis anteriores e permite trabalhar nos tickets conforme as permissões do cargo.

Texto de apoio: `Hierarquia: Enviar → Visualizar → Editar. Cada nível inclui o anterior.`

Acompanhamento só exige direito efetivo de visualizar o departamento, seja por associação `view/edit` ou por permissão global aplicável. Não exige membership quando a pessoa já tem esse acesso por cargo/global.

## Interface da árvore

### Linha de departamento

Conteúdo compacto:

- chevron de expansão;
- ícone de pasta;
- nome;
- descrição opcional em texto secundário truncado;
- contador de tickets;
- botão discreto `+`;
- botão `⋯`.

Clicar na área principal da linha expande/recolhe o departamento. Clicar em `+` ou `⋯` não altera o estado de expansão.

### Menu `+`

Em departamento:

- `Nova tarefa` — cria um ticket na raiz daquele departamento;
- `Nova pasta` — cria uma pasta na raiz daquele departamento.

Em pasta/subpasta:

- `Nova tarefa` — cria ticket naquela pasta;
- `Nova pasta` — cria subpasta dentro da pasta atual.

O item é criado no contexto atual sem exigir que o usuário escolha novamente departamento/pasta.

### Menu `⋯` do departamento

Mostrar conforme autorização:

- `Acompanhar e notificações` — para quem pode visualizar;
- `Ver tickets do departamento` — para quem pode visualizar;
- `Pessoas e acessos` — para quem pode administrar departamentos;
- `Editar departamento` — para quem pode administrar departamentos e quando não for a Triagem.

`Acompanhar e notificações` permite marcar/desmarcar E-mail, WhatsApp e Push. Nenhum canal marcado significa não acompanhar.

`Pessoas e acessos` usa os nomes Nível 1/2/3 e explica a hierarquia.

`Editar departamento` permite alterar nome, descrição e estado ativo.

### Botão superior

Na tela de Departamentos: `+ Novo departamento`.

A criação pede nome, descrição opcional e estado ativo. A criação de ticket geral permanece disponível pelos fluxos já existentes do sistema, mas não ocupa a ação principal desta tela.

## Exibição dos tickets na árvore

Cada ticket continua identificável pelo número e título. A bolinha anterior ao ticket usa cor por prioridade:

- Baixa: verde `#2F855A`
- Normal: roxo `#6D28D9`
- Alta: laranja `#D97706`
- Urgente: vermelho `#DC2626`

A cor não será a única fonte de informação: o elemento terá texto acessível/tooltip com a prioridade.

Dentro de cada nível, as pastas são apresentadas de forma compacta e os tickets preservam a ordenação operacional já usada pelo sistema (prazo/prioridade), sem transformar a árvore em uma nova lógica de SLA.

## Tela completa do ticket

Abrir um ticket pela árvore leva à rota/tela completa atual do ticket. Não será criado painel lateral simplificado nem uma versão reduzida.

Devem permanecer visíveis e funcionais os elementos atuais, incluindo:

- cabeçalho e status;
- Prioridade, Responsável, Prazo e Solicitante;
- Conversa, comentários públicos e notas internas;
- Informações do ticket;
- Histórico;
- Atendimento;
- Etiquetas;
- Checklist;
- Anexos;
- Recorrência;
- Participantes;
- Ações do ticket;
- Notificar solicitante;
- confirmações de ações finais.

Em `Atendimento`, acrescentar `Pasta / subpasta` junto à localização do ticket. `Raiz do departamento` deve ser uma opção explícita.

## Mobile e desktop

A árvore deve manter densidade alta e navegação confortável:

- desktop: nome/descrição, contadores e ações na mesma linha;
- celular: manter chevron, ícone, nome, contador, `+` e `⋯`; textos secundários podem ser ocultados/truncados;
- o recuo de níveis diminui no celular para evitar perda excessiva de largura;
- menus `+` e `⋯` abrem como popover/menu compacto ou bottom sheet quando necessário;
- nenhum card grande será usado para cada departamento.

A tela completa do ticket continuará usando o layout responsivo atual.

## Notificações e novidades

Notificações continuam vinculadas ao departamento, não à pasta. Criar ou mover um ticket entre pastas do mesmo departamento não deve gerar uma notificação de `ticket encaminhado para departamento`.

Mover entre departamentos continua seguindo as regras atuais de encaminhamento e notificações do departamento de destino.

A contagem de novidades por departamento considera tickets de todas as pastas e da raiz.

Os jobs de E-mail, WhatsApp e Web Push deverão consultar as novas assinaturas sem duplicar alertas de quem também é responsável, colaborador ou seguidor do ticket.

## Fluxos

### Criação normal de ticket

1. Usuário abre o formulário normal.
2. Seleciona o departamento, como hoje.
3. O ticket é criado com `folder_id = null`.
4. Depois pode ser movido em `Atendimento` para uma pasta/subpasta.

### Criação pelo `+` da árvore

1. Usuário clica `+` no departamento/pasta.
2. Escolhe `Nova tarefa` ou `Nova pasta`.
3. Para tarefa, o contexto de departamento/pasta já vem definido.
4. Para pasta, o `department_id` e `parent_id` são definidos pelo nível atual.

### Movimentação do ticket

1. Usuário abre a tela completa do ticket.
2. Em `Atendimento`, escolhe departamento e localização.
3. Se só a pasta mudar dentro do mesmo departamento, apenas a organização muda.
4. Se o departamento mudar, aplicar as regras atuais de roteamento/atribuição e validar a pasta no novo departamento.

## Tratamento de erros e segurança

- impedir pasta de outro departamento;
- impedir ciclos de subpastas;
- impedir criação de pasta na Triagem;
- aplicar autorização do departamento a toda operação de pasta/ticket;
- menus escondem ações sem permissão, mas o servidor também valida todas as ações;
- falha de migração/backfill deve abortar o deploy antes de publicar código incompatível;
- nenhum dado de ticket existente pode ser apagado ou deslocado durante a migration.

## Testes obrigatórios

### Banco/modelos

- criação de pasta raiz e subpasta;
- validação de mesmo departamento;
- proibição de ciclos;
- ticket com `folder_id = null` continua válido;
- movimento raiz ↔ pasta ↔ subpasta;
- troca de departamento elimina pasta inválida;
- backfill das preferências de acompanhamento sem perda de canais/last_seen.

### Permissões

- send não visualiza árvore de tickets;
- view visualiza, acompanha e recebe opções de notificação;
- edit herda view e pode trabalhar conforme cargo;
- Super Admin acompanha sem membership artificial;
- acesso de uma pasta/subpasta é exatamente o acesso do departamento;
- usuário sem acesso não consegue forçar operações por URL/request.

### Interface

- expandir/recolher departamento e pastas;
- `+` abre exatamente Nova tarefa/Nova pasta;
- `⋯` mostra ações conforme permissão;
- prioridade produz a classe/cor correta e label acessível;
- descrição do departamento aparece sem quebrar o layout;
- botão superior é Novo departamento;
- ausência da frase auxiliar removida;
- árvore responsiva em viewport de celular e desktop.

### Regressão do ticket

- abrir ticket pela árvore usa a tela completa atual;
- comentários, notas, anexos, checklist, etiquetas, recorrência, participantes e lifecycle continuam funcionando;
- Notificar solicitante continua funcionando;
- alteração de pasta não dispara encaminhamento de departamento;
- alteração de departamento mantém os avisos atuais.

### Integrações/notificações

- ticket criado por integração sem pasta fica na raiz;
- Estúdio França e demais integrações continuam listando/comentando tickets sem exigir folder;
- seguidores antigos continuam recebendo conforme canais existentes após backfill;
- seguidores globais/Super Admin passam a poder receber alertas sem membership;
- sem duplicação de e-mail/WhatsApp/push por papéis sobrepostos.

## Publicação

1. Implementar em branch de feature, nunca diretamente em `main`.
2. Adicionar migrations somente aditivas/compatíveis.
3. Executar suíte completa e testes novos.
4. Validar sintaxe Blade/JS e comportamento responsivo.
5. Abrir PR com resumo das migrations e regressões cobertas.
6. Mesclar apenas com CI verde.
7. O merge em `main` usa o workflow atual de Build e deploy.
8. Confirmar o workflow de produção e verificar página de Departamentos e um ticket real após deploy.

## Fora deste pacote

Não fazem parte desta entrega: permissões específicas por pasta, compartilhamento isolado de subpastas, Gantt, Kanban, exclusão destrutiva de pastas, drag-and-drop ou alterações nas pendências gerais de paginação/lixeira/status/Turnstile discutidas separadamente. Esses itens podem ser tratados depois sem mudar o modelo de autorização definido aqui.
