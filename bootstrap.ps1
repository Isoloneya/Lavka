#Requires -Version 5.1
<#
  Lavka Engine: bootstrap з порожньої папки.

  Що робить:
    1. Створює Docker-оточення (compose.yaml, Dockerfile, Caddyfile), .gitignore, .gitattributes
    2. Збирає образ і встановлює Symfony 7.4 skeleton
    3. Встановлює пакети (API Platform, Doctrine, Messenger, Workflow, Security, JWT тощо)
    4. Створює структуру bounded contexts, phpstan.neon, шаблон ADR
    5. Правит services.yaml і composer.json (scripts)
    6. Генерує JWT-ключі і піднімає контейнери

  Запуск (з кореня проєкту, де лежить цей файл):
    powershell -ExecutionPolicy Bypass -File .\bootstrap.ps1

  Скрипт можна безпечно запускати повторно: існуючі файли не перезаписуються,
  уже виконані кроки пропускаються.
#>
$ErrorActionPreference = 'Continue'

$root = $PSScriptRoot
Set-Location $root
$utf8 = New-Object System.Text.UTF8Encoding($false)   # UTF-8 без BOM

function Step([string]$text) {
    Write-Host ""
    Write-Host "=== $text ===" -ForegroundColor Cyan
}

function Run([string]$what, [scriptblock]$cmd) {
    & $cmd
    if ($LASTEXITCODE -ne 0) {
        throw "Помилка на кроці '$what' (код виходу $LASTEXITCODE)"
    }
}

function Write-FileIfMissing([string]$Path, [string]$Content) {
    if (Test-Path $Path) {
        Write-Host "  пропущено (існує): $Path"
        return
    }
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    $Content = $Content -replace "`r`n", "`n"     # у контейнері потрібні LF
    [System.IO.File]::WriteAllText((Join-Path $root $Path), $Content, $utf8)
    Write-Host "  створено: $Path"
}

try {

# ---------------------------------------------------------------------------
Step '1/6 Перевірка інструментів'
# ---------------------------------------------------------------------------
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker не знайдено. Встанови Docker Desktop.' }
if (-not (Get-Command git -ErrorAction SilentlyContinue))    { throw 'Git не знайдено. Встанови Git for Windows.' }
docker info *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker Desktop не запущено. Запусти його, дочекайся статусу Running і повтори.' }
Write-Host '  docker і git на місці'

if (-not (Test-Path '.git')) {
    git init | Out-Null
    Write-Host '  виконано git init'
}

# ---------------------------------------------------------------------------
Step '2/6 Docker-оточення та службові файли'
# ---------------------------------------------------------------------------

Write-FileIfMissing 'compose.yaml' @'
name: lavka

x-app: &app
  build:
    context: .
    dockerfile: docker/php/Dockerfile
  volumes:
    - .:/app
    # var/ (кеш Symfony) у named volume: на Windows це помітно швидше за bind mount
    - var_data:/app/var
  environment:
    APP_ENV: dev
    DATABASE_URL: "postgresql://app:app@database:5432/lavka?serverVersion=16&charset=utf8"
    REDIS_URL: "redis://redis:6379"
    MESSENGER_TRANSPORT_DSN: "redis://redis:6379/messages"
  depends_on:
    database:
      condition: service_healthy
    redis:
      condition: service_started

services:
  app:
    <<: *app
    ports:
      - "8080:80"

  # Воркер черг і планувальника. Поки Messenger не налаштований, запускається окремо:
  #   docker compose --profile worker up -d
  # Коли Messenger буде готовий, рядок `profiles` можна видалити.
  worker:
    <<: *app
    profiles: ["worker"]
    command: php bin/console messenger:consume async scheduler_default -vv
    restart: unless-stopped

  database:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: lavka
      POSTGRES_USER: app
      POSTGRES_PASSWORD: app
    ports:
      - "5433:5432"
    volumes:
      - db_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U app -d lavka"]
      interval: 5s
      timeout: 3s
      retries: 10

  redis:
    image: redis:7-alpine

volumes:
  db_data:
  var_data:
'@

Write-FileIfMissing 'docker/php/Dockerfile' @'
FROM dunglas/frankenphp:1-php8.3

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    @composer \
    apcu \
    intl \
    opcache \
    pdo_pgsql \
    redis \
    zip

# dev-конфігурація PHP (для production буде окремий stage)
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/Caddyfile /etc/caddy/Caddyfile
'@

Write-FileIfMissing 'docker/php/Caddyfile' @'
{
	frankenphp
	auto_https off
}

:80 {
	root * /app/public
	encode zstd gzip
	php_server
}
'@

Write-FileIfMissing '.dockerignore' @'
.git
vendor
var
node_modules
'@

Write-FileIfMissing '.gitattributes' @'
* text=auto eol=lf
*.ps1 text eol=crlf
*.bat text eol=crlf
'@

Write-FileIfMissing '.gitignore' @'
/vendor/
/var/
/public/bundles/
/config/jwt/*.pem
.env.local
.env.local.php
.env.*.local
.phpunit.cache/
.php-cs-fixer.cache
.idea/
.vscode/
.DS_Store
node_modules/
'@

# ---------------------------------------------------------------------------
Step '3/6 Збірка образу та Symfony skeleton (може тривати кілька хвилин)'
# ---------------------------------------------------------------------------
Run 'docker compose build' { docker compose build }

if (Test-Path 'composer.json') {
    Write-Host '  composer.json уже існує, створення skeleton пропущено'
}
else {
    $skel = "composer create-project symfony/skeleton:'7.4.*' /tmp/skel --no-install --no-interaction && cp -an /tmp/skel/. /app/ && composer install --no-interaction"
    Run 'створення Symfony skeleton' { docker compose run --rm --no-deps app sh -c $skel }
}

# Дозволяємо contrib-рецепти (lexik, nelmio) і вимикаємо Docker-рецепти Flex,
# щоб Flex не переписував наш compose.yaml
$composerPath = Join-Path $root 'composer.json'
$c = [System.IO.File]::ReadAllText($composerPath)
if ($c -notmatch '"docker"') {
    if ($c.Contains('"allow-contrib": false,')) {
        $c = $c.Replace('"allow-contrib": false,', '"allow-contrib": true,' + "`n" + '            "docker": false,')
        [System.IO.File]::WriteAllText($composerPath, $c, $utf8)
        Write-Host '  composer.json: allow-contrib=true, docker=false'
    }
    else {
        Write-Host '  УВАГА: не знайшов "allow-contrib" у composer.json. Додай вручну в extra.symfony: "allow-contrib": true, "docker": false'
    }
}

# ---------------------------------------------------------------------------
Step '4/6 Встановлення пакетів (на Windows-диску це найповільніший крок)'
# ---------------------------------------------------------------------------
$c = [System.IO.File]::ReadAllText($composerPath)
if ($c -match 'symfony/test-pack') {
    Write-Host '  пакети вже встановлені, пропущено'
}
else {
    $pkgs = @(
        'api-platform/symfony', 'api-platform/doctrine-orm', 'symfony/orm-pack', 'symfony/uid', 'symfony/validator',
        'symfony/workflow', 'symfony/messenger', 'symfony/redis-messenger', 'symfony/scheduler',
        'symfony/security-bundle', 'lexik/jwt-authentication-bundle', 'nelmio/cors-bundle',
        'symfony/monolog-bundle', 'symfony/rate-limiter', 'symfony/lock',
        'brick/money', 'opis/json-schema'
    )
    Run 'composer require' { docker compose run --rm --no-deps -e SYMFONY_DOCKER=0 app composer require --no-interaction @pkgs }

    $devPkgs = @(
        'symfony/test-pack', 'symfony/maker-bundle', 'phpstan/phpstan',
        'friendsofphp/php-cs-fixer', 'doctrine/doctrine-fixtures-bundle'
    )
    Run 'composer require --dev' { docker compose run --rm --no-deps -e SYMFONY_DOCKER=0 app composer require --dev --no-interaction @devPkgs }
}

# ---------------------------------------------------------------------------
Step '5/6 Структура проєкту та конфігурація'
# ---------------------------------------------------------------------------
$contexts = 'Catalog', 'Pricing', 'Inventory', 'Cart', 'Order', 'Payment', 'Identity'
$layers   = 'Domain', 'Application', 'Infrastructure', 'Api'

$dirs = @()
foreach ($ctx in $contexts) {
    foreach ($l in $layers) { $dirs += "src/$ctx/$l" }
}
foreach ($l in 'Domain', 'Application', 'Infrastructure') { $dirs += "src/Shared/$l" }
$dirs += 'tests/Unit', 'tests/Integration', 'tests/Functional'
$dirs += 'docs/adr', 'docs/diagrams'

foreach ($d in $dirs) {
    New-Item -ItemType Directory -Path $d -Force | Out-Null
    $keep = Join-Path $d '.gitkeep'
    if (-not (Test-Path $keep)) { New-Item -ItemType File -Path $keep | Out-Null }
}
Write-Host "  каталогів у структурі: $($dirs.Count)"

Write-FileIfMissing 'phpstan.neon' @'
parameters:
    level: 8
    paths:
        - src
        - tests
'@

Write-FileIfMissing 'docs/adr/0000-template.md' @'
# ADR-0000: Назва рішення

- **Статус:** запропоновано | прийнято | замінено
- **Дата:** РРРР-ММ-ДД

## Контекст

Яку проблему вирішуємо і які є обмеження.

## Рішення

Що саме вирішили зробити.

## Альтернативи

Що розглядали і чому відхилили.

## Наслідки

Плюси, мінуси, що це змінює в проєкті.
'@

# services.yaml: виключити Domain з DI-контейнера
$svcPath = Join-Path $root 'config/services.yaml'
if (Test-Path $svcPath) {
    $text = [System.IO.File]::ReadAllText($svcPath)
    if ($text -match 'src/\*/Domain') {
        Write-Host '  services.yaml: уже налаштовано'
    }
    else {
        $pattern = "(?m)^([ \t]*)- '\.\./src/Kernel\.php'[ \t]*\r?$"
        if ($text -match $pattern) {
            $evaluator = [System.Text.RegularExpressions.MatchEvaluator] {
                param($m)
                $m.Value + "`n" + $m.Groups[1].Value + "- '../src/*/Domain/'"
            }
            $text = [regex]::Replace($text, $pattern, $evaluator)
            [System.IO.File]::WriteAllText($svcPath, $text, $utf8)
            Write-Host "  services.yaml: додано exclude '../src/*/Domain/'"
        }
        else {
            Write-Host "  УВАГА: у services.yaml не знайшов рядок з Kernel.php. Додай вручну в exclude: - '../src/*/Domain/'"
        }
    }
}
else {
    Write-Host '  УВАГА: config/services.yaml не знайдено'
}

# composer.json: скрипти test / stan / cs
$c = [System.IO.File]::ReadAllText($composerPath)
if ($c -match '"stan"') {
    Write-Host '  composer.json: скрипти вже додані'
}
elseif ($c.Contains('"scripts": {')) {
    $add = '"scripts": {' + "`n" +
           '        "test": "phpunit",' + "`n" +
           '        "stan": "phpstan analyse",' + "`n" +
           '        "cs": "php-cs-fixer fix --dry-run --diff",'
    $c = $c.Replace('"scripts": {', $add)
    [System.IO.File]::WriteAllText($composerPath, $c, $utf8)
    Write-Host '  composer.json: додано скрипти test, stan, cs'
}
else {
    Write-Host '  УВАГА: у composer.json не знайшов блок "scripts". Додай test/stan/cs вручну.'
}

# ---------------------------------------------------------------------------
Step '6/6 JWT-ключі та запуск контейнерів'
# ---------------------------------------------------------------------------
Run 'генерація JWT-ключів' { docker compose run --rm --no-deps app bin/console lexik:jwt:generate-keypair --skip-if-exists }
Run 'docker compose up' { docker compose up -d }

Write-Host '  чекаю, поки застосунок відповість...'
$ok = $false
for ($i = 0; $i -lt 20; $i++) {
    try {
        $r = Invoke-WebRequest 'http://localhost:8080/api/docs' -UseBasicParsing -TimeoutSec 5
        if ($r.StatusCode -eq 200) { $ok = $true; break }
    }
    catch { Start-Sleep -Seconds 3 }
}

Write-Host ""
if ($ok) {
    Write-Host 'Готово! Swagger UI: http://localhost:8080/api/docs' -ForegroundColor Green
}
else {
    Write-Host 'Контейнери запущені, але /api/docs ще не відповів. Подивись логи: docker compose logs app' -ForegroundColor Yellow
}
Write-Host ''
Write-Host 'Далі: git add . ; git commit -m "chore: додано Symfony skeleton, Docker-оточення і структуру контекстів"'

}
catch {
    Write-Host ""
    Write-Host "ПОМИЛКА: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Скинь мені вивід вище, розберемося. Скрипт можна запустити повторно: виконані кроки пропускаються.'
    exit 1
}