# PlayMoz Hyper Network Scanner

Este serviço substitui a resolução antiga baseada em `/api/download`. O PHP consulta a página pública de reprodução do Hyper e o Chromium observa as respostas reais de rede via Chrome DevTools Protocol (CDP).

## Rotas utilizadas

- Filme: `https://hyper.hyperapps.site/filmes/{slug}/assistir`
- Série: a ficha `/series/{slug}` é lida pelo PHP para localizar o link exacto do episódio:
  `/series/{slug}/temporada/{temporada}/episodio/{episodio}`

O serviço só aceita páginas HTTPS de reprodução do domínio `hyper.hyperapps.site`. Não aceita URLs arbitrários, não descarrega o vídeo e não tenta contornar DRM, autenticação ou outras protecções.

## Requisitos no servidor

- Linux com permissão para manter um processo Node.js em execução.
- Node.js 22 ou superior (usa o WebSocket nativo do Node).
- Chromium/Chrome instalado no mesmo servidor.
- PHP 8+ com extensão cURL.
- O processo PHP e o scanner devem conseguir comunicar por `127.0.0.1`.

> A instalação apenas de PHP num alojamento partilhado não é suficiente. É necessário um VPS, container ou plano de alojamento que permita executar um processo Node.js persistente e Chromium.

## Instalação

1. Instale Node.js 20+ e Chromium no servidor. Em Debian/Ubuntu, por exemplo:

   ```bash
   sudo apt update
   sudo apt install -y nodejs npm chromium
   ```

   Alguns repositórios chamam ao pacote `chromium-browser`. Ajuste `CHROMIUM_PATH` conforme o caminho real.

2. Entre nesta pasta e crie o ficheiro de configuração:

   ```bash
   cd scanner
   cp .env.example .env
   chmod +x start.sh
   ```

   O scanner usa o WebSocket nativo do Node.js e não precisa de dependências npm externas.

3. Edite `scanner/.env` e defina um segredo longo e aleatório em `PM_SCANNER_TOKEN`. Confirme também `CHROMIUM_PATH`.

   Pode gerar um segredo com:

   ```bash
   openssl rand -hex 32
   ```

4. Inicie o serviço:

   ```bash
   chmod +x start.sh
   ./start.sh
   ```

5. Teste localmente:

   ```bash
   curl http://127.0.0.1:8787/health
   ```

   A resposta deve conter `"ok":true`.

6. Configure **o mesmo** `PM_SCANNER_TOKEN` no ambiente do PHP-FPM/Apache. Configure também, se necessário:

   ```text
   PM_HYPER_SCANNER_URL=http://127.0.0.1:8787/scan
   PM_SCANNER_TOKEN=O_MESMO_SEGREDO_DO_SCANNER
   ```

   Estas variáveis têm de estar disponíveis para o processo PHP que executa `filme/index.php` e `serie/index.php`. Defini-las apenas na sessão SSH não as torna automaticamente disponíveis ao PHP-FPM.

Num servidor PHP-FPM, pode declará-las no pool (por exemplo, `/etc/php/8.x/fpm/pool.d/www.conf`):

```ini
env[PM_HYPER_SCANNER_URL] = http://127.0.0.1:8787/scan
env[PM_SCANNER_TOKEN] = O_MESMO_SEGREDO_LONGO
```

Depois reinicie o PHP-FPM. Se usar Apache com mod_php, defina as mesmas variáveis no VirtualHost/ambiente do Apache.

## Execução permanente com systemd

Crie `/etc/playmoz-hyper-scanner.env`:

```text
PM_SCANNER_TOKEN=O_MESMO_SEGREDO_LONGO
SCANNER_HOST=127.0.0.1
SCANNER_PORT=8787
CHROMIUM_PATH=/usr/bin/chromium
SCAN_TIMEOUT_MS=35000
MAX_CONCURRENT_SCANS=2
```

Crie `/etc/systemd/system/playmoz-hyper-scanner.service` (substitua os caminhos e o utilizador):

```ini
[Unit]
Description=PlayMoz Hyper Network Scanner
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/playmoz/scanner
EnvironmentFile=/etc/playmoz-hyper-scanner.env
ExecStart=/usr/bin/node /var/www/playmoz/scanner/server.js
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

Depois:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now playmoz-hyper-scanner
sudo systemctl status playmoz-hyper-scanner
```

O utilizador do serviço precisa de permissão para executar Chromium e criar ficheiros temporários.

## Diagnóstico

- `GET http://127.0.0.1:8787/health`: confirma que o scanner está activo.
- `401 unauthorized`: o token configurado no PHP não coincide com o token do scanner.
- `503 scanner_token_not_configured`: falta configurar `PM_SCANNER_TOKEN`.
- `Chromium não disponibilizou o DevTools`: caminho ou permissões do Chromium.
- `O navegador não observou uma resposta MP4, HLS ou DASH`: o player não iniciou a reprodução, a origem não respondeu ou não expôs um recurso de media compatível.

O serviço limita a concorrência, remove o perfil temporário do Chromium no fim de cada análise e não regista URLs assinados nos logs.
