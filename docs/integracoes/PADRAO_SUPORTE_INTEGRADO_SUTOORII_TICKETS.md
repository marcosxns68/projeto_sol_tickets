# Padrão Sutoorii Labs de suporte integrado
## Modelo de referência: Estúdio França → Sutoorii Tickets

**Versão:** 1.0 — 20/09/2026  
**Responsável pelo padrão:** Sutoorii Labs  
**Sistema central:** Sutoorii Tickets — `https://tickets.sutoorii.com`  
**Implementação de referência:** Estúdio França — `https://sistema.estudiofranca.com.br`  
**Finalidade:** orientar a implantação da **mesma experiência de suporte** em todos os sistemas mantidos pela Sutoorii Labs, independentemente da linguagem, da estrutura de usuários e da identidade visual do cliente.

> **Regra de leitura:** cada seção separa o **padrão a reproduzir** do **comportamento observado no código em 20/09/2026** e de **adaptações/pendências**. Uma funcionalidade planejada ou um teste automatizado não deve ser descrito como entrega real de e-mail, WhatsApp ou operação do cron no servidor sem verificação operacional.

---

## 1. Princípios e arquitetura

1. O sistema do cliente contém uma **central de suporte nativa**. O usuário não precisa criar conta, autenticar-se outra vez nem ser redirecionado à interface administrativa do Sutoorii Tickets para abrir ou acompanhar um chamado.
2. O **Sutoorii Tickets é a fonte central de verdade** para os tickets, seus números, descrição inicial, respostas públicas, notas internas, anexos e status de atendimento. O sistema cliente apresenta esses dados pela API.
3. A integração é **servidor a servidor**: o navegador fala com o backend autenticado do sistema cliente; este fala com a API central por HTTPS usando uma chave exclusiva daquela integração. **Nunca colocar a chave no HTML, JavaScript, aplicativo cliente ou requisições diretas feitas pelo navegador.**
4. Cada sistema cliente tem sua **própria integração, chave de API, configurações de departamento, etiquetas e segredo de webhook**. Não reutilizar a chave de outro sistema/cliente.
5. A experiência, o vocabulário e os critérios de permissão são comuns. **Cores, logomarca, menu e nomes da instituição acompanham a identidade do cliente**; o nome do atendimento prestado pela equipe continua **Sutoorii Labs**.
6. Tickets internos da Sutoorii e tickets recebidos de clientes coexistem no painel central. Um ticket oriundo da integração permanece associado ao sistema que o criou, inclusive quando o solicitante de dois clientes possui o mesmo ID local.

### Fluxo de alto nível

```text
Usuário autenticado no sistema cliente
  → Central de suporte / botão flutuante
  → backend do cliente valida sessão, CSRF, dados e identidade
  → HTTPS: API Sutoorii Tickets + chave exclusiva da integração
  → Tickets registra/lê/atualiza ticket, comentário, anexo ou estado
  → backend do cliente apresenta o resultado na interface local
  ↘ Tickets pode notificar e-mail/WhatsApp pelo serviço central
  ↘ Tickets pode enviar webhook assinado para o sistema cliente
```

**Não confundir três canais:** (a) API com chave de integração: ações do cliente no Tickets; (b) webhook assinado: eventos do Tickets enviados ao cliente; (c) diretório de usuários assinado: consulta do Tickets ao cadastro de usuários do cliente. Cada canal tem finalidade e configuração próprias.

---

## 2. Experiência obrigatória no sistema cliente

### 2.1 Entrada e navegação

- Disponibilizar um item **Suporte** no menu local e um **botão flutuante circular de suporte** coerente com o visual do produto. No Estúdio França, o botão abre um painel com “Abrir ticket” e “Chamar no WhatsApp”; “Abrir ticket” leva à **página local de abertura**, não ao login do Tickets.
- O WhatsApp de atendimento direto é **um canal alternativo**; não confundir a conversa direta com as notificações automáticas de tickets disparadas pelo Tickets.
- Manter a sessão e os controles de acesso do sistema cliente. O usuário acompanha seus chamados dentro da mesma aplicação.
- O botão flutuante não deve sobrepor campos, mensagens ou controles em telas pequenas; incluir acessibilidade para abrir, fechar e usar o teclado.

**Referência Estúdio França:** `includes/suporte_flutuante.php`, `includes/footer.php`, `painel/suporte.php` e `painel/suporte_novo.php`. O destino “Abrir ticket” é `painel/suporte_novo.php`.

### 2.2 Central/lista de chamados

A página **Suporte** deve mostrar:
- cabeçalho com pergunta acolhedora **“Como podemos ajudar?”**, descrição curta e botão **“Abrir chamado”**;
- filtros **Prioridade**, **Status** e botão **Filtrar**, alinhados na mesma linha/base visual em desktop, adaptados à largura de celular;
- cards clicáveis com **número do ticket, assunto, nome do solicitante quando a pessoa tiver permissão de gestão, prioridade, indicador único de situação e prazo** quando presente;
- paginação baseada nos resultados filtrados retornados pela API, incluindo quantidade total real.

**Cancelados:** ocultar da lista padrão, **sem apagar os dados**. Incluir opção explícita **“Cancelados”** no filtro de status; ao selecioná-la, mostrar os cancelados, preservando paginação e isolamento de permissões. Não filtrar cancelados somente no HTML após paginar, porque isso cria páginas aparentemente vazias e contagens incorretas.

**Alinhamento e cache de CSS:** cada filtro e o botão devem usar a mesma estrutura de célula/label e altura dos controles. O Estúdio França usa um `form-group` com rótulo visualmente oculto sobre o botão para compartilhar a base dos `select`; os controles têm altura de 48 px e o CSS é versionado pelo `filemtime`. Em outros produtos, reproduzir **o resultado**, não necessariamente o mesmo CSS.

### 2.3 Abertura do chamado

O formulário local recebe **Assunto**, **Prioridade**, **Descrição** e botão **“Enviar chamado”**. O Estúdio França utiliza prioridades Baixa (`low`), Normal (`normal`, padrão), Alta (`high`) e Urgente (`urgent`). Enviar nome, e-mail e WhatsApp **a partir do cadastro autenticado no servidor**, sem permitir que o formulário altere a identidade do solicitante.

- Assunto no formulário de referência: obrigatório, até 180 caracteres.
- Descrição: obrigatória, até 10.000 caracteres; é a **primeira mensagem da conversa**, não só um resumo escondido.
- O telefone de WhatsApp, quando válido, é lido do cadastro do usuário ativo, normalizado para o formato brasileiro `55 + DDD + número` e encaminhado na criação.
- Mostrar no formulário se haverá confirmação pelo WhatsApp cadastrado. Sem telefone válido, **o ticket ainda deve poder ser criado**, avisando que a confirmação por esse canal não será enviada.
- Gerar uma **referência externa idempotente** para a tentativa de abertura, para que duplo clique/reenvio da mesma tentativa não crie dois tickets. Consumir/renovar a referência após confirmação de sucesso.
- Em caso de sucesso, redirecionar para o **detalhe local** usando o número retornado pela API. Em caso de erro, preservar uma mensagem compreensível e evitar perder o texto digitado, quando possível.

**Referência:** `painel/suporte_novo.php` e `api/sutoorii_tickets/criar.php`. No Tickets, `IntegrationTicketController::store` aplica as etiquetas padrão da integração, departamento configurado e prazo definido pela prioridade. O número é gerado **no Tickets**, com 8 dígitos no formato `AAMM + 4 dígitos`; não gerar número local em cada produto.

### 2.4 Detalhe/conversa do ticket

Usar a mesma página de detalhe para listar histórico e permitir resposta, acompanhamento, anexo e ações autorizadas.

**Cabeçalho:** número, título, prioridade, **um único indicador de situação** e data do prazo quando existir; ações de fechamento/reabertura de acordo com autorização e situação. **Não mostrar dois badges contraditórios nem um segundo aviso dizendo “aguardando sua resposta”.**

**Primeira mensagem:** renderizar sempre a `description` do ticket como **mensagem de abertura**, com nome do solicitante e data de criação (`created_at`). Não esperar que ela apareça em `activity`: a API atual devolve a descrição em `ticket.description`, separadamente dos comentários. A ausência dessa renderização foi a causa histórica de tickets aparentemente “em branco” no Estúdio França.

**Histórico:** obter `GET /tickets/{numero}/activity` e mostrar:
- **somente comentários públicos** no portal do cliente; notas internas nunca devem ser serializadas ou exibidas;
- arquivos anexados disponíveis, com link de download autenticado por ticket;
- autor, data/hora e texto integral da resposta; preservar quebras de linha e escapar HTML;
- mensagens do cliente identificadas como cliente e da equipe como **nome individual da pessoa + marcador “Sutoorii Labs”**. Não substituir o nome do funcionário pelo nome da empresa e não deixar o marcador genérico “Suporte” isolado.

**Atenção ao retorno atual:** `activity` contém comentários públicos e anexos como coleções concatenadas; a integração nova deve **ordenar cronologicamente a conversa**, caso o backend continue sem ordenação global de tipos. O texto inicial deve continuar em primeiro lugar.

**Responder:** formulário de texto até 10.000 caracteres, com envio de comentário **público** pelo backend do cliente. Possuir referência idempotente da mensagem e tratar retorno/sucesso para impedir resposta duplicada em reenvios.

**Anexos:** no portal de referência, até **10 MB**, extensões `jpg,jpeg,png,webp,pdf,txt,doc,docx,xls,xlsx,zip`; vídeo não aceito. Enviar e baixar via backend autenticado do cliente, **sem expor chave da API e sem URL pública para armazenamento privado**. No endpoint integrado atual, `expires_at` é 1 ano após upload. Outros tipos ou retenções só após decisão explícita, validação no servidor e revisão da segurança.

### 2.5 Situação pública, sem mensagens conflitantes

O **status interno de processamento** e o **indicador mostrado ao usuário** são coisas diferentes. O status interno continua sendo usado para roteamento, prazos e trabalho da equipe; o portal apresenta apenas **uma situação pública**, sempre no mesmo componente visual discreto **verde** na lista e no detalhe.

**Precedência de apresentação acordada:**
1. Se o ticket está `closed`, `resolved` ou `cancelled`, mostrar **Fechado**, **Resolvido** ou **Cancelado**, mesmo se houve comentário anteriormente.
2. Em ticket não finalizado com última resposta pública da equipe, mostrar **“Sutoorii respondeu”**.
3. Em ticket não finalizado com última resposta pública do solicitante, mostrar **“Você respondeu”** na visão do próprio solicitante.
4. Sem resposta pública, mostrar uma tradução curta e compreensível do status interno, por exemplo **Novo**, **Encaminhado** ou **Em andamento**. Estados de espera podem aparecer como **“Em atendimento”**, sem instrução redundante “aguardando sua resposta”.

**Códigos internos não podem vazar para a tela**: `forwarded` deve aparecer como **Encaminhado**, nunca “FORWARDED”. Traduzir nomes e chaves conhecidas; oferecer fallback em português, sem exibir o código bruto. O componente visual é uniforme, mas o texto preserva o estado finalizado quando necessário.

**Ponto de adaptação obrigatório:** o texto atual “Você respondeu” é adequado à visão do próprio solicitante. Ao implementar uma **visão de gestor sobre tickets de outras pessoas**, ajustar a perspectiva, por exemplo “Solicitante respondeu”, sem alterar o campo `last_public_reply_by`. O Estúdio França ainda compartilha a função de legenda na lista do gestor; não copiar isso sem revisão contextual.

**Referência:** `includes/sutoorii_tickets.php` (`tickets_status_label`, `tickets_situacao_publica`) e os componentes `support-ticket-state` das páginas de lista/detalhe.

---

## 3. Identidade, permissões e isolamento de dados

### 3.1 Identificador do solicitante

A identificação **não pode depender só de nome, e-mail ou telefone**. Cada sistema deve fornecer um **ID externo estável, não ambíguo e exclusivo ao seu namespace**, derivado no backend após autenticação local. Exemplo do Estúdio França: `estudio-franca-17` para o usuário local de ID 17.

Em outro sistema, usar seu próprio namespace, como `projeto-venus-17` **apenas como exemplo**, e documentar a identificação de papéis. Não atribuir a dois usuários diferentes o mesmo ID dentro de uma integração; não trocar IDs ao editar o perfil.

O Tickets também associa cada registro a `system_id` obtido da **chave da integração**; a chave determina qual sistema/cliente possui o ticket.

### 3.2 Papéis

No Estúdio França, os perfis `super_admin` e `gestao` são traduzidos em `manager`; outros usuários autenticados usam `user`. **Essa relação é particular ao produto de referência**: definir o mapeamento por produto. Um gestor autorizado visualiza tickets de **seu sistema integrado**, e o usuário comum visualiza somente os tickets cujo `external_requester_id` seja o seu ID.

O navegador **não pode escolher ou falsificar** `X-External-User-Id` ou `X-External-User-Role`; o servidor cliente os define com base na sessão. **Nunca colocar a chave bearer em código do navegador.** O Tickets confia nesses cabeçalhos depois de validar a chave; portanto, se a chave for exposta ao cliente final, um usuário malicioso poderá alegar `manager`.

O atendimento técnico interno é controlado no Sutoorii Tickets por cargos, permissões, responsáveis, colaboradores, seguidores e departamentos. **Não criar conta administrativa no Tickets para cada usuário final.**

### 3.3 Chaves e webhook

Cadastrar cada integração na administração do Tickets, configurar **nome, URL base, URL webhook quando necessário, departamento de destino, etiquetas padrão, status ativo** e gerar uma chave de API exclusiva. Guardar essa chave **apenas no arquivo de configuração/segredos do servidor cliente**; a interface de configuração nunca deve exibir a chave antiga em texto aberto. Rotação de chave requer atualização coordenada para evitar indisponibilidade.

O Estúdio França lê `sutoorii_tickets_api_key` e `sutoorii_tickets_webhook_secret` de `config.local.php` (com alternativa por variável de ambiente). O segredo de webhook é diferente da chave da API. Para receber webhooks, validar HMAC-SHA256 **sobre o corpo bruto exato** e rejeitar assinatura inválida; em novas integrações, proteger também contra **replay** (janelas de tempo/IDs únicos).

**Diretório de usuários (opcional):** o Tickets pode consultar cadastro local do sistema cliente por `GET /api/sutoorii/users.php?search=...` ou `?id=...`, com assinatura HMAC + timestamp. A pesquisa por nome/e-mail não devolve telefone; a consulta assinada por ID exato pode devolver WhatsApp. **Não presumir que a chave de API por si só configura esse diretório**; o segredo correspondente precisa estar presente dos dois lados.

### 3.4 Proteção de entrada e saída

- Validar sessão, perfil, CSRF, método HTTP, limites de campos e propriedade do recurso **no backend**; HTML e JavaScript não são controles de autorização.
- Usar HTTPS e validação TLS, sem redirecionamentos HTTP para destinos arbitrários, e proteger URLs de webhook/diretório contra SSRF.
- Escapar textos de comentários, descrição, nomes e nomes de arquivo na renderização; sanitizar o nome enviado no download.
- Não registrar chaves, assinaturas, senhas, mensagens sensíveis ou números completos em logs operacionais.
- Aplicar limite de chamadas e timeouts. O middleware da API atual limita cada integração a **120 requisições por janela de 60 segundos**; avaliar também limites por usuário em integrações com muitos acessos.
- Se a API central estiver temporariamente indisponível, mostrar mensagem de erro sem quebrar todo o sistema do cliente.

---

## 4. Contrato de API a reproduzir

**Base atual:** `https://tickets.sutoorii.com/api/v1`  
**Chamadas:** HTTPS feitas pelo backend do sistema integrado.  
**Cabeçalhos obrigatórios por requisição autenticada:**

```http
Authorization: Bearer <CHAVE_SECRETA_DO_SISTEMA>
X-External-User-Id: <NAMESPACE_E_ID_AUTENTICADO>
X-External-User-Role: user
Accept: application/json
```

Apenas para perfis locais com permissão efetiva de gestão, utilizar `X-External-User-Role: manager`. Os símbolos entre `<...>` são **placeholders**; não incluir chaves reais em documentação, tickets ou prints.

| Operação | Método e rota após `/api/v1` | Observações |
|---|---|---|
| Saúde do serviço | `GET /health` | Disponível sem autenticação de integração; não revela tickets. |
| Listagem paginada | `GET /tickets?per_page=25&page=1&priority=normal&status=...` | Prioridade/status opcionais; exclui categoria cancelada por padrão; `status=cancelled` mostra cancelados. Respeita sistema e usuário/papel. |
| Criar ticket | `POST /tickets` | `title`, `description`, `priority`, `requester_name` obrigatórios conforme validação; e-mail, WhatsApp e `external_reference` opcionais. |
| Detalhar | `GET /tickets/{reference}` | Retorna `ticket`, inclusive `description`, status, data de abertura, identificação da última resposta pública. |
| Conversa pública | `GET /tickets/{reference}/activity` | Retorna comentários **públicos** e anexos; não contém a descrição inicial como comentário. |
| Responder | `POST /tickets/{reference}/comments` | JSON `body` e `external_message_id` opcional para idempotência; comentário público da integração. |
| Anexar | `POST /tickets/{reference}/attachments` | Multipart `file`; validar tamanho/extensões no cliente e no Tickets. |
| Baixar anexo | `GET /tickets/{reference}/attachments/{attachment}` | Acesso autenticado e restrito ao ticket/sistema. |
| Fechar/reabrir | `POST /tickets/{reference}/close`, `POST /tickets/{reference}/reopen` | Sem corpo obrigatório; somente usuário autenticado visível pela API. Revisar regra de negócio antes de expor a novos perfis. |
| Completar WhatsApp legado | `POST /requester/whatsapp` | JSON `requester_whatsapp`. **Implementação atual aceita apenas IDs `estudio-franca-N`**; generalizar antes de outros clientes. |

Os tickets são encontrados pelo **número gerado pelo Tickets** ou `external_reference` correspondente; toda consulta exige vínculo com a integração autenticada. **Não usar ID local de um produto para pesquisar tickets de outro.**

### Exemplo seguro de abertura

```json
{
  "external_reference": "abertura-uma-referencia-opaca-por-tentativa",
  "requester_name": "Nome do usuário autenticado",
  "requester_email": "usuario@exemplo.invalid",
  "requester_whatsapp": "5515999998888",
  "title": "Não consigo acessar o sistema",
  "description": "Expliquei o problema e quando começou.",
  "priority": "normal"
}
```

O telefone e a identidade do exemplo representam **dados obtidos no servidor do cliente**, não valores que a pessoa possa substituir no formulário. Não copiar o número fictício para testes de envio real.

### Campos importantes do retorno

`ticket.number`, `title`, `description`, `priority`, `due_at`, `status`, `status_key`, `last_public_reply_by` (`support`, `requester` ou nulo), `last_public_reply_at`, `requester_name`, `requester_email`, `created_at`, `updated_at`. Não usar `status_key` como texto exibido ao cliente.

A listagem vem em `data[]` com `meta.current_page`, `meta.per_page`, `meta.total` e `meta.last_page`. O detalhe vem em `ticket`, e o histórico em `activity[]`. A API usa códigos HTTP; lidar ao menos com 401 (chave), 403 (permissão), 404 (não encontrado), 409 (referência repetida/mudança de estado), 422 (validação), 429 (limite) e indisponibilidade de rede.

---

## 5. Fluxos de atendimento e efeitos

| Ação | No portal do cliente | No Sutoorii Tickets |
|---|---|---|
| Abrir | Registrar assunto, prioridade e descrição com identidade local; redirecionar ao detalhe local. | Criar ticket associado ao sistema, departamento e etiquetas; disponibilizar para triagem/atribuição; avaliar notificações de abertura. |
| Equipe responde publicamente | Mostrar nome da pessoa + Sutoorii Labs, texto e data; atualizar o indicador para “Sutoorii respondeu”. | Salvar comentário público; opcionalmente notificar solicitante por e-mail e WhatsApp. |
| Solicitante responde | Mostrar resposta no histórico; indicador “Você respondeu” na visão do próprio autor. | Salvar comentário público; alertar equipe elegível por e-mail/notificações. Se ticket não finalizado, marcar status interno `requester_replied`; **não reabrir automaticamente** tickets fechados, resolvidos ou cancelados. |
| Equipe cria nota interna | **Não mostrar** nota nem mencionar seu conteúdo em API/webhook público. | Salvar nota interna, restrita à equipe autorizada. |
| Status muda/é encaminhado | Atualizar apenas o indicador público, traduzido em português, sem mensagens repetidas. | Roteamento, responsável/departamento, auditoria e notificações conforme evento/configuração. |
| Fechar/reabrir | Oferecer ações conforme perfil e estado; preservar o histórico. | Atualizar status existente; não recriar ticket para cada reabertura. |
| Cancelar | Ocultar da lista padrão; oferecer filtro “Cancelados”. | Preservar registro e permissões; **cancelar não é excluir**. |
| Anexar | Mostrar anexo na conversa e permitir download autenticado. | Salvar arquivo privado associado ao ticket, com retenção e validação. |

**Distinção importante:** o usuário final pode ver “Sutoorii respondeu” mesmo que o status interno ainda seja `waiting_customer`; a indicação de conversa **não altera automaticamente a regra de roteamento**, e a UI não deve exibir simultaneamente duas interpretações concorrentes.

---

## 6. Notificações por e-mail e WhatsApp

### 6.1 Abertura

O Tickets é responsável pelo envio central. Na criação, envia notificações de abertura por e-mail aos destinatários elegíveis — entre eles solicitante, responsáveis/participantes configurados e seguidores do departamento — sem duplicar o mesmo e-mail na mesma operação. A confirmação pelo WhatsApp destina-se **ao solicitante**, se houver número válido, conexão WhatsApp operacional e automação **Abertura do ticket** habilitada.

**Não prometer confirmação de WhatsApp a quem não cadastrou número ou quando a automação/canal estiver indisponível.** O formulário local pode indicar o número cadastrado, mas o sucesso no cadastro do ticket não é prova de entrega da mensagem.

### 6.2 Resposta pública da equipe: uma opção “Notificar solicitante”

O padrão de interface do Sutoorii Tickets é **uma única caixa “Notificar solicitante”** vinculada ao comentário público; ela **não se chama “Notificar por e-mail”**. A decisão do operador controla **ambos os canais para aquela resposta**:

- **Marcada:** tentar notificar o solicitante por e-mail **quando existir e-mail** e por WhatsApp **quando houver telefone válido, conexão ativa e automação global “Novo comentário público” habilitada**.
- **Desmarcada:** não disparar ao solicitante e-mail nem WhatsApp **por causa desse comentário**.
- **Nota interna:** nunca notificar o solicitante nem publicar conteúdo interno no portal.
- **Comentário e alteração de status no mesmo salvamento:** não enviar um segundo aviso genérico nem um aviso de status que contorne a opção desmarcada. A equipe pode receber notificações próprias, selecionadas separadamente, sem que isso marque o solicitante.

**A opção marcada não ignora configurações globais:** se a automação WhatsApp para comentários estiver desligada, o e-mail ainda pode ser enviado. A entrega efetiva depende do SMTP, da Evolution API, do agendador/worker e dos contatos cadastrados.

**Escopo atual:** a caixa única foi aplicada ao comentário público no painel do Tickets. As automações de **abertura, fechamento e mudança de status** têm configurações e disparadores próprios; não tratar a caixa de comentário como um interruptor global de todos os eventos.

### 6.3 Resposta do cliente

Uma resposta pública feita pelo cliente no sistema integrado, no portal assinado ou no Tickets **avisa automaticamente o responsável** por e-mail e pelo sininho, sem checkbox do cliente. Também tenta enviar **WhatsApp ao número próprio do responsável**, quando cadastrado e válido, com conexão Evolution API operacional, preferência pessoal ativada e automação global “Resposta do solicitante ao responsável” habilitada. Os demais participantes da equipe continuam sujeitos às preferências existentes. **Não enviar WhatsApp ao cliente informando que ele mesmo respondeu.** A interface local passa a indicar a última resposta pública. O WhatsApp enviado à equipe informa o número e assunto do ticket, sem copiar o corpo do comentário; o destinatário abre a conversa autenticada para ler os detalhes.

### 6.4 Seguir uma caixa de departamento: escolhas independentes

Em **Departamentos → Acompanhar [nome do departamento]**, cada usuário com permissão de visualizar a caixa escolhe separadamente **E-mail**, **WhatsApp** e **Push real para o PWA instalado, mesmo fechado**. As escolhas são por usuário e por departamento. Desmarcar todas desativa o acompanhamento daquela caixa, sem modificar as permissões de visualização. O usuário não escolhe se o responsável pelo ticket será avisado: notificações próprias do responsável continuam independentes.

- Na **abertura**, o seguidor recebe mensagem identificando o nome exato da caixa, o número, o assunto e um link autenticado. No **encaminhamento para a caixa** e na **resposta pública do cliente**, o evento também é identificado com a caixa correta. A equipe que acompanha outra caixa não recebe o aviso.
- **E-mail:** depende do endereço verificado/configuração SMTP; não dispara por simples mudança de outros departamentos. **WhatsApp:** usa o telefone pessoal do usuário cadastrado no Sutoorii Tickets, a preferência de avisos pessoais e a Evolution configurada centralmente; nunca o número do solicitante.
- **Push no PWA instalado:** o usuário abre **Departamentos → Acompanhar caixa**, clica **Ativar push neste celular**, concede a permissão do Android/navegador e marca a opção **Push do aplicativo** para a caixa; o navegador registra uma assinatura vinculada à conta. O serviço central guarda a assinatura do dispositivo de forma criptografada e entrega o evento pelo protocolo Web Push (VAPID), diretamente ao service worker, inclusive com a interface do PWA fechada. Ao tocar no aviso abre o ticket autenticado. O botão **Desativar push neste dispositivo** cancela a assinatura sem alterar e-mail e WhatsApp. A entrega depende do serviço de push, dispositivo on-line, permissão do sistema e execução dos workers no servidor. Encerrar à força o aplicativo pelo sistema operacional, restrições severas de bateria e bloqueio de notificações podem impedir a entrega. A implementação não é uma promessa de recebimento em qualquer circunstância.
- A caixa exibe um contador de tickets novos/atualizados desde a última confirmação do usuário, destaque nas linhas e **“Marcar novidades como vistas”**. A mera visita à página não marca automaticamente as novidades; marcar como vistas na caixa não apaga o histórico dos tickets.
- Na atualização, seguidores existentes preservam e-mail/sininho como anteriormente, mas não passam a receber WhatsApp automaticamente. Para habilitá-lo devem selecionar o canal e ter o telefone cadastrado.

### 6.5 Configuração e processamento

O painel administrativo do Tickets oferece cinco automações de WhatsApp destinadas ao fluxo individual de tickets. O aviso de acompanhamento de departamento **é controlado pela preferência de cada caixa e usuário** e não depende da automação de comentário enviada ao solicitante. Os cinco eventos individuais são: **abertura**, **fechamento**, **comentário público da equipe para o solicitante**, **mudança de status** e **resposta pública do solicitante ao responsável**. No código, abertura e resposta ao responsável iniciam habilitadas por padrão; fechamento, comentário ao solicitante e status iniciam desabilitados até configuração. O último evento é **independente** da automação de comentários destinada ao cliente e da caixa “Notificar solicitante”. Nunca deduzir que os outros canais estão funcionando apenas porque a confirmação de abertura chega.

A integração de WhatsApp é operada pelo Tickets (Evolution API); e-mail também é configurado no Tickets. Os jobs de WhatsApp utilizam marcadores/idempotência para evitar repetição; isso não equivale a garantia de entrega. **Jobs de comentários, status e aviso WhatsApp ao responsável usam uma tentativa (`tries=1`) com registro de falha**, sem reenvio persistente automático; caso o negócio exija garantia, evoluir essa política separadamente.

O agendador Laravel descreve worker/fila por minuto e outras tarefas, mas **publicar código pelo GitHub não prova que o cron está instalado/executando na hospedagem**. Validar fila, logs, SMTP, canal WhatsApp e entrega real com contas de teste de cada integração.

---

## 7. Sincronização do telefone de tickets antigos

O número de WhatsApp do **cadastro atual** do usuário cliente pode estar ausente no ticket criado antes da coleta desse dado. O fluxo de referência foi corrigido assim:

1. No **Estúdio França**, ao abrir a central de suporte ou o detalhe, o backend obtém o WhatsApp **do próprio usuário ativo autenticado** e chama `POST /api/v1/requester/whatsapp` usando a chave de integração já configurada.
2. No Tickets, a operação preenche **somente registros sem telefone**, da **mesma integração** e do **ID externo exato**; não usa correspondência por nome/e-mail, não sobrescreve telefones já gravados e **não envia mensagens retroativas**.
3. Para usuários que não voltarem a acessar a central, o superadministrador do Estúdio França pode usar **Configurações → Integração com Sutoorii Tickets → “Sincronizar WhatsApp dos tickets antigos”**. A operação verifica cadastros em lotes, informa quantos tickets foram preenchidos e permite continuar em novo lote.
4. O Tickets mantém também uma busca de telefone pelo diretório assinado, dependente do segredo correspondente; **essa busca falhou anteriormente em produção por falta do segredo**, por isso a sincronização autenticada do lado do cliente é o caminho funcional de referência.

**Limitação de portabilidade a corrigir antes de implantar em outro produto:** a rota `POST /requester/whatsapp`, o serviço `IntegrationRequesterWhatsAppSync` e o comando de backfill ainda validam/filtram IDs com prefixo `estudio-franca-`. **Não reaproveitar esse prefixo nem executar backfill de outro cliente pela rotina atual.** Criar mecanismo genérico restrito à integração autenticada, com namespace configurável e checagens de usuário/tenant, testes de isolamento, consentimento/correção de contato e auditoria. O formato brasileiro do número é parte da integração atual; adaptar a validação somente se o produto atender usuários de outros países.

A alteração de telefone no perfil **não substitui automaticamente números já armazenados em tickets existentes** na implementação atual. Decidir política de atualização de contatos de forma explícita, com confirmação de identidade, autorização e proteção de dados; não sobrescrever em massa por conveniência.

---

## 8. Webhooks e sincronização com o sistema cliente

Quando configurado, o Tickets pode emitir webhooks assíncronos para alterações públicas/status do atendimento. O payload base inclui `event`, `ticket_number`, `external_reference`, `status`, `status_key` e `occurred_at`; eventos de comentário público podem incluir `comment.body` e `comment.created_at`. A requisição é assinada em `X-Sutoorii-Signature: sha256=...` com segredo exclusivo da integração, e tem tentativas/recuos definidos no job central.

**Estado real no Estúdio França:** `api/integracoes/sutoorii-tickets/webhook.php` valida assinatura e registra **metadados do evento** no banco local. Ele **não mantém uma cópia completa e atualizada do ticket nem transmite mensagens em tempo real à tela**. A página de suporte busca a lista/detalhe/histórico diretamente pela API ao ser carregada. Portanto, não descrever o webhook atual como “sincronização completa” ou “atualização ao vivo”.

**Padrão para outros sistemas:** permitir receber e validar eventos assinados se houver necessidade de contadores, indicadores de resposta, alertas locais ou cache; usar IDs de evento/deduplicação e recarregar dados da API com autorização. Não exibir comentários internos, não publicar automaticamente conteúdo recebido num canal público e não confiar apenas no webhook para definir permissão de leitura. Definir requisitos de tempo real separadamente.

---

## 9. Responsabilidades de cada lado

| Sistema cliente | Sutoorii Tickets |
|---|---|
| Autenticar usuário, decidir `user`/`manager`, guardar chave somente no backend. | Autenticar chave da integração, vinculá-la ao `system_id` e aplicar isolamento no banco. |
| Expor botão flutuante, central, abertura, lista, detalhe, conversa e ações no próprio visual. | Gerar número único; armazenar ticket, descrição, comentários, estados, anexos e histórico central. |
| Ler nome/e-mail/WhatsApp do cadastro local; criar ID externo estável. | Validar dados, normalizar e proteger telefone no armazenamento, aplicar prioridade/prazo/etiquetas/departamento. |
| Enviar requisições autenticadas, tratar erro/timeout e nunca mostrar segredos. | Processar notificações, filas e webhooks; disponibilizar API consistente. |
| Renderizar situação pública única, texto inicial e comentários públicos; não renderizar notas internas. | Impedir acesso entre integrações e entre solicitantes comuns do mesmo sistema. |
| Sincronizar contato de tickets antigos só do usuário/tenant correto. | Preencher dados ausentes sem sobrescrever telefones existentes nem criar avisos retroativos. |
| Validar CSRF e uploads; preservar identidade visual do cliente. | Aplicar permissões, validar arquivos, armazená-los privadamente e controlar retenção. |

---

## 10. Implementação no próximo sistema: procedimento recomendado

**Fase A — levantamento e decisão de escopo**

- [ ] Identificar stack, autenticação, perfis e tabela de usuários; definir os IDs externos estáveis e os papéis que podem ver tickets de outras pessoas.
- [ ] Confirmar URL pública do produto, domínio da central e URL interna de abertura, lista, detalhe e download.
- [ ] Verificar se usuário possui nome, e-mail e WhatsApp; normalizar números e decidir como lidar com cadastro incompleto e tickets preexistentes.
- [ ] Definir identidade visual do botão flutuante e das páginas, mantendo comportamento/palavras comuns.
- [ ] Confirmar quais usuários podem fechar/reabrir tickets e se o produto exige aprovação, autorização adicional ou limitações específicas.

**Fase B — preparar integração central**

- [ ] Criar integração separada na administração do Sutoorii Tickets, com departamento, etiquetas padrão, URL base, webhook quando usado e ativação.
- [ ] Guardar chave da API **em segredo de servidor**, nunca no repositório; planejar rotação.
- [ ] Se for usar diretório/webhook, gerar e configurar o segredo correspondente de ambos os lados; verificar assinatura, HTTPS e URLs permitidas.
- [ ] **Generalizar e testar a rota de sincronização de WhatsApp antes de usá-la fora do Estúdio França**.
- [ ] Garantir isolamento de `system_id` + `external_requester_id` e que o backend, não o navegador, atribui papel de gestão.

**Fase C — construir a experiência no produto cliente**

- [ ] Botão de suporte com acesso ao ticket nativo, canal direto opcional e versão móvel.
- [ ] Central com filtros responsivos corretamente alinhados, paginação real e “Cancelados” somente sob filtro.
- [ ] Abertura com assunto, descrição, prioridade, dados do solicitante obtidos no backend e referência de idempotência.
- [ ] Detalhe com mensagem inicial, última resposta, nomes corretos, comentário público, anexos/download autenticado e estados coerentes.
- [ ] Traduzir todos os status internos; usar um só indicador público verde, inclusive para tickets antigos.
- [ ] Preservar todas as regras de permissão/CSRF/validação, incluindo acessos de usuário comum e gestor.

**Fase D — comunicação e operação**

- [ ] Confirmar e-mail SMTP e as cinco automações de WhatsApp, incluindo retorno do solicitante ao responsável, sem presumir que todas estão ligadas.
- [ ] Testar seguimento de departamentos com e-mail, WhatsApp e avisos do navegador em todas as oito combinações de canais; testar abertura, encaminhamento, resposta do cliente, isolamento entre caixas e desativação individual.
- [ ] Garantir que a página da caixa destaque tickets novos/atualizados até a confirmação explícita; **testar em PWA Android instalado com o aplicativo completamente fechado**, após ativar push e permitir notificações no dispositivo.
- [ ] Verificar o processamento da fila no servidor e a entrega real do push, além da abertura do ticket autenticado ao tocar no aviso. Os testes de CI validam o código e as regras de autorização, mas não substituem o teste no celular.
- [ ] Cada responsável deve cadastrar o próprio WhatsApp em **Meu perfil → Notificações por WhatsApp** ou ter o número preenchido pelo administrador em **Usuários → Editar**. O número do cliente armazenado no ticket **não** substitui o número do funcionário.
- [ ] Testar respostas enviadas pelo portal interno, pelo portal assinado e pela integração: e-mail + sininho para o responsável, WhatsApp separado quando elegível, sem enviar ao solicitante um aviso sobre a própria resposta.
- [ ] Testar a caixa **“Notificar solicitante”** em comentário público marcada/desmarcada; validar e-mail e WhatsApp de maneira independente.
- [ ] Testar resposta do próprio cliente: equipe recebe aviso e cliente **não** recebe confirmação redundante por resposta própria.
- [ ] Testar comentário + mudança de status no mesmo salvamento, sem notificações duplicadas ou contrariando checkbox.
- [ ] Validar fila, cron, webhook e logs da integração **em produção**, além da suíte automatizada.

**Fase E — validação/aceite**

- [ ] Usuário A não vê, comenta ou baixa anexo do usuário B; o gestor acessa somente o seu sistema; integração X não acessa integração Y.
- [ ] Abertura dupla com mesma referência gera um ticket; resposta repetida com mesmo ID externo não duplica mensagem.
- [ ] Descrição da abertura aparece no detalhe; notas internas nunca aparecem na API pública; comentários mostram pessoa + Sutoorii Labs.
- [ ] Lista e detalhe exibem exatamente a mesma situação pública; `forwarded` aparece em português; não há “aguardando sua resposta” duplicado.
- [ ] Cancelados não aparecem no padrão, mas aparecem no filtro com total e paginação corretos.
- [ ] Filtrar fica alinhado em desktop e celular, inclusive após atualização de CSS em navegador com cache.
- [ ] Novos tickets usam WhatsApp cadastrado; tickets antigos sem telefone podem ser completados com identidade exata, sem sobrescrita nem mensagens retroativas.
- [ ] E-mail e WhatsApp são verificados com teste de entrega real; o sucesso do deploy não substitui essa verificação.

---

## 11. Pontos do primeiro projeto que NÃO devem ser replicados sem correção

Estas observações são **limitações do código verificado**, não decisões de produto a propagar:

1. **Código de contato legado restrito ao Estúdio França.** A rota e o serviço de sincronização aceitam `estudio-franca-N`. Generalizar e testar isolamento antes de integrar Vênus, Mercúrio, Marte, Áquila ou qualquer outro produto.
2. **Diretório com endpoint PHP fixo.** `IntegrationUserDirectory` aponta para `/api/sutoorii/users.php`; novos produtos podem ter outras linguagens/rotas. Criar configuração de endpoint/contrato e assinatura consistente sem liberar SSRF.
3. **Gestores exibem “Você respondeu” para comentário feito por terceiros.** Ajustar a legenda ao ponto de vista de quem está lendo.
4. **A API de fechamento/reabertura integrada não percorre todo o fluxo do controlador interno.** O código atual atualiza status, mas não reproduz integralmente auditoria, e-mails de ciclo de vida e webhooks do fluxo interno. Unificar comportamento e validar permissões antes de reutilizar amplamente.
5. **O webhook do Estúdio França registra eventos, mas não atualiza localmente a conversa em tempo real.** Se outro produto exigir contadores/alertas locais, especificar e implementar tal comportamento.
6. **A API de atividade devolve comentários e anexos concatenados, não uma conversa globalmente ordenada.** Ordenar por data/ID ao renderizar ou evoluir o contrato.
7. **Fechamento de um ticket pela API pode ter regras diferentes das ações internas.** Exigir regras explícitas por papel e status. Na tela de detalhe de referência, o botão ainda trata apenas `closed`/`resolved` como estados concluídos; revisar a interação de tickets cancelados antes de ampliar o escopo.
8. **Jobs/SMTP/cron e entrega real não são provados por teste de código ou deployment bem-sucedido.** Definir observabilidade, testes operacionais e política de falha/reenvio conforme necessidade de cada cliente.
9. **A rotina de backfill assinada do Tickets depende do segredo do diretório.** A falha histórica `sem_segredo` mostrou que a chave de API, sozinha, não habilita essa consulta. Preferir o push autenticado por usuário/ação administrativa quando apropriado, após generalização.
10. **A resposta pública do cliente pela API integrada não grava todos os mesmos eventos de auditoria de um comentário interno do Tickets.** Uniformizar a trilha de eventos sem expor notas internas.

Esses itens são parte do **checklist de engenharia da próxima integração**. Não atribuir-lhes estado “concluído” por causa da existência deste documento.

---

## 12. Fontes de implementação e atualização deste padrão

**No repositório Estúdio França `marcosxns68/projeto_terra`:**
- `includes/sutoorii_tickets.php` — cliente da API, identidade, status traduzido e sincronização de telefone.
- `includes/suporte_flutuante.php` — acesso contextual ao suporte.
- `painel/suporte.php`, `painel/suporte_novo.php`, `painel/suporte_detalhe.php` — interface e comportamento.
- `api/sutoorii_tickets/{criar,responder,estado,anexar,download,sincronizar_whatsapp_antigos}.php` — rotas locais.
- `api/sutoorii/users.php` e `api/integracoes/sutoorii-tickets/webhook.php` — diretório e recebimento de eventos.
- `assets/css/style.css` e `includes/header.php` — alinhamento dos filtros e versionamento de CSS.
- `tests/run.php`, `tests/static_checks.sh` — verificações de referência.

**No repositório central `marcosxns68/projeto_sol_tickets`:**
- `routes/api.php` e `app/Http/Controllers/Api/V1/IntegrationTicketController.php` — contrato e isolamento da API.
- `app/Http/Middleware/AuthenticateIntegration.php` — autenticação e limite de requisições.
- `app/Models/{Ticket,ConnectedSystem}.php`, `app/Services/{IntegrationSettings,IntegrationUserDirectory,IntegrationRequesterWhatsAppSync}.php`.
- `app/Services/{TicketNotifier,TicketWhatsAppAutomations,IntegrationWebhookDispatcher,RequesterReplyWorkflow}.php`.
- `app/Jobs/{SendTicketWhatsAppAutomation,SendIntegrationWebhook}.php`.
- `app/Http/Controllers/{TicketCommentController,TicketController,TicketLifecycleController}.php`.
- `tests/Feature/{IntegrationApiV1SafeTest,IntegrationRequesterWhatsAppPushTest,RequesterCommentNotificationChoiceTest,WhatsAppCombinedTicketUpdateTest}.php`.

**Manutenção do padrão:** sempre que a API central, a política de notificação ou o comportamento público da central mudar, atualizar este arquivo **na mesma entrega** e conferir os sistemas já integrados. Registrar no PR qual seção do padrão mudou, se há compatibilidade retroativa e quais testes operacionais foram executados. A especificação aprovada prevalece sobre divergências acidentais do primeiro cliente.

**Segurança documental:** este documento contém **somente placeholders e exemplos fictícios**. Chaves de API, segredos de webhook, tokens de implantação e números reais dos clientes jamais devem ser acrescentados a ele.
