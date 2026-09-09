# Deploy automático

O workflow `.github/workflows/deploy.yml` publica a branch `main` por FTPS.

Cadastre no GitHub, em **Settings → Secrets and variables → Actions**, os secrets:

- `FTP_SERVER`: servidor FTP/FTPS da hospedagem;
- `FTP_USERNAME`: usuário FTP;
- `FTP_PASSWORD`: senha FTP;
- `FTP_SERVER_DIR`: diretório remoto, sempre terminando com `/`.
- `APP_KEY`: chave fixa da aplicação no formato `base64:...`;
- `DB_HOST`: servidor MySQL da hospedagem;
- `DB_DATABASE`: nome do banco;
- `DB_USERNAME`: usuário do banco;
- `DB_PASSWORD`: senha do banco;
- `MAIL_PASSWORD`: senha da conta `tickets@sutoorii.com`.

O arquivo `.env` é montado apenas durante o workflow usando os Secrets e enviado ao servidor. Nenhum valor sensível fica salvo no repositório.

O domínio `tickets.sutoorii.com` deve ter como document root a pasta `public` do Laravel. O cron da hospedagem deve executar `php artisan schedule:run` a cada minuto, e a fila precisa ser processada regularmente.
