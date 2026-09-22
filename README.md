# Lavka Engine

Headless e-commerce рушій на Symfony. Надає API для керування каталогом, цінами, складськими залишками, кошиком і замовленнями. Головна особливість: **Promotion Engine з explain-режимом**, який не лише рахує знижки за декларативними JSON-правилами, а й пояснює, чому ціна саме така (які правила застосовано, які пропущено та чому).

## Зміст

- [Проблема, яку вирішує проєкт](#проблема-яку-вирішує-проєкт)
- [Основні можливості](#основні-можливості)
- [Технологічний стек](#технологічний-стек)
- [Системні вимоги](#системні-вимоги)
- [Встановлення](#встановлення)
- [Змінні середовища](#змінні-середовища)
- [Налаштування бази даних](#налаштування-бази-даних)
- [Запуск застосунку](#запуск-застосунку)
- [Запуск тестів](#запуск-тестів)
- [Структура проєкту](#структура-проєкту)
- [Опис API](#опис-api)
- [Приклад типового сценарію використання](#приклад-типового-сценарію-використання)
- [Розгортання](#розгортання)

## Проблема, яку вирішує проєкт

Монолітні e-commerce платформи мають типові болі: EAV-модель атрибутів ускладнює схему БД і запити, знижки та ціноутворення непрозорі («чому цей товар коштує саме стільки?»), розширення через plugins/interceptors ускладнює дебаг порядку виконання, а залишки без додаткових зусиль не захищені від oversell при одночасних замовленнях. Lavka Engine надає легке ядро з чистою доменною моделлю: атрибути у JSONB замість EAV, прозорий рушій знижок з поясненням, атомарне резервування залишків та явні точки розширення замість «магії».

## Основні можливості

- реєстрація та автентифікація користувачів (JWT);
- каталог: категорії, товари, варіанти (SKU), атрибути у JSONB з валідацією за схемою категорії;
- прайс-листи (за валютою та групою покупців) і tier-ціни (знижка за кількість);
- Promotion Engine: JSON-правила, пріоритети, `stop_processing`, купони, explain-відповідь;
- склад: залишки, атомарне резервування без oversell, TTL резерву, автоматичне звільнення прострочених резервів;
- кошик з pipeline-розрахунком (позиції → знижки → доставка → підсумок), гостьовий режим за токеном кошика;
- оформлення замовлення з ідемпотентністю (`Idempotency-Key`) і знімком цін;
- життєвий цикл замовлення на Symfony Workflow з журналом переходів;
- платіжний адаптер та ідемпотентна обробка вебхуків;
- ролі доступу (guest / customer / manager / admin) через Symfony Voters, аудит-лог дій персоналу;
- REST API та GraphQL, OpenAPI-документація.

## Технологічний стек

| Шар | Технологія |
|---|---|
| Backend | PHP 8.3, Symfony 7.4 LTS |
| API | API Platform (REST, GraphQL, OpenAPI) |
| ORM / міграції | Doctrine ORM, Doctrine Migrations |
| База даних | PostgreSQL 16 (JSONB для атрибутів і правил) |
| Кеш / блокування | Redis |
| Черги / планувальник | Symfony Messenger, Symfony Scheduler |
| Стани замовлення | Symfony Workflow |
| Автентифікація | JWT (lexik/jwt-authentication-bundle), Symfony Security (Voters) |
| Гроші | brick/money (суми у мінімальних одиницях) |
| Тестування | PHPUnit, Infection (mutation testing) |
| Якість коду | PHPStan (level 8+), PHP-CS-Fixer, Deptrac |
| Інфраструктура | Docker, Docker Compose, GitHub Actions |

Обґрунтування вибору технологій наведено в технічному завданні проєкту ([LavkaAC.md](LavkaAC.md), розділ AC-02) та в ADR-документах

## Системні вимоги

- Docker та Docker Compose (рекомендований спосіб запуску);
- для запуску без Docker: PHP 8.3+ (розширення `intl`, `pdo_pgsql`, `redis`), Composer 2, PostgreSQL 16+, Redis 7+.

## Встановлення

```bash
# 1. Клонування репозиторію
git clone https://github.com/Isoloneya/lavka-engine.git
cd lavka-engine

# 2. Копіювання шаблону змінних середовища
cp .env .env.local

# 3. Запуск контейнерів
docker compose up -d --build

# 4. Встановлення залежностей
docker compose exec app composer install

# 5. Генерація ключів для JWT
docker compose exec app bin/console lexik:jwt:generate-keypair
```

## Змінні середовища

У файлі `.env` зберігаються значення за замовчуванням без секретів. Реальні значення задаються у `.env.local` (не потрапляє до репозиторію; у CI/CD та на хостингу задається окремо або через Symfony Secrets):

| Змінна | Опис | Приклад |
|---|---|---|
| `APP_ENV` | режим застосунку | `dev` |
| `APP_SECRET` | секрет застосунку | `change-me` |
| `DATABASE_URL` | рядок підключення до PostgreSQL | `postgresql://app:app@database:5432/lavka?serverVersion=16&charset=utf8` |
| `REDIS_URL` | адреса Redis | `redis://redis:6379` |
| `MESSENGER_TRANSPORT_DSN` | транспорт черг | `redis://redis:6379/messages` |
| `JWT_PASSPHRASE` | пароль до приватного ключа JWT | `change-me` |
| `JWT_TTL` | термін дії токена доступу (у секундах) | `3600` |
| `CORS_ALLOW_ORIGIN` | дозволені origin для CORS | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` |
| `CART_RESERVATION_TTL` | час життя резерву залишків (у секундах) | `900` |
| `PAYMENT_WEBHOOK_SECRET` | секрет для перевірки підпису вебхуків оплати | `change-me` |

## Налаштування бази даних

```bash
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

Команда застосовує всі міграції та створює структуру таблиць відповідно до моделей даних (User, Category, Product, ProductVariant, PriceList, Price, PromotionRule, StockItem, StockReservation, Cart, Order, Payment та ін.).

Для завантаження демо-даних (каталог, прайс-листи, правила знижок, залишки):

```bash
docker compose exec app bin/console doctrine:fixtures:load --no-interaction
```

## Запуск застосунку

```bash
docker compose up -d
```

Сервіс `worker` у складі Compose автоматично запускає обробку черг і планувальника (`messenger:consume async scheduler_default`). За потреби запустити воркер вручну:

```bash
docker compose exec app bin/console messenger:consume async scheduler_default -vv
```

- API: http://localhost:8080
- Інтерактивна документація REST (Swagger UI): http://localhost:8080/api/docs
- GraphQL: http://localhost:8080/api/graphql
- Перевірка стану: http://localhost:8080/health

## Запуск тестів

```bash
docker compose exec app composer test        # PHPUnit: unit, integration, functional
docker compose exec app composer stan        # PHPStan
docker compose exec app composer cs          # PHP-CS-Fixer
docker compose exec app composer deptrac     # контроль меж контекстів і шарів
docker compose exec app composer infection   # mutation testing (Pricing Engine)
```

## Структура проєкту

```
lavka-engine/
├── bin/console
├── config/                   # конфігурація Symfony, пакетів, маршрутів
│   └── jwt/                  # ключі JWT (не в репозиторії)
├── migrations/               # міграції Doctrine
├── src/
│   ├── Shared/               # Money, шини, обробка помилок, аудит
│   ├── Catalog/              # категорії, товари, варіанти
│   ├── Pricing/              # прайс-листи, Promotion Engine, explain
│   ├── Inventory/            # залишки та резерви
│   ├── Cart/                 # кошик і його розрахунок
│   ├── Order/                # замовлення, Workflow, історія переходів
│   ├── Payment/              # платіжний інтерфейс, вебхуки
│   └── Identity/             # користувачі, групи покупців, JWT, ролі
│       └── (кожен контекст: Domain / Application / Infrastructure / Api)
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Functional/
├── docker/
├── docs/
│   ├── Lavka_TZ.md           # технічне завдання проєкту
│   ├── adr/                  # рішення щодо архітектури
│   └── diagrams/             # C4, state machine, ER-діаграма
├── compose.yaml
├── composer.json
├── phpstan.neon
├── deptrac.yaml
└── .php-cs-fixer.dist.php
```

## Опис API

Повний перелік ендпоінтів доступний у Swagger-документації (`/api/docs`) після запуску застосунку. Версіонування через префікс `/api/v1`. Основні групи:

| Група | Базовий шлях | Призначення |
|---|---|---|
| Auth | `/api/v1/auth`, `/api/v1/me` | реєстрація, вхід, профіль |
| Catalog | `/api/v1/categories`, `/api/v1/products` | перегляд каталогу |
| Admin: Catalog | `/api/v1/admin/categories`, `/products`, `/variants` | керування каталогом |
| Admin: Pricing | `/api/v1/admin/price-lists`, `/promotion-rules` | прайс-листи та правила знижок |
| Admin: Inventory | `/api/v1/admin/stock` | коригування залишків |
| Cart | `/api/v1/carts` | кошик, позиції, купон |
| Checkout | `/api/v1/checkout` | оформлення замовлення |
| Orders | `/api/v1/orders`, `/api/v1/admin/orders` | замовлення та їхні переходи |
| Payments | `/api/v1/payments/webhook/{provider}` | вебхуки платіжних провайдерів |

Помилки повертаються у форматі RFC 9457 (`application/problem+json`) із машинозчитуваним полем `code`. Формат помилок і коди відповіді детально описані в технічному завданні ([docs/Lavka_TZ.md](docs/Lavka_TZ.md), розділи AC-06 та AC-13).

## Приклад типового сценарію використання

1. Менеджер входить через `POST /api/v1/auth/login` і отримує JWT.
2. Менеджер створює категорію, товар і варіант: `POST /api/v1/admin/categories`, `POST /api/v1/admin/products`, `POST /api/v1/admin/products/{id}/variants`.
3. Менеджер задає ціну у прайс-листі (`PUT /api/v1/admin/price-lists/{id}/prices`) і залишок (`PATCH /api/v1/admin/stock/{variant_id}`).
4. Менеджер створює правило знижки: `POST /api/v1/admin/promotion-rules` (перед збереженням ефект можна перевірити через `POST /api/v1/admin/promotion-rules/preview`).
5. Покупець (навіть без реєстрації) створює кошик і додає товар: `POST /api/v1/carts`, `POST /api/v1/carts/{token}/items`. У відповіді `GET /api/v1/carts/{token}` є блок `pricing` з розрахунком і поясненням знижок.
6. Покупець оформлює замовлення: `POST /api/v1/checkout` із заголовком `Idempotency-Key`. Система перераховує кошик на сервері, резервує залишки й створює замовлення зі статусом `pending_payment`.
7. Платіжний провайдер надсилає вебхук `POST /api/v1/payments/webhook/{provider}`: замовлення переходить у `paid`.
8. Менеджер веде замовлення за станами: `POST /api/v1/admin/orders/{number}/transitions/start_processing`, далі `ship`, `complete`. Історія переходів доступна через `GET /api/v1/orders/{number}/history`.

Приклад запитів:

```bash
# Створення кошика та додавання позиції
curl -X POST http://localhost:8080/api/v1/carts \
  -H "Content-Type: application/json" \
  -d '{"currency": "UAH"}'

curl -X POST http://localhost:8080/api/v1/carts/<CART_TOKEN>/items \
  -H "Content-Type: application/json" \
  -d '{"sku": "SHOE-42-BLK", "quantity": 2}'

# Оформлення замовлення
curl -X POST http://localhost:8080/api/v1/checkout \
  -H "Content-Type: application/json" \
  -H "X-Cart-Token: <CART_TOKEN>" \
  -H "Idempotency-Key: 7f3c2b1e-0d5a-4a55-9c0f-2f1c3a6e9b10" \
  -d '{"email": "buyer@example.com", "shipping_address": {"country": "UA", "city": "Київ", "address": "вул. Хрещатик, 1", "recipient": "Іван Петренко", "phone": "+380501234567"}}'
```

Приклад фрагмента відповіді кошика з поясненням знижок (суми у копійках):

```json
{
  "pricing": {
    "currency": "UAH",
    "subtotal": 250000,
    "discount": 25000,
    "total": 225000,
    "applied_rules": [
      { "name": "-10% на взуття від 2000 грн", "scope": "item", "discount": 25000 }
    ],
    "skipped_rules": [
      { "name": "Купон SUMMER", "reason": "coupon_not_provided" }
    ]
  }
}
```

## Розгортання

Застосунок збирається як Docker-образ (multi-stage build, `composer install --no-dev --optimize-autoloader`, `APP_ENV=prod`, `APP_DEBUG=0`) і розгортається на VPS із Docker Compose або на платформі з підтримкою контейнерів (наприклад, Fly.io, Railway). Окремо запускаються веб-процес і воркер:

```bash
# воркер черг і планувальника
bin/console messenger:consume async scheduler_default --time-limit=3600
```

Перед першим запуском і при кожному оновленні на цільовому середовищі необхідно застосувати міграції:

```bash
bin/console doctrine:migrations:migrate --no-interaction
```

Адреса бази даних передається через `DATABASE_URL`, секрети (`APP_SECRET`, `JWT_PASSPHRASE`, `PAYMENT_WEBHOOK_SECRET`) задаються через змінні середовища платформи або Symfony Secrets. Демо-дані (fixtures) у production не завантажуються. GitHub Actions виконує на кожен push перевірку стилю, PHPStan, Deptrac і PHPUnit.
