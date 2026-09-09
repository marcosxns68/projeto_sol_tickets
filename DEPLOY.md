# Deploy automático

O workflow `.github/workflows/deploy.yml` publica a branch `main` por FTPS.

Cadastre no GitHub, em **Settings → Secrets and variables → Actions**, os secrets:

- `FTP_SERVER`: servidor FTP/FTPS da hospedagem;
- `FTP_USERNAME`: usuário FTP;
- `FTP_PASSWORD`: senha FTP;
- `FTP_SERVER_DIR`: diretório remoto, sempre terminando com `/`.

O arquivo `.env` não é enviado. Ele precisa ser criado uma única vez no servidor com as configurações de produção.

O domínio `tickets.sutoorii.com` deve ter como document root a pasta `public` do Laravel. O cron da hospedagem deve executar `php artisan schedule:run` a cada minuto, e a fila precisa ser processada regularmente.
