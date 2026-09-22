# Cloudflare Turnstile — ativação e revisão

Este branch adiciona proteção de formulários públicos com validação **no servidor**.
Não envolve mudanças no banco de dados. As versões atualmente instaladas não
ficam protegidas até que esta alteração seja revisada, publicada e ativada.

## Configuração no servidor

Crie um widget Turnstile no painel da Cloudflare e autorize exatamente o(s)
hostname(s) de produção do sistema. Configure as duas chaves no ambiente
do PHP/hospedagem; nunca inclua a chave secreta em páginas HTML, scripts,
repositórios Git ou prints.

- Chave pública/site key: `TURNSTILE_SITE_KEY`
- Chave secreta/secret key: `TURNSTILE_SECRET_KEY`
- Hostname autorizado (opcional, mas recomendado): `TURNSTILE_HOSTNAME`

Na aplicação com arquivo de configuração local, também são aceitos os
campos de configuração Turnstile documentados pelo código do helper.

Com as duas chaves vazias, a proteção permanece desabilitada, para evitar
bloquear usuários antes da configuração. **Nunca interprete esse modo como
uma instalação protegida.** Se apenas uma chave for preenchida, os
formulários protegidos recusam o envio até a configuração estar completa.

Os tokens são únicos e expiram; obtenha um token novo ao repetir uma ação.
Falha de rede, token inválido ou erro na configuração devem ser tratados
como falha de verificação, e não como aprovação.

## Fluxos abrangidos

POST de login, cadastro interno e solicitação de redefinição de senha. Integrações API e webhooks não recebem desafio.

## Validação antes da publicação

1. Preencher um widget de teste e verificar que um token válido permite o
   envio do formulário; verificar também ausência, reutilização e expiração
   de token, falha de rede e configuração incompleta.
2. Com chaves vazias, verificar que os fluxos anteriores seguem operando.
3. Verificar usabilidade em celulares e no PWA quando aplicável, e que
   chamadas de integrações/webhooks permanecem sem desafio de navegador.
4. Confirmar o hostname definitivo, a extensão PHP cURL quando usada e
   autorização de saída HTTPS do servidor para a API da Cloudflare.
5. Somente após revisar as verificações, mesclar e publicar via
   processo habitual do sistema; adicionar as chaves reais fora do GitHub.
