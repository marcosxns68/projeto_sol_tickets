# Configuração de e-mail pelo Super Admin — Design

## Objetivo
Permitir que o Super Admin configure o SMTP do Sutoorii Tickets pela interface administrativa, sem editar `.env` e sem expor credenciais no GitHub.

## Acesso
- Nova área: Administração > Configurações > E-mail.
- Acesso somente para usuário com `users.manage` e `permissions.manage`.
- Usuários sem as duas permissões recebem 403.

## Persistência
Usar a tabela existente `settings` com chaves globais:
- `mail.host`
- `mail.port`
- `mail.username`
- `mail.password_encrypted`
- `mail.encryption`
- `mail.from_address`
- `mail.from_name`

A senha é armazenada com `Crypt::encryptString()` e nunca retornada para a view. Campo de senha vazio significa manter a senha atual.

## Aplicação dinâmica
Um serviço `MailSettings` lê os valores do banco e aplica em runtime às chaves `mail.mailers.smtp.*` e `mail.from.*`. Se não houver configuração no banco, mantém o `config/mail.php` carregado do `.env`.

A aplicação ocorre antes de envios feitos por requisição web, inclusive cadastro, reenvio administrativo, recuperação de senha e botão de teste.

## Tela
Campos:
- Servidor SMTP
- Porta
- Criptografia (`ssl`, `tls` ou nenhuma)
- Usuário
- Senha
- E-mail remetente
- Nome remetente

A tela informa se já existe senha salva, sem revelar o valor.

## Teste de envio
Após salvar, o Super Admin pode informar um destinatário e clicar em “Enviar e-mail de teste”. O sistema usa a configuração efetiva e exibe sucesso ou mensagem amigável de erro, sem mostrar senha.

## Segurança
- Nunca gravar senha em log, auditoria ou resposta HTML.
- Auditoria de alteração registra apenas campos não sensíveis e se a senha foi alterada (`password_changed: true/false`).
- Não salvar credenciais no repositório.
- Rate limit no envio de teste.

## Compatibilidade
- Fallback completo para `.env` enquanto nenhuma configuração de banco existir.
- Não exige migration nova porque a tabela `settings` já existe.
