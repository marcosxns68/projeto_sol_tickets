# Departamentos, solicitantes e notificações — Design

## Objetivo

Reestruturar o Sutoorii Tickets para permitir múltiplos acessos por usuário a departamentos, simplificar a abertura de tickets, separar claramente criador/solicitante/participantes e criar notificações de e-mail previsíveis.

## Departamentos

Cada usuário pode ter uma associação independente com vários departamentos. A associação possui um nível progressivo de acesso:

- `send`: pode criar ou encaminhar tickets para o departamento, mas não vê a caixa nem os tickets apenas por esse vínculo.
- `view`: inclui `send` e permite visualizar a caixa e todos os tickets do departamento.
- `edit`: inclui `view` e permite editar tickets do departamento, sempre respeitando as permissões gerais do cargo para cada ação.

A preferência `follow_department` existe apenas para associações `view` e `edit`. Se o acesso cair para `send`, ela é desligada automaticamente. Acompanhar o departamento envia e-mail quando um ticket é criado/entra no departamento ou é excluído/cancelado da operação normal.

A associação antiga `users.department_id` será preservada por compatibilidade e migrada para o novo vínculo com nível `view`, sem ser mais a fonte principal de autorização.

## Tela Departamentos

O menu "Departamentos" passa a ser operacional. Administradores com `departments.manage` veem todos os departamentos, criam novos e gerenciam as associações. Usuários comuns veem apenas os departamentos aos quais têm associação.

A tabela mostra departamento, abertos, concluídos e pessoas com acesso. Para associação somente `send`, contagens não são expostas ao usuário comum. Ao expandir, administradores veem os usuários associados, nível de acesso e estado de acompanhamento. O próprio usuário pode ativar/desativar acompanhamento quando seu nível for `view` ou `edit`.

## Visibilidade de tickets

Um usuário interno vê um ticket quando ocorrer ao menos uma destas condições:

- possui `tickets.view_all`;
- é responsável;
- é colaborador ou seguidor;
- é solicitante interno do ticket;
- possui acesso `view` ou `edit` ao departamento do ticket;
- compatibilidade temporária: `department_id` legado coincide com o departamento e possui a permissão histórica adequada.

Ser apenas o criador não concede visibilidade. Ao criar, o usuário pode pesquisar o próprio nome no campo Seguidores para manter acesso.

## Criador, solicitante e participantes

`creator_id` registra quem abriu o ticket internamente e não concede acompanhamento automático.

Solicitante é opcional em tickets internos. Pode ser um usuário interno, um usuário de sistema integrado ou um contato manual com nome/e-mail. O solicitante sempre pode visualizar seus tickets, inclusive concluídos/fechados, e sempre pode comentar publicamente.

Responsável, colaboradores e seguidores usam busca dinâmica por nome/e-mail, sem selects com todos os usuários.

## Criação de tickets

A interface usa a pergunta "Este ticket é para:" com opções "Minha equipe" e "Uma empresa/cliente".

Para empresa/cliente, seleciona-se o sistema integrado. O solicitante pode ser localizado por busca no diretório da integração; se não houver correspondência, nome e e-mail podem ser informados manualmente. Se o e-mail corresponder exatamente a um usuário ativo da integração, o ticket é vinculado automaticamente ao `external_requester_id`, aparecendo em "Minhas solicitações" desse usuário.

O departamento de destino é limitado à união dos departamentos associados ao usuário com acesso `send`, `view` ou `edit`, exceto quem tem visão global/gestão administrativa apropriada.

## Portal do solicitante manual

Para solicitantes que possuem apenas nome/e-mail, notificações incluem um link assinado e não expirável para uma área simples de solicitações. O link identifica o e-mail por assinatura Laravel; não exige conta. A área lista todos os tickets vinculados àquele e-mail, inclusive concluídos, mostra apenas comentários públicos e permite novo comentário público.

## Integrações

Usuário comum do sistema integrado continua vendo apenas tickets com seu `external_requester_id`. Gestor (`manager`) continua vendo e comentando qualquer ticket da própria integração. Tickets criados manualmente para um usuário localizado na integração recebem o `external_requester_id` dele.

## Notificações

- Criador interno recebe confirmação de abertura sempre, independentemente de ser seguidor.
- Solicitante com e-mail recebe confirmação de abertura, sem duplicar se tiver o mesmo e-mail do criador.
- Adição/remoção de responsável, colaborador ou seguidor envia automaticamente e-mail à pessoa afetada.
- Acompanhadores de departamento recebem e-mail quando ticket é criado/entra naquele departamento e quando é cancelado/excluído da operação normal.
- Comentário público oferece caixas: Notificar solicitante (marcada por padrão), Notificar responsável, Notificar colaboradores e Notificar seguidores. Sem marcação, o comentário é silencioso para aquele grupo.
- Nota interna nunca notifica solicitante externo.
- Alterações de conteúdo/status/prioridade/prazo e transições exibem "Notificar solicitante" marcada por padrão quando há solicitante com e-mail; pode ser desmarcada.
- O autor da ação não recebe e-mail causado pela própria ação e destinatários são deduplicados por endereço.

## Compatibilidade e segurança

Permissões gerais de cargo continuam definindo quais ações são permitidas. O nível do departamento define onde o usuário pode exercer essas ações. Links de solicitante manual usam URLs assinadas e nunca expõem notas internas. Endpoints de busca exigem autenticação, limitam resultados e retornam somente id, nome e e-mail de usuários ativos.