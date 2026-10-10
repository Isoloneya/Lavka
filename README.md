# Lavka Engine

Headless e-commerce рушій на Symfony. Надає API для керування каталогом, цінами, складськими залишками, кошиком і замовленнями. Головна особливість: **Promotion Engine з explain-режимом**, який не лише рахує знижки за декларативними JSON-правилами, а й пояснює, чому ціна саме така (які правила застосовано, які пропущено та чому).

Портфоліо-версія з повним сценарієм покупки та тестовими інтеграціями: оплата симулюється персоналом, методи доставки `pickup` і `stub_delivery` мають нульову вартість. Реальні платежі, вебхуки провайдерів, email і SMS не підключені.

Twig-вітрина доступна на `/`: головна, каталог `/shop` із пошуком, категоріями, сортуванням і пагінацією та товар `/shop/{slug}` із базовими цінами варіантів. Гостьовий кошик `/cart` підтримує кількість, видалення та купони; оформлення `/checkout` створює замовлення й резервує залишки. Сторінка `/order/{number}` доступна власнику після входу або в сесії гостьового покупця, що оформив замовлення. Форми захищені CSRF-токеном, повторне оформлення використовує той самий ключ ідемпотентності. Кошик оновлюється без перезавантаження; без JavaScript залишаються звичайні серверні форми. Реєстрація `/register`, вхід `/login` і кабінет `/account` працюють через браузерну сесію окремо від JWT API. Кабінет показує замовлення, оформлені після входу; гостьовий кошик зберігається під час входу, групові ціни застосовуються автоматично. Вихід очищає сесію. Відновлення пароля та підтвердження email поки не реалізовані.

Адмінка `/admin` доступна менеджерам і адміністраторам після входу через `/login`. Вона містить огляд, категорії, товари, SKU, базові прайс-листи UAH, ціни, склади, коригування залишків та обробку замовлень із тестовою оплатою. Архівування товарів доступне лише адміністратору. Зміни та аудит зберігаються в одній транзакції; CSRF-токени в аудит не потрапляють. Створення персоналу: `docker compose exec app php bin/console app:create-staff manager@example.com ROLE_MANAGER` (пароль вводиться приховано). Акції `/admin/promotions` підтримують купони, період дії за київським часом, JSON-умови й дії та перевірку чинних правил за SKU. Адміністратору доступні користувачі `/admin/users`, створення груп `/admin/groups` і журнал `/admin/audit`. Групу можна призначити користувачу та прайс-листу; відповідна ціна застосовується у вітрині й кошику після входу.

Фото товарів задаються атрибутами `image` та `image_hover` зі шляхами `/media/...`; файли розміщуються в `public/media`. Демо-каталог містить 113 товарів і 226 згенерованих WebP-зображень: річ окремо та на вигаданій моделі при наведенні. Без фото відображається нейтральний блок. Головна використовує власний згенерований постер і коротку відеоанімацію `public/storefront/media/campaign.webm`; файл `campaign.mp4`, якщо його додати, має пріоритет. Без відео показується постер. Автовідтворення вимикається за налаштуванням зменшення руху.

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
- платіжний адаптер-заглушка з явною ознакою `test_mode`;
- ролі доступу (guest / customer / manager / admin) через Symfony Voters, аудит-лог дій персоналу;
- REST API та GraphQL, OpenAPI-документація.

## Технологічний стек

| Шар | Технологія |
|---|---|
| Backend | PHP 8.3, Symfony 7.4 LTS |
| API | REST-контролери, API Platform (OpenAPI), webonyx/graphql-php (читання) |
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

Обґрунтування вибору технологій наведено в [LavkaAC.md](LavkaAC.md), розділ AC-02, та в ADR-документах (`docs/adr/`).

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
| `CORS_ALLOW_ORIGIN` | дозволені origin для CORS | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` |
| `CART_RESERVATION_TTL` | час життя резерву залишків (у секундах) | `900` |
| `MESSENGER_CONSUMER_NAME` | унікальне ім'я споживача черги | `lavka-worker-1` |

## Налаштування бази даних

```bash
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

Команда застосовує всі міграції та створює структуру таблиць відповідно до моделей даних (User, Category, Product, ProductVariant, PriceList, Price, PromotionRule, StockItem, StockReservation, Cart, Order, Payment та ін.).

Для завантаження демо-даних (каталог, прайс-листи, правила знижок, залишки):

```bash
docker compose exec app php bin/console app:seed-demo
```

Каталог одягу, взуття, сумок та аксесуарів: `docker compose exec app php bin/console app:seed-fashion --no-debug`. Команда додає 113 вигаданих товарів і 372 варіанти з демонстраційними цінами та залишками. Для заміни попередніх фото й описів додайте `--refresh-media`: залишки та ціни зберігаються, товари зі старими запозиченими фото приховуються з вітрини. У картці спочатку видно річ, при наведенні — її на синтетичній моделі. 226 оптимізованих WebP зберігаються в `public/media/generated-fashion`, дані — у `data/fashion-catalog.json`, промпти генерацій — у `data/fashion-generation-plan.json`. Зображення та банер створені вбудованим інструментом генерації зображень; фотографії сторонніх магазинів не використовуються.

## Запуск застосунку

```bash
docker compose --profile worker up -d
```

Профіль `worker` запускає обробку черг і планувальника (`messenger:consume async scheduler_default`), зокрема звільнення прострочених резервів. Для створення адміністратора:

```bash
docker compose exec app php bin/console app:create-staff admin@example.com ROLE_ADMIN
```

Команда запитає пароль. За потреби запустити воркер вручну:

```bash
docker compose exec app bin/console messenger:consume async scheduler_default -vv
```

- API: http://localhost:8080
- Інтерактивна документація REST (Swagger UI): http://localhost:8080/api/docs
- GraphQL: `POST http://localhost:8080/api/graphql`, схема — `config/graphql.graphql`
- Перевірка стану: http://localhost:8080/health

## Запуск тестів

Перед першим запуском підготуйте тестову базу:

```bash
docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

```bash
docker compose exec app composer test
docker compose exec app composer stan
docker compose exec app composer cs
docker compose exec app composer deptrac
docker compose exec app composer infection
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
│   ├── Payment/              # платіжний інтерфейс і тестовий адаптер
│   └── Identity/             # користувачі, групи покупців, JWT, ролі
│       └── (кожен контекст: Domain / Application / Infrastructure / Api)
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Functional/
├── docker/
├── docs/
│   ├── adr/                  # рішення щодо архітектури
│   └── diagrams/             # потік покупки та стани замовлення
├── LavkaAC.md
├── compose.yaml
├── compose.prod.yaml
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
| Тестова оплата | `/api/v1/admin/orders/{number}/transitions/pay` | симуляція оплати для manager/admin |

Помилки REST повертаються у форматі RFC 9457 (`application/problem+json`) із машинозчитуваним полем `code`. GraphQL повертає помилки в `errors`. Postman-колекція: [docs/Lavka.postman_collection.json](docs/Lavka.postman_collection.json).

## Приклад типового сценарію використання

1. Менеджер входить через `POST /api/v1/auth/login` і отримує JWT.
2. Менеджер створює категорію, товар і варіант: `POST /api/v1/admin/categories`, `POST /api/v1/admin/products`, `POST /api/v1/admin/products/{id}/variants`.
3. Менеджер задає ціну у прайс-листі (`PUT /api/v1/admin/price-lists/{id}/prices`) і залишок (`PATCH /api/v1/admin/stock/{variant_id}`).
4. Менеджер створює правило знижки: `POST /api/v1/admin/promotion-rules` (перед збереженням ефект можна перевірити через `POST /api/v1/admin/promotion-rules/preview`).
5. Покупець (навіть без реєстрації) створює кошик і додає товар: `POST /api/v1/carts`, `POST /api/v1/carts/{token}/items`. У відповіді `GET /api/v1/carts/{token}` є блок `pricing` з розрахунком і поясненням знижок.
6. Покупець оформлює замовлення: `POST /api/v1/checkout` із заголовками `X-Cart-Token` та `Idempotency-Key`. Система перераховує кошик на сервері, резервує залишки й створює замовлення зі статусом `pending_payment`.
7. Менеджер симулює оплату через `POST /api/v1/admin/orders/{number}/transitions/pay`: замовлення переходить у `paid`, платіж отримує статус `simulated_paid`. Реальні кошти не списуються.
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
  -d '{"email": "buyer@example.com", "shipping_method": "pickup", "shipping_address": {"country": "UA", "city": "Київ", "address": "вул. Хрещатик, 1", "recipient": "Іван Петренко", "phone": "+380501234567"}}'
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

Для тестового production використовуйте `compose.prod.yaml`: образ без dev-залежностей, користувач `www-data`, `APP_DEBUG=0`, Caddy з автоматичним HTTPS та окремий worker. PostgreSQL і Redis не публікуються назовні.

У `.env.prod.local` задайте `SERVER_NAME`, `APP_SECRET`, `POSTGRES_PASSWORD`, `DATABASE_URL`, `JWT_PASSPHRASE`, `JWT_PRIVATE_FILE`, `JWT_PUBLIC_FILE` та `CORS_ALLOW_ORIGIN`. Пароль у `DATABASE_URL` має збігатися з `POSTGRES_PASSWORD`; URL повинен містити `serverVersion=16.0.0`. Шляхи JWT вказують на пару ключів поза образом; приватний ключ має читатися UID 33 контейнера. Домен має вказувати на сервер із відкритими портами 80 і 443.

Перший запуск:

```bash
docker compose --env-file .env.prod.local -f compose.prod.yaml config --quiet
docker compose --env-file .env.prod.local -f compose.prod.yaml build
docker compose --env-file .env.prod.local -f compose.prod.yaml up -d database redis
docker compose --env-file .env.prod.local -f compose.prod.yaml run --rm app php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.prod.local -f compose.prod.yaml run --rm app php bin/console cache:clear
docker compose --env-file .env.prod.local -f compose.prod.yaml run --rm app php bin/console cache:warmup
docker compose --env-file .env.prod.local -f compose.prod.yaml up -d app worker
```

Для наповнення портфоліо-демо й створення адміністратора після запуску:

```bash
docker compose --env-file .env.prod.local -f compose.prod.yaml exec app php bin/console app:seed-fashion --no-debug
docker compose --env-file .env.prod.local -f compose.prod.yaml exec app php bin/console app:create-staff admin@example.com ROLE_ADMIN
```

Пароль адміністратора вводиться приховано. Повторне наповнення пропускає вже створені товари й зберігає зміни цін та залишків. Перевірте `/health` і `/api/docs`. Перед оновленням зробіть резервну копію БД; після збірки зупиніть app і worker, застосуйте міграції, очистьте та прогрійте кеш, потім запустіть сервіси. GitHub Actions виконує PHPUnit, PHPStan, перевірку стилю, Deptrac, Infection, production-збірку та smoke-тест. Розгортання на сервер виконується окремо.
