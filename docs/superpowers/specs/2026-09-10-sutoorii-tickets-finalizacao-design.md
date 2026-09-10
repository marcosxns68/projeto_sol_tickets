# Sutoorii Tickets — Especificação de Finalização

## Objetivo

Finalizar o Sutoorii Tickets como um sistema completo de atendimento interno e integração entre sistemas Sutoorii, aproveitando a estrutura Laravel, banco de dados, autenticação, modelos e deploy existentes, mas substituindo as partes ainda incompletas por fluxos funcionais, consistentes e testáveis.

## Princípios gerais

- Reaproveitar a base existente sempre que ela estiver correta.
- Não reconstruir banco, autenticação ou deploy sem necessidade.
- Toda função sensível deve ser controlada por permissão.
- Cargo fornece permissões padrão, mas permissões podem ser concedidas ou negadas individualmente por usuário.
- Nenhuma funcionalidade administrativa deve depender exclusivamente do nome do cargo.
- O sistema deve impedir uma configuração que deixe a instalação sem nenhum usuário ativo capaz de administrar usuários e permissões.
- O visual deve permanecer limpo, profissional e alinhado à identidade Sutoorii.

## 1. Caixas de tickets

### 1.1 Minha Caixa

A Minha Caixa é pessoal e mostra todos os tickets ativos em que o usuário esteja diretamente vinculado como:

- responsável;
- colaborador;
- seguidor.

Tickets com status Resolvido, Fechado ou Cancelado não aparecem por padrão. Eles continuam acessíveis por filtros.

Um mesmo ticket deve aparecer apenas uma vez na Minha Caixa, mesmo que o usuário tenha mais de um vínculo com ele.

### 1.2 Caixas de departamento

Cada departamento funciona como uma fila própria. Todos os usuários pertencentes ao departamento, desde que possuam permissão para visualizar tickets do próprio departamento, podem visualizar os tickets daquela caixa.

Departamento e responsável são conceitos independentes:

- Departamento = fila/caixa onde o ticket está.
- Responsável = usuário encarregado de resolver o ticket.

Um ticket pode estar em um departamento e não ter responsável.

Tickets Resolvidos, Fechados e Cancelados também ficam fora da listagem padrão das caixas de departamento e aparecem somente através de filtros.

### 1.3 Visibilidade ao sair de um departamento

Ser criador do ticket não garante acesso permanente.

Quando o ticket sair do departamento do usuário, ele deixa de enxergar o ticket, exceto se ainda estiver vinculado como responsável, colaborador ou seguidor, ou possuir uma permissão global de visualização.

## 2. Responsável, colaborador e seguidor

### 2.1 Responsável

O responsável é a pessoa encarregada da resolução do ticket.

Quando um ticket estiver sem responsável, qualquer usuário elegível da caixa daquele departamento poderá assumir o ticket para si.

Se já existir um responsável, somente um usuário com a permissão específica de reatribuição poderá trocar o responsável.

Quando um gestor ou outro usuário autorizado atribuir diretamente um ticket a alguém, o ticket passa imediatamente a aparecer na Minha Caixa desse usuário.

### 2.2 Colaborador

O colaborador participa ativamente do trabalho do ticket, conforme suas permissões. Ele pode interagir com comentários, notas internas, checklist e anexos quando autorizado.

O colaborador continua enxergando o ticket mesmo se ele mudar para outro departamento.

O colaborador pode solicitar a conclusão do ticket, mas a conclusão efetiva permanece restrita ao responsável, salvo permissão administrativa específica.

### 2.3 Seguidor

O seguidor acompanha o ticket e recebe as notificações configuradas, mas não administra o trabalho do ticket.

Ele continua enxergando o ticket mesmo se ele mudar para outro departamento.

## 3. Encaminhamento entre departamentos

Quando um ticket for encaminhado para outro departamento:

1. o departamento responsável muda para o novo departamento;
2. o responsável atual é removido;
3. o ticket fica sem responsável;
4. o status muda automaticamente para **Encaminhado**;
5. o ticket aparece na caixa do novo departamento;
6. o evento é registrado no histórico do ticket.

O histórico deve registrar, no mínimo:

- usuário que encaminhou;
- departamento anterior;
- novo departamento;
- responsável removido, quando houver;
- data e hora;
- motivo, quando informado.

Quando um usuário do novo departamento clicar em **Assumir ticket**:

1. ele passa a ser o responsável;
2. o status muda automaticamente de **Encaminhado** para **Em andamento**;
3. o evento é registrado no histórico.

## 4. Status

Os status são administráveis e não devem ficar fixos somente no código.

O sistema deve possuir ao menos os estados funcionais necessários aos fluxos existentes, incluindo:

- Novo;
- Encaminhado;
- Em análise;
- Em andamento;
- Aguardando cliente;
- Aguardando terceiro;
- Aguardando aprovação;
- Resolvido;
- Fechado;
- Cancelado.

Usuários com a permissão correspondente podem criar, editar, ordenar, ativar e desativar status.

As categorias internas dos status devem continuar permitindo ao sistema distinguir ativos, aguardando, conclusão solicitada, concluídos e cancelados.

## 5. Tela do ticket

A página de detalhe do ticket deve deixar de ser uma tela apenas de leitura e se tornar a central operacional do atendimento.

### 5.1 Cabeçalho

Exibir:

- número do ticket;
- título;
- status;
- prioridade;
- ações principais permitidas ao usuário.

### 5.2 Dados principais

Permitir, conforme permissões:

- editar título;
- editar descrição;
- alterar status;
- alterar prioridade;
- alterar prazo;
- alterar departamento;
- assumir ticket;
- reatribuir responsável;
- adicionar ou remover colaboradores;
- adicionar ou remover seguidores;
- adicionar ou remover etiquetas.

### 5.3 Atividade e linha do tempo

A tela deve possuir uma linha do tempo única e legível, diferenciando visualmente:

- eventos automáticos do histórico;
- comentários públicos;
- notas internas;
- anexos;
- mudanças de status;
- mudanças de prioridade;
- mudanças de prazo;
- encaminhamentos;
- assunções e reatribuições;
- inclusão e remoção de participantes;
- alterações de checklist;
- solicitações de conclusão;
- conclusão, fechamento e cancelamento.

## 6. Comentários públicos e notas internas

Todo ticket pode ter comentários de atividade.

Em tickets integrados existem dois tipos distintos:

### 6.1 Comentário público

- Pode ser enviado de volta ao sistema de origem.
- Pode ficar visível ao solicitante externo no sistema integrado.
- Deve registrar autor, data/hora e origem.

### 6.2 Nota interna

- Visível somente dentro do Sutoorii Tickets.
- Nunca deve ser enviada ao sistema de origem.
- Deve registrar autor e data/hora.

## 7. Checklist

O ticket pode possuir itens de checklist editáveis.

Cada item deve registrar:

- texto;
- ordem;
- situação concluído/não concluído;
- usuário que concluiu;
- data/hora da conclusão.

A conclusão do ticket deve ser bloqueada enquanto houver itens obrigatórios pendentes.

## 8. Anexos

O ticket deve aceitar anexos permitidos pelas configurações do sistema.

Vídeos não fazem parte do escopo de anexos.

Os anexos devem respeitar retenção configurável e registrar:

- autor do upload;
- nome original;
- tipo MIME;
- tamanho;
- data de expiração;
- vínculo opcional com comentário.

## 9. Histórico do ticket e auditoria do sistema

São estruturas diferentes e não devem ser misturadas.

### 9.1 Histórico do ticket

Fica somente dentro do ticket e registra acontecimentos operacionais daquele atendimento.

Exemplos:

- criação;
- encaminhamento;
- assunção;
- reatribuição;
- alteração de status;
- alteração de prioridade;
- alteração de prazo;
- alteração de participantes;
- checklist;
- anexos;
- comentários;
- conclusão.

### 9.2 Auditoria

É uma área administrativa separada e registra ações relevantes sobre o próprio sistema, incluindo:

- criação e alteração de usuários;
- ativação e inativação de usuários;
- mudanças de cargo;
- mudanças de permissões;
- departamentos;
- status;
- cargos;
- integrações;
- configurações;
- exclusões e outras operações administrativas sensíveis.

## 10. Usuários

Deve existir uma área própria de gestão de usuários.

Cada usuário possui:

- nome;
- e-mail;
- cargo;
- departamento;
- situação ativo/inativo;
- permissões individuais.

Usuários comuns podem pesquisar os demais usuários quando necessário para adicionar colaborador, seguidor ou selecionar um responsável, desde que a ação esteja autorizada, sem receber automaticamente acesso administrativo às contas.

## 11. Cargos e permissões

### 11.1 Cargos

Cargos são conjuntos reutilizáveis de permissões padrão.

Usuários autorizados podem:

- criar cargos;
- editar cargos;
- ativar/desativar cargos quando aplicável;
- escolher as permissões padrão de cada cargo.

### 11.2 Permissões individuais

Cada permissão do usuário deve possuir um estado efetivo baseado em três possibilidades:

- **Herdada do cargo**: usa o valor padrão do cargo;
- **Concedida**: força a permissão como permitida para aquele usuário;
- **Negada**: força a permissão como negada para aquele usuário.

A verificação de autorização deve considerar primeiro a configuração individual e, na ausência dela, herdar a configuração do cargo.

Devem existir permissões específicas, entre outras, para:

- criar tickets;
- visualizar tickets do próprio departamento;
- visualizar todos os tickets;
- editar tickets;
- encaminhar tickets;
- assumir ticket;
- reatribuir responsável;
- alterar status;
- alterar prioridade;
- alterar prazo;
- gerenciar participantes;
- comentar;
- criar nota interna;
- gerenciar checklist;
- gerenciar anexos;
- solicitar conclusão;
- concluir ticket;
- enviar ticket para lixeira;
- restaurar ticket;
- excluir definitivamente;
- gerenciar usuários;
- gerenciar cargos;
- gerenciar permissões;
- gerenciar departamentos;
- gerenciar status;
- gerenciar etiquetas;
- gerenciar integrações;
- visualizar auditoria;
- gerenciar configurações.

## 12. Departamentos

Departamentos são caixas/fila de atendimento configuráveis.

Usuários autorizados podem:

- criar departamentos;
- editar departamentos;
- ativar/desativar departamentos;
- visualizar a lista de usuários pertencentes a cada departamento.

Cada usuário pertence a um departamento principal por vez, salvo futura extensão explícita do modelo.

## 13. Etiquetas

Etiquetas devem ser gerenciáveis por usuários autorizados.

A etiqueta de sistema **Atrasada** permanece automática para tickets que ultrapassarem o prazo sem conclusão.

## 14. Conclusão e ciclo de vida

- O responsável pode concluir o ticket quando estiver autorizado e o checklist permitir.
- Colaboradores podem solicitar conclusão.
- Tickets Resolvidos, Fechados e Cancelados permanecem consultáveis, mas ficam fora das caixas padrão.
- Tickets enviados para lixeira devem permanecer recuperáveis durante o período configurado.
- Exclusão definitiva deve exigir permissão específica.

## 15. Recorrência

A recorrência deve criar novos tickets a partir do ticket de origem, preservando a referência da recorrência e os dados definidos pela regra configurada, sem reutilizar o mesmo registro como se fosse um ticket permanente.

## 16. Notificações

O sistema deve possuir central de notificações para eventos relevantes, respeitando preferências e vínculos do usuário.

Notificações podem incluir:

- atribuição como responsável;
- inclusão como colaborador;
- inclusão como seguidor;
- mudança de status;
- comentário;
- anexo;
- encaminhamento;
- solicitação de conclusão;
- prazo próximo ou vencido.

## 17. Integrações com outros sistemas Sutoorii

A estrutura `systems` existente será aproveitada e completada com painel administrativo e API operacional.

Cada sistema integrado deve possuir:

- empresa vinculada;
- nome;
- URL base;
- chave/API token própria;
- webhook de retorno;
- segredo de webhook;
- situação ativo/inativo.

### 17.1 Entrada de tickets

Um sistema autorizado poderá criar tickets via API informando os dados necessários, incluindo referência externa e dados do solicitante.

Tickets criados por integração devem possuir origem `integration` e vínculo com o sistema de origem.

### 17.2 Atualizações e consulta

A API deve permitir operações autorizadas de consulta e atualização sem expor dados de outros sistemas.

### 17.3 Webhooks de saída

O Sutoorii Tickets poderá enviar eventos ao sistema de origem, incluindo quando aplicável:

- criação/aceite do ticket;
- mudança de status;
- comentário público;
- conclusão;
- fechamento;
- cancelamento.

Notas internas nunca fazem parte do payload público de integração.

### 17.4 Segurança

- O token deve ser exibido em texto puro somente no momento da criação/regeneração.
- O banco deve armazenar apenas o hash do token.
- Webhooks devem possuir assinatura/segredo verificável.
- A integração deve poder ser revogada sem excluir o histórico do ticket.

## 18. Administração

O menu administrativo deve ser controlado por permissões e reunir, quando autorizado:

- Usuários;
- Cargos;
- Departamentos;
- Status;
- Etiquetas;
- Integrações;
- Configurações;
- Auditoria.

Não deve existir uma regra do tipo “somente quem tem cargo X entra aqui”. O acesso é sempre calculado por permissões efetivas.

## 19. Interface e experiência

A interface deve ser reorganizada para parecer um help desk completo, mantendo a identidade Sutoorii.

Diretrizes:

- navegação clara entre Minha Caixa, caixas de departamento e administração;
- filtros funcionais, não apenas botões decorativos;
- indicadores de status e prioridade legíveis;
- ações contextuais conforme permissões;
- formulários simples e sem exposição de detalhes técnicos desnecessários;
- tela de ticket com conteúdo principal e painel lateral de propriedades;
- responsividade para desktop e celular;
- preservar suporte PWA já planejado.

## 20. Compatibilidade com a base atual

A implementação deve reaproveitar sempre que possível:

- Laravel existente;
- autenticação e verificação de e-mail;
- recuperação de senha;
- deploy automático GitHub Actions → hospedagem;
- tabelas de tickets, participantes, comentários, anexos, checklist, etiquetas, auditoria, recorrência, notificações e integrações já existentes;
- modelos já criados.

Novas migrations devem ser incrementais. O banco de produção não deve ser apagado nem recriado para aplicar esta finalização.

## 21. Critérios de aceite

A finalização será considerada funcional quando:

1. a Minha Caixa exibir corretamente tickets de responsável, colaborador e seguidor, escondendo concluídos/cancelados por padrão;
2. cada usuário puder acessar a caixa do seu departamento conforme permissão;
3. tickets sem responsável puderem ser assumidos por usuários elegíveis;
4. reatribuição de tickets já assumidos exigir permissão específica;
5. encaminhamento remover responsável, mover departamento e aplicar status Encaminhado;
6. assumir um ticket Encaminhado aplicar Em andamento automaticamente;
7. a tela do ticket permitir todas as operações autorizadas sem rotas 501/incompletas;
8. comentários públicos e notas internas funcionarem separadamente;
9. histórico operacional aparecer somente dentro do ticket;
10. auditoria administrativa existir em área separada;
11. usuários, cargos, departamentos, status, etiquetas e permissões puderem ser administrados por quem tiver autorização;
12. permissões individuais puderem conceder ou negar direitos sobre o cargo;
13. integrações puderem ser criadas no painel e autenticar chamadas por token;
14. tickets integrados puderem receber comentários públicos de retorno por webhook sem expor notas internas;
15. filtros mostrarem Resolvidos, Fechados e Cancelados quando solicitados;
16. testes automatizados cobrirem regras críticas de autorização, visibilidade, encaminhamento, atribuição e integração;
17. o deploy em produção preservar dados já existentes.
