# Технічне завдання проєкту Lavka Engine

**Технологічний стек:** PHP 8.3, Symfony 7.4 LTS, API Platform, Doctrine ORM, PostgreSQL, Redis, Symfony Messenger / Workflow, Docker

---

## AC-01. Опис та постановка задачі

### Назва проєкту

Lavka Engine

### Предметна область

Headless e-commerce платформа: API-first рушій каталогу, цін, складу, кошика й замовлень без вбудованого storefront. Клієнтом може бути будь-який фронтенд (Next.js, мобільний застосунок, зовнішня система).

### Проблема

Монолітні e-commerce платформи (зокрема Magento) мають типові болі:

- EAV-модель атрибутів ускладнює запити та схему БД;
- ціноутворення й знижки непрозорі: неможливо швидко відповісти на питання «чому товар коштує саме стільки»;
- розширення через plugins/interceptors ускладнює дебаг порядку виконання;
- залишки не захищені від oversell при одночасних замовленнях без додаткових зусиль;
- ядро перевантажене функціями, які проєкту не потрібні.

Розробникам storefront і малому бізнесу потрібне легке ядро з чистою доменною моделлю, чіткими API-контрактами та прозорим рушієм знижок.

### Цільова аудиторія

- розробники storefront-ів і мобільних застосунків, які інтегруються через API;
- малі та середні інтернет-магазини, яким не потрібна «важка» платформа;
- менеджери магазину, що керують каталогом, цінами й замовленнями через адмін-API;
- інтегратори (ERP, служби доставки, платіжні системи).

### Призначення системи

Система надає API для керування каталогом товарів, розрахунку цін із застосуванням правил знижок, обліку складських залишків із резервуванням, ведення кошика та оформлення замовлень із контрольованим життєвим циклом.

### Ключова відмінність (основна фішка)

**Pricing / Promotion Engine з explain-режимом.** Правила знижок описуються декларативно (JSON), мають пріоритети та прапорець взаємовиключення. Кожен розрахунок ціни повертає пояснення: які правила застосовано, які пропущено й чому.

### Основні сценарії використання

1. Реєстрація та автентифікація покупця; гостьовий режим із токеном кошика.
2. Створення адміністратором категорій, товарів і варіантів (SKU) з атрибутами.
3. Налаштування прайс-листів (за валютою та групою покупців) і tier-цін (знижка за кількість).
4. Створення менеджером правил знижок і купонів.
5. Перегляд каталогу покупцем: пошук, фільтрація, сортування, актуальна ціна з урахуванням правил.
6. Формування кошика: додавання, зміна кількості, видалення позицій, застосування купона.
7. Автоматичний перерахунок кошика системою через pricing pipeline; отримання explain-відповіді.
8. Оформлення замовлення (checkout): фіксація цін, резервування залишків, створення замовлення зі статусом `pending_payment`.
9. Оплата замовлення через платіжний адаптер, обробка вебхука (ідемпотентно), перехід замовлення в `paid`.
10. Обробка замовлення менеджером за станами (`processing` → `shipped` → `completed`) або скасування із поверненням резерву.
11. Автоматичне зняття прострочених резервів фоновою задачею.
12. Перегляд історії змін статусів замовлення та аудит-логу дій персоналу.

### Перелік ключових функцій

- реєстрація та автентифікація (JWT);
- CRUD каталогу: категорії, товари, варіанти, атрибути у JSONB з валідацією за схемою категорії;
- прайс-листи та tier-ціни, зберігання грошей у мінімальних одиницях (копійки) із валютою;
- Promotion Engine: JSON-правила, пріоритети, `stop_processing`, купони, explain;
- склад: залишки, атомарне резервування, TTL резерву, звільнення резерву;
- кошик як агрегат із pipeline-розрахунком (позиції → знижки → доставка → підсумок);
- оформлення замовлення з ідемпотентністю (`Idempotency-Key`);
- state machine замовлення (Symfony Workflow) з журналом переходів;
- платіжний адаптер (інтерфейс + реалізація для тестового середовища) та вебхуки;
- рольова модель доступу (guest / customer / manager / admin) через Symfony Voters;
- аудит-лог змін для персоналу;
- REST API + GraphQL (читання каталогу й кошика), OpenAPI-документація;
- обробка помилкових сценаріїв (недостатньо залишку, невалідний купон, недопустимий перехід статусу, порушення прав).

### Обсяг MVP та поза обсягом

| У межах MVP | Поза межами MVP (можливі вдосконалення) |
|---|---|
| каталог, прайс-листи, Promotion Engine | Event Sourcing для замовлень і складу |
| склад з резервами, кошик, замовлення | мультитенантність |
| Workflow замовлення, платіжний адаптер | інтеграції з Новою поштою, LiqPay, Monobank, Checkbox (ПРРО) |
| JWT, ролі, аудит-лог | повнотекстовий пошук на OpenSearch |
| REST + GraphQL, OpenAPI | повернення коштів, часткові відвантаження |
| Docker, CI, тести | демо-storefront, адмін-панель, OpenTelemetry, навантажувальні тести k6 |

---

## AC-02. Архітектура та структура системи

### Загальний підхід

Застосовується **модульний моноліт** з розділенням на bounded contexts. Усередині кожного контексту дотримується шарова структура Domain / Application / Infrastructure / Api. Взаємодія із зовнішніми клієнтами відбувається через REST і GraphQL.

### Компоненти системи

**Client.** Будь-який зовнішній споживач API: storefront, мобільний застосунок, адмін-інтерфейс, ERP. Власного UI система не містить (демо-вітрина — опційно, див. AC-03).

**Backend (Symfony).** Складається з чотирьох шарів у кожному контексті:

| Шар | Відповідальність |
|---|---|
| Api | приймання HTTP-запитів (API Platform resources, контролери вебхуків), десеріалізація у DTO, валідація, виклик команд/запитів, серіалізація відповіді |
| Application | сценарії використання: command/query handlers, транзакції, оркестрація доменних об'єктів, перевірка прав через Voters |
| Domain | сутності, value objects (`Money`, `Quantity`, `Sku`), доменні сервіси (Pricing Engine), доменні винятки; без залежності від фреймворку та Doctrine-анотацій |
| Infrastructure | репозиторії на Doctrine, платіжні адаптери, Redis, Messenger-транспорти, маппінг сутностей |

**Bounded contexts:**

| Контекст | Зміст |
|---|---|
| Catalog | категорії, товари, варіанти, атрибути |
| Pricing | прайс-листи, tier-ціни, правила знижок, рушій розрахунку та explain |
| Inventory | склади, залишки, резерви |
| Cart | кошик та його розрахунок |
| Order | замовлення, Workflow, історія переходів |
| Payment | платіжний інтерфейс, платежі, вебхуки |
| Identity | користувачі, групи покупців, JWT, ролі |
| Shared | `Money`, годинник, шини, обробка помилок, аудит |

**Інфраструктура:**

- **PostgreSQL** — основне сховище; JSONB для атрибутів, правил і знімків розрахунку;
- **Redis** — кеш, розподілені блокування, rate limiter;
- **Symfony Messenger** — черги (`async`), failure transport, планувальник (звільнення прострочених резервів);
- **Docker Compose** — локальне середовище і базова форма розгортання.

### Обґрунтування архітектурних рішень

- Модульний моноліт дає межі контекстів без операційної складності мікросервісів; межі контролюються інструментом Deptrac.
- Доменний шар не залежить від Symfony/Doctrine, що дозволяє юніт-тестувати Pricing Engine без БД і фреймворку.
- Сутності Doctrine та ресурси API розділені DTO: контракт API не залежить від схеми зберігання і не розкриває внутрішні поля.
- Замість EAV використовується JSONB: атрибути товару валідуються за JSON Schema, що задається на рівні категорії.
- Замість магічних interceptors точки розширення явні: доменні події й стадії pipeline мають фіксований, документований порядок.
- Гроші зберігаються як ціле число в мінімальних одиницях + код валюти (бібліотека `brick/money`), що виключає помилки округлення `float`.
- Event Sourcing та CQRS із окремою read-моделлю в MVP не застосовуються: використовується легка форма CQRS (розділення команд і запитів на рівні handlers) без окремих сховищ.

### Pricing pipeline

Розрахунок кошика виконується послідовністю стадій із фіксованим порядком:

1. **Base price** — вибір ціни з прайс-листа (валюта, група покупців, період дії, tier за кількістю).
2. **Item promotions** — правила рівня позиції за пріоритетом.
3. **Cart promotions** — правила рівня кошика, купони.
4. **Shipping** — вартість доставки за методом.
5. **Totals** — підсумок і формування explain.

Правило може мати `stop_processing`: після його застосування наступні правила цього рівня не розглядаються.

Приклад правила:

```json
{
  "name": "-10% на взуття від 2000 грн",
  "priority": 100,
  "stop_processing": false,
  "conditions": {
    "all": [
      { "field": "cart.subtotal", "op": ">=", "value": 200000 },
      { "field": "item.category", "op": "in", "value": ["shoes"] }
    ]
  },
  "actions": [
    { "type": "percent_discount", "target": "item", "value": 10 }
  ]
}
```

Приклад explain-відповіді (суми у копійках):

```json
{
  "currency": "UAH",
  "subtotal": 250000,
  "discount": 25000,
  "shipping": 0,
  "total": 225000,
  "applied_rules": [
    { "rule_id": "…", "name": "-10% на взуття від 2000 грн", "scope": "item", "discount": 25000 }
  ],
  "skipped_rules": [
    { "rule_id": "…", "name": "Купон SUMMER", "reason": "coupon_not_provided" }
  ]
}
```

### Життєвий цикл замовлення

```mermaid
stateDiagram-v2
    [*] --> pending_payment: place_order
    pending_payment --> paid: pay
    pending_payment --> cancelled: cancel
    paid --> processing: start_processing
    paid --> cancelled: cancel
    processing --> shipped: ship
    processing --> cancelled: cancel
    shipped --> completed: complete
```

Кожен перехід фіксується в `OrderStatusHistory` (звідки, куди, ким, коли).

### Наскрізний потік запиту (приклад: оформлення замовлення)

Client → `POST /api/v1/checkout` (JWT або токен кошика, заголовок `Idempotency-Key`) → Api валідує тіло запиту та повторний ключ → Application відкриває транзакцію → Cart перераховується Pricing Engine (ціни з клієнта не приймаються) → Inventory атомарно резервує залишки → створюється `Order` зі знімком цін та explain → Workflow ставить статус `pending_payment` → Payment створює платіж → транзакція фіксується → Api повертає замовлення й дані для оплати → фонова задача звільняє резерв, якщо оплата не надійшла до `expires_at`.

---

## AC-03. Інтерфейс користувача (API-інтерфейс і документація контракту)

Lavka Engine є headless-системою, тому основним «інтерфейсом» є API та його документація.

### Обов'язкові інтерфейси

| № | Адреса | Призначення |
|---|---|---|
| 1 | `/api/docs` | Swagger UI: інтерактивна OpenAPI-документація REST |
| 2 | `/api/graphql` | GraphQL endpoint (GraphiQL / playground у dev-режимі) |
| 3 | `/api/docs.jsonopenapi` | Експорт OpenAPI-специфікації |
| 4 | `/health` | Перевірка працездатності (БД, Redis) |

Додатково постачається Postman-колекція з типовими сценаріями (реєстрація → кошик → checkout → оплата).

### Опційна демо-вітрина (не входить до MVP)

Мінімальний storefront (Next.js або статичний HTML + vanilla JS) для демонстрації роботи API:

| Маршрут | Призначення |
|---|---|
| `/` | Список товарів із фільтрами |
| `/products/{slug}` | Картка товару з варіантами й актуальною ціною |
| `/cart` | Кошик, купон, розшифровка знижок (explain) |
| `/checkout` | Оформлення замовлення |
| `/orders/{number}` | Статус і історія замовлення |

### Принципи формування відповідей API

- Усі суми повертаються у мінімальних одиницях із полем `currency`; форматування виконує клієнт.
- Відповіді кошика завжди містять блок `pricing` з explain, щоб клієнт міг показати покупцю розшифровку знижок.
- Помилки мають єдиний машинозчитуваний код (див. AC-06), що дозволяє клієнту показувати локалізовані повідомлення.
- Для порожніх колекцій повертається коректна пагінація з `total = 0`.

---

## AC-04. Робота з даними

### Перелік сутностей

| Сутність | Опис | Основні поля |
|---|---|---|
| User | обліковий запис (покупець або персонал) | id (UUID), email (унікальне), password_hash, roles, customer_group_id, created_at |
| CustomerGroup | група покупців для цін і правил | id, code (унікальне), name |
| Category | категорія товарів (дерево) | id, parent_id, slug (унікальне), name, attribute_schema (JSONB), is_active |
| Product | товар | id, category_id, slug (унікальне), name, description, attributes (JSONB), status (draft/active/archived), created_at |
| ProductVariant | варіант товару (SKU) | id, product_id, sku (унікальне), options (JSONB), is_active |
| PriceList | прайс-лист | id, code, currency, customer_group_id (nullable), valid_from, valid_to, priority |
| Price | ціна варіанта в прайс-листі | id, price_list_id, variant_id, amount_minor, min_quantity |
| PromotionRule | правило знижки | id, name, priority, conditions (JSONB), actions (JSONB), stop_processing, coupon_code (nullable, унікальне), valid_from, valid_to, is_active |
| Warehouse | склад | id, code, name |
| StockItem | залишок варіанта на складі | id, variant_id, warehouse_id, quantity, reserved |
| StockReservation | резерв залишку | id, order_id, variant_id, warehouse_id, quantity, status (active/committed/released), expires_at |
| Cart | кошик | id, token (унікальне), user_id (nullable), currency, coupon_code, status (active/converted/abandoned), updated_at |
| CartItem | позиція кошика | id, cart_id, variant_id, quantity |
| Order | замовлення | id, number (унікальне), user_id (nullable), email, status, currency, subtotal_minor, discount_minor, shipping_minor, total_minor, pricing_snapshot (JSONB), shipping_address (JSONB), idempotency_key (унікальне), placed_at |
| OrderItem | позиція замовлення (знімок) | id, order_id, variant_id, sku, name, unit_price_minor, quantity, discount_minor, total_minor |
| OrderStatusHistory | журнал переходів | id, order_id, from_status, to_status, transition, actor_id, created_at |
| Payment | платіж | id, order_id, provider, external_id, status, amount_minor, created_at |
| AuditLog | аудит дій персоналу | id, actor_id, action, entity_type, entity_id, changes (JSONB), created_at |

### Обмеження цілісності даних

- `email`, `sku`, `slug`, `Order.number`, `Order.idempotency_key`, `PromotionRule.coupon_code` — унікальні;
- унікальна пара `(provider, external_id)` у Payment гарантує ідемпотентну обробку вебхуків;
- унікальна пара `(variant_id, warehouse_id)` у StockItem;
- обмеження `CHECK (quantity - reserved >= 0)` і `CHECK (reserved >= 0)` у StockItem на рівні БД;
- усі грошові поля — цілі невід'ємні числа в мінімальних одиницях;
- `Order`, `OrderItem` і `pricing_snapshot` є незмінними знімками: зміна каталогу чи цін не впливає на вже оформлені замовлення;
- товар, що фігурує в замовленнях, не видаляється фізично: використовується статус `archived`;
- видалення кошика каскадно видаляє його позиції; видалення замовлення не передбачене;
- атрибути товару перевіряються за `Category.attribute_schema` під час збереження.

### Резервування залишків

Резервування виконується одним атомарним SQL-оновленням, що не потребує окремого читання перед записом:

```sql
UPDATE stock_item
SET reserved = reserved + :qty
WHERE variant_id = :variant AND warehouse_id = :wh
  AND quantity - reserved >= :qty;
-- 0 змінених рядків означає недостатній залишок
```

Це гарантує відсутність oversell при паралельних замовленнях без блокування таблиці.

### Операції над сутностями

| Сутність | Створення | Читання | Оновлення | Видалення |
|---|---|---|---|---|
| Category / Product / Variant | admin, manager | всі (лише active) | admin, manager | архівація (admin) |
| PriceList / Price | admin, manager | admin, manager; ціна варіанта — всі через розрахунок | admin, manager | admin |
| PromotionRule | admin, manager | admin, manager | admin, manager | admin (або деактивація) |
| StockItem | admin, manager | admin, manager | admin, manager (коригування) | — |
| Cart / CartItem | guest, customer | власник за токеном або JWT | власник | власник |
| Order | customer, guest (через checkout) | власник; персонал — усі | лише через переходи Workflow | — |
| Payment | система | власник замовлення, персонал | вебхук платіжного провайдера | — |
| AuditLog | система | admin | — | — |

---

## AC-05. Форми та валідація

### Рівні валідації

1. **Валідація на рівні API (Symfony Validator)** — структурна перевірка DTO: типи, формат, довжина, діапазони. Виконується до виклику Application-шару.
2. **Інваріанти домену** — value objects (`Money`, `Quantity`, `Sku`) не можуть бути створені в некоректному стані (від'ємна сума, нульова кількість).
3. **Валідація бізнес-правил (Application)** — правила, що залежать від стану системи: наявність залишку, чинність купона, допустимість переходу Workflow.
4. **Обмеження БД** — остання лінія захисту (унікальність, `CHECK`, зовнішні ключі).

Клієнтська валідація на стороні storefront є лише покращенням UX і не є механізмом захисту.

### Правила валідації

| Поле / дія | Правило | Рівень |
|---|---|---|
| email | коректний формат, обов'язкове поле | API |
| email | унікальність у системі | Application, БД |
| password | мінімум 8 символів | API |
| slug | `[a-z0-9-]`, 1–100 символів, унікальний | API, БД |
| name (товар, категорія) | 1–255 символів, обов'язкове поле | API |
| sku | 1–64 символи, унікальний | API, БД |
| attributes товару | відповідність `attribute_schema` категорії | Application |
| amount (ціна) | ціле число ≥ 0, валюта збігається з прайс-листом | API, Domain |
| quantity позиції | ціле число ≥ 1 і ≤ ліміт на позицію (наприклад, 100) | API, Domain |
| PromotionRule.conditions / actions | відповідність JSON Schema правил; відомі `field`, `op`, `type` | API, Application |
| PromotionRule.valid_to | не раніше `valid_from` | API |
| coupon_code | існує, активний, у межах терміну дії | Application |
| додавання в кошик | варіант існує, активний, товар має статус active | Application |
| checkout | кошик не порожній, достатньо залишку, унікальний `Idempotency-Key` | Application |
| shipping_address | обов'язкові поля (країна, місто, адреса, отримувач, телефон) | API |
| перехід замовлення | дозволений поточним станом (Workflow guard) | Application |
| вебхук оплати | дійсний підпис провайдера; сума збігається із сумою замовлення | Application |

### Відображення результатів валідації

Помилки структури повертаються з кодом 422 і переліком порушень за полями (`violations[].propertyPath`, `violations[].message`). Помилки бізнес-правил повертаються з кодом 400 або 409 і машинозчитуваним кодом помилки, що не прив'язаний до конкретного поля.

---

## AC-06. Взаємодія клієнта та сервера

### Механізм взаємодії

Взаємодія здійснюється через REST API (повний набір операцій) і GraphQL (читання каталогу, кошика та замовлень). Формат даних — JSON. Версіонування — через префікс `/api/v1`.

### Автентифікація на рівні транспорту

Після входу клієнт отримує JWT, який передається в заголовку `Authorization: Bearer <token>`. Гостьовий кошик ідентифікується заголовком `X-Cart-Token`. Ідемпотентні операції (checkout) приймають заголовок `Idempotency-Key`.

### Структура REST API

```
Auth:         POST   /api/v1/auth/register
              POST   /api/v1/auth/login
              GET    /api/v1/me

Catalog:      GET    /api/v1/categories
              GET    /api/v1/products
              GET    /api/v1/products/{slug}
              POST   /api/v1/admin/categories
              PATCH  /api/v1/admin/categories/{id}
              POST   /api/v1/admin/products
              PATCH  /api/v1/admin/products/{id}
              DELETE /api/v1/admin/products/{id}          (архівація)
              POST   /api/v1/admin/products/{id}/variants
              PATCH  /api/v1/admin/variants/{id}

Pricing:      GET    /api/v1/admin/price-lists
              POST   /api/v1/admin/price-lists
              PUT    /api/v1/admin/price-lists/{id}/prices
              GET    /api/v1/admin/promotion-rules
              POST   /api/v1/admin/promotion-rules
              PATCH  /api/v1/admin/promotion-rules/{id}
              POST   /api/v1/admin/promotion-rules/preview   (перегляд ефекту правила на тестовому кошику)

Inventory:    GET    /api/v1/admin/stock
              PATCH  /api/v1/admin/stock/{variant_id}

Cart:         POST   /api/v1/carts
              GET    /api/v1/carts/{token}
              POST   /api/v1/carts/{token}/items
              PATCH  /api/v1/carts/{token}/items/{id}
              DELETE /api/v1/carts/{token}/items/{id}
              PUT    /api/v1/carts/{token}/coupon
              DELETE /api/v1/carts/{token}/coupon

Checkout:     POST   /api/v1/checkout

Orders:       GET    /api/v1/orders
              GET    /api/v1/orders/{number}
              POST   /api/v1/admin/orders/{number}/transitions/{name}
              GET    /api/v1/admin/orders
              GET    /api/v1/orders/{number}/history

Payments:     POST   /api/v1/payments/webhook/{provider}

System:       GET    /health
```

### Формат обробки помилок

Помилки повертаються у форматі RFC 9457 (`application/problem+json`) з додатковим машинозчитуваним полем `code`:

```json
{
  "type": "https://lavka.dev/errors/insufficient-stock",
  "title": "Недостатньо залишку",
  "status": 409,
  "code": "INSUFFICIENT_STOCK",
  "detail": "Для SKU SHOE-42-BLK доступно 1 шт., запрошено 3",
  "violations": []
}
```

| Код HTTP | Умова виникнення |
|---|---|
| 400 | порушення бізнес-правила (недійсний купон, порожній кошик) |
| 401 | відсутня або недійсна автентифікація |
| 403 | недостатньо прав доступу |
| 404 | ресурс не знайдено |
| 409 | конфлікт стану (недостатньо залишку, недопустимий перехід, повторний унікальний ключ) |
| 422 | помилка валідації вхідних даних |
| 429 | перевищено ліміт запитів |

### Формат успішної відповіді

Одиничний ресурс повертається безпосередньо у тілі відповіді. Колекції повертаються з пагінацією:

```json
{
  "items": [],
  "total": 42,
  "page": 1,
  "page_size": 20
}
```

Фільтрація й сортування каталогу виконуються через query-параметри (`category`, `price_min`, `price_max`, `q`, `sort=price|-price|created_at`, `page`, `page_size`).

### Ідемпотентність

- Повторний `POST /checkout` з тим самим `Idempotency-Key` повертає вже створене замовлення, а не створює нове.
- Повторна доставка вебхука оплати не змінює стан завдяки унікальності `(provider, external_id)` та перевірці поточного статусу.

### Мережева конфігурація

Для дозволених origin налаштовується `nelmio/cors-bundle` із явним переліком джерел. Символи `*` у production не використовуються.

---

## AC-07. Якість коду та архітектурна організація

### Структура проєкту

```
lavka/
├── bin/console
├── config/
│   ├── packages/
│   ├── routes/
│   ├── jwt/                  # ключі (не в репозиторії)
│   └── services.yaml
├── migrations/
├── src/
│   ├── Shared/
│   │   ├── Domain/           # Money, Clock, DomainException
│   │   ├── Application/      # шини команд/запитів
│   │   └── Infrastructure/   # ProblemDetails, AuditSubscriber
│   ├── Catalog/
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Api/
│   ├── Pricing/
│   │   ├── Domain/           # PricingEngine, Rule, Condition, Action, Explanation
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   └── Api/
│   ├── Inventory/
│   ├── Cart/
│   ├── Order/
│   ├── Payment/
│   └── Identity/
├── tests/
│   ├── Unit/                 # Domain, Pricing Engine
│   ├── Integration/          # репозиторії, резервування, Workflow
│   └── Functional/           # API-сценарії
├── docs/
│   ├── adr/
│   └── diagrams/
├── docker/
├── compose.yaml
├── composer.json
├── phpstan.neon
├── deptrac.yaml
├── .php-cs-fixer.dist.php
└── .env
```

### Розподіл відповідальності між шарами

| Шар | Відповідальність | Заборонено |
|---|---|---|
| Api | HTTP, DTO, валідація, серіалізація, виклик handlers | бізнес-логіка, прямі запити до БД |
| Application | сценарії, транзакції, права (Voters), оркестрація | SQL/ORM-запити напряму, залежність від HTTP-об'єктів |
| Domain | правила, інваріанти, Pricing Engine | залежність від Symfony, Doctrine, HTTP, БД |
| Infrastructure | Doctrine-репозиторії, адаптери, Redis, черги | бізнес-правила |

Дозволені напрями залежностей: `Api → Application → Domain`, `Infrastructure → Domain`. Порушення блокуються Deptrac у CI. Прямі залежності між контекстами допускаються лише через Application-інтерфейси та доменні події.

### Застосування принципів SOLID та DRY

- **Принцип єдиної відповідальності.** Кожен handler виконує один сценарій (`PlaceOrderHandler`, `AddCartItemHandler`); розрахунок цін винесено в окремий доменний сервіс.
- **Принцип відкритості/закритості.** Нові типи умов і дій правил знижок додаються реалізацією інтерфейсів `Condition` і `Action` та реєстрацією через теги DI-контейнера без зміни ядра рушія. Так само підключаються нові платіжні адаптери (`PaymentGateway`).
- **Інверсія залежностей.** Application залежить від інтерфейсів репозиторіїв і шлюзів, що оголошені в Domain; реалізації підставляються контейнером, у тестах — заміняються фейками.
- **Уникнення дублювання коду.** Розрахунок цін виконує єдиний `PricingEngine`, яким користуються і кошик, і checkout, і preview правил. Перевірки прав — у Voters. Обробка помилок — в одному listener.
- **Явні extension points.** Стадії pricing pipeline та доменні події мають задокументований порядок виконання.

### Угоди щодо іменування та стилю

- PSR-1, PSR-4, PSR-12; форматування — PHP-CS-Fixer;
- сутності та value objects — іменники в однині (`Order`, `Money`);
- команди — дієслово + іменник (`PlaceOrder`, `CancelOrder`), handlers — суфікс `Handler`;
- доменні події — минулий час (`OrderPlaced`, `StockReserved`);
- строгі типи (`declare(strict_types=1)`) у всіх файлах.

### Інструменти контролю якості

| Інструмент | Призначення |
|---|---|
| PHPStan (level 8+) | статичний аналіз типів |
| PHP-CS-Fixer | стиль коду |
| Deptrac | контроль меж контекстів і шарів |
| PHPUnit | unit-, integration- та functional-тести |
| Infection | mutation testing для Pricing Engine |
| GitHub Actions | автоматичний запуск усіх перевірок на кожен push |

### Обґрунтування рівня складності архітектури

Доменно насичені частини (Pricing, Order, Inventory) обґрунтовують окремий доменний шар, value objects і Workflow. Для простих частин (довідники, CRUD категорій) допускається спрощена реалізація без окремих доменних сервісів. Event Sourcing, окремі read-сховища і мікросервіси не застосовуються, оскільки на обсязі MVP вони створюють надлишкову складність.

---

## AC-08. Git та історія розробки

### Репозиторій

Проєкт зберігається в одному Git-репозиторії. Демо-вітрина (за наявності) розміщується в каталозі `storefront/` того самого репозиторію.

### Стратегія роботи з гілками

- `main` — стабільна версія, придатна до розгортання;
- `dev` — інтеграційна гілка поточної розробки;
- `feature/<назва>` — гілки функціональних блоків (наприклад, `feature/pricing-engine`, `feature/stock-reservation`, `feature/order-workflow`), що зливаються в `dev` після проходження CI.

### Конвенція повідомлень комітів

Застосовується Conventional Commits:

```
feat(pricing): додано правила знижок із пріоритетами та stop_processing
fix(inventory): виправлено звільнення резерву при скасуванні замовлення
refactor(cart): винесено pipeline розрахунку в окремий сервіс
docs(adr): додано рішення щодо зберігання атрибутів у JSONB
test(pricing): додано мутаційні тести для PricingEngine
chore(ci): додано Deptrac у GitHub Actions
```

Історія комітів відображає послідовність: постановка задачі → проєктування → реалізація за контекстами → інтеграція → тестування → документування → розгортання.

### Вміст .gitignore

```
/vendor/
/var/
/public/bundles/
/config/jwt/*.pem
.env.local
.env.*.local
.phpunit.cache/
.php-cs-fixer.cache
.idea/
.vscode/
.DS_Store
node_modules/
```

### Обов'язкові файли репозиторію

`README.md`, `.gitignore`, `.env` (лише значення за замовчуванням без секретів), `compose.yaml`, `docs/adr/`, `docs/diagrams/`.

---

## AC-09. Документація

### Структура README.md

1. **Назва та опис проєкту** — призначення, розв'язувана проблема, ключова відмінність (Promotion Engine з explain).
2. **Технологічний стек** — перелік технологій із коротким обґрунтуванням вибору.
3. **Системні вимоги** — Docker та Docker Compose; для запуску без Docker: PHP 8.3+, Composer, PostgreSQL 16+, Redis 7+.
4. **Встановлення**:
   - клонування репозиторію;
   - `docker compose up -d`;
   - `composer install` (усередині контейнера);
   - генерація JWT-ключів (`bin/console lexik:jwt:generate-keypair`);
   - копіювання `.env` у `.env.local` і заповнення значень.
5. **Налаштування бази даних** — `bin/console doctrine:migrations:migrate`; завантаження демо-даних `bin/console doctrine:fixtures:load`.
6. **Запуск застосунку**:
   - API: `http://localhost:8080`;
   - OpenAPI-документація: `http://localhost:8080/api/docs`;
   - GraphQL: `http://localhost:8080/api/graphql`;
   - воркер: `bin/console messenger:consume async scheduler_default`.
7. **Запуск тестів і перевірок** — `composer test`, `composer stan`, `composer cs`, `composer deptrac`, `composer infection`.
8. **Опис основних можливостей** — стисло, з посиланням на AC-01.
9. **Приклади використання** — типовий сценарій: створення товару й правила знижки → створення кошика → додавання позицій → отримання explain → checkout → оплата → зміна статусу замовлення (з прикладами `curl`).

### Додаткова документація

- **ADR (Architecture Decision Records)** у `docs/adr/`: вибір Symfony, JSONB замість EAV, зберігання грошей у мінімальних одиницях, атомарне резервування, JSON-DSL правил замість виразів, модульний моноліт;
- **Діаграми** у `docs/diagrams/`: C4 (Context / Container), state machine замовлення, ER-діаграма;
- **OpenAPI-специфікація** та **Postman-колекція** із типовими сценаріями.

---

## AC-10. Залежності та конфігурація

### Керування залежностями

Залежності фіксуються у `composer.json` і `composer.lock` (lock-файл додається до репозиторію). Основні пакети:

| Пакет | Призначення |
|---|---|
| symfony/framework-bundle, symfony/runtime | ядро фреймворку |
| api-platform/symfony | REST/GraphQL, OpenAPI |
| doctrine/orm, doctrine/doctrine-bundle, doctrine/doctrine-migrations-bundle | ORM і міграції |
| symfony/messenger, symfony/scheduler | черги та планувальник |
| symfony/workflow | state machine замовлення |
| symfony/security-bundle, lexik/jwt-authentication-bundle | автентифікація, JWT, Voters |
| symfony/validator, symfony/serializer | валідація та серіалізація |
| symfony/rate-limiter, symfony/lock | rate limiting, розподілені блокування |
| symfony/monolog-bundle | логування |
| nelmio/cors-bundle | CORS |
| brick/money | коректна робота з грошима |
| opis/json-schema | валідація атрибутів і правил за JSON Schema |
| predis/predis або ext-redis | Redis |
| phpunit/phpunit, symfony/test-pack | тестування |
| doctrine/doctrine-fixtures-bundle, zenstruck/foundry | тестові дані |
| phpstan/phpstan, friendsofphp/php-cs-fixer, qossmic/deptrac, infection/infection | якість коду |

### Конфігурація та секрети

Конфігурація завантажується зі змінних середовища (`.env`, `.env.local`). Файл `.env.local` і приватні ключі JWT не потрапляють до репозиторію; у production використовується Symfony Secrets або змінні середовища платформи. Приклад змінних:

```
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="postgresql://app:app@database:5432/lavka?serverVersion=16&charset=utf8"
REDIS_URL=redis://redis:6379
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages
JWT_PASSPHRASE=change-me
JWT_TTL=3600
CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'
CART_RESERVATION_TTL=900
PAYMENT_WEBHOOK_SECRET=change-me
```

---

## AC-11. Безпека

| Загроза | Захід протидії |
|---|---|
| SQL-ін'єкція | Doctrine ORM/DBAL із параметризованими запитами; заборона конкатенації рядків у SQL |
| Підміна ціни клієнтом | клієнт не передає ціни: усі суми обчислює сервер за прайс-листами та правилами; у замовленні зберігається знімок |
| Oversell при паралельних замовленнях | атомарне резервування SQL-оновленням із `CHECK`-обмеженням на рівні БД |
| Дублювання замовлень і платежів | `Idempotency-Key` для checkout; унікальність `(provider, external_id)` для вебхуків |
| Підробка вебхука оплати | перевірка підпису провайдера та відповідності суми |
| Небезпечне зберігання паролів | хешування через Symfony PasswordHasher (`auto`: bcrypt/argon2id) |
| Підробка або підміна токена | підпис JWT асиметричним ключем; обмежений термін дії; ключі поза репозиторієм |
| Доступ до чужих даних (IDOR) | перевірка належності кошика й замовлення через Voters; токен кошика — непередбачуваний (криптографічно випадковий) |
| Mass assignment / витік внутрішніх полів | окремі DTO та групи серіалізації; поля `roles`, `password_hash` недоступні через API |
| Перебір паролів і зловживання API | `symfony/rate-limiter` на `/auth/login`, `/auth/register`, `/checkout` |
| Витік секретів | змінні середовища, Symfony Secrets; `.env.local` і `config/jwt/*.pem` у `.gitignore` |
| Некоректні вхідні дані | багаторівнева валідація (Validator, value objects, бізнес-правила, БД) |
| Небезпечні правила знижок | правила — лише структурований JSON за схемою; довільне виконання коду чи виразів не допускається |
| Розкриття технічних деталей помилок | єдиний формат помилок без стек-трейсів; `APP_DEBUG=0` у production |
| XSS у демо-вітрині | вставка даних у DOM через `textContent` або екранування шаблонізатором |
| Небезпечні заголовки та CORS | явний список дозволених origin; заголовки безпеки на рівні reverse proxy |

CSRF-захист не застосовується для API, оскільки автентифікація stateless і не використовує cookies.

---

## AC-12. Автентифікація та авторизація

### Автентифікація

Реалізується через JWT (`lexik/jwt-authentication-bundle`): користувач отримує токен доступу після входу за email та паролем. Токен передається в заголовку `Authorization`. Гостьові користувачі працюють із кошиком за токеном кошика й оформлюють замовлення, вказуючи email.

### Модель авторизації

Ролі глобальні (на відміну від бюджетної моделі «на ресурс»), оскільки система керує єдиним магазином:

| Роль | Призначення |
|---|---|
| `GUEST` (неавтентифікований) | перегляд каталогу, робота з власним кошиком за токеном, checkout |
| `ROLE_CUSTOMER` | те саме + перегляд власних замовлень і адрес |
| `ROLE_MANAGER` | керування каталогом, цінами, правилами, залишками, замовленнями |
| `ROLE_ADMIN` | усі права менеджера + керування користувачами, видалення, перегляд аудит-логу |

Перевірка прав виконується централізовано через Symfony Voters (`CartVoter`, `OrderVoter`, `CatalogVoter`) і атрибути `#[IsGranted]`, що унеможливлює дублювання логіки в окремих ендпоінтах.

### Матриця прав доступу

| Дія | Guest | Customer | Manager | Admin |
|---|---|---|---|---|
| Перегляд каталогу й розрахунок цін | так | так | так | так |
| Робота з власним кошиком | так (за токеном) | так | так | так |
| Checkout | так | так | так | так |
| Перегляд власних замовлень | ні | так | так | так |
| Перегляд усіх замовлень | ні | ні | так | так |
| Зміна статусу замовлення (transitions) | ні | лише `cancel` власного замовлення в `pending_payment` | так | так |
| Керування каталогом і цінами | ні | ні | так | так |
| Керування правилами знижок | ні | ні | так | так |
| Коригування залишків | ні | ні | так | так |
| Архівація/видалення товарів і правил | ні | ні | ні | так |
| Керування користувачами й ролями | ні | ні | ні | так |
| Перегляд аудит-логу | ні | ні | ні | так |

---

## AC-13. Обробка помилок

Обробка помилок реалізована на чотирьох рівнях:

1. **Очікувані помилки бізнес-логіки** (`InsufficientStock`, `InvalidCoupon`, `InvalidTransition`, `NotFound`, `AccessDenied`) реалізовані як доменні та прикладні винятки з машинозчитуваним кодом і перехоплюються єдиним `ExceptionListener`, що формує відповідь у форматі RFC 9457 (див. AC-06).
2. **Помилки валідації вхідних даних** обробляються Symfony Validator (код 422) і приводяться до єдиної структури з переліком `violations`.
3. **Непередбачені помилки** (недоступність БД чи Redis, збій платіжного провайдера) перехоплюються глобальним обробником: клієнту повертається загальна відповідь 500 без деталей, повний стек фіксується в лозі Monolog з ідентифікатором запиту для кореляції.
4. **Помилки фонової обробки**: Messenger повторює невдалі повідомлення за стратегією retry з експоненційною затримкою; після вичерпання спроб повідомлення переміщується в `failure` transport для ручного аналізу й повторного запуску.

Додаткові гарантії:

- оформлення замовлення виконується в одній транзакції БД: у разі помилки резерви та частково створені записи відкочуються;
- прострочені резерви звільняються планувальною задачею, тому «завислі» замовлення не блокують склад;
- перевірка `/health` повідомляє про стан БД і Redis для моніторингу.

---

## AC-14. Розгортання

### Локальний запуск

Локальне середовище піднімається командою `docker compose up -d` (сервіси: `app` — PHP (FrankenPHP або PHP-FPM + Nginx), `worker` — Messenger consumer, `database` — PostgreSQL, `redis`). Детальна інструкція наведена в README.md (розділ AC-09).

### Розгортання на хостингу

Застосунок збирається як Docker-образ (multi-stage build, `composer install --no-dev --optimize-autoloader`, `APP_ENV=prod`, `APP_DEBUG=0`) і розгортається на VPS із Docker Compose або на платформі з підтримкою контейнерів (наприклад, Fly.io, Railway). Окремо запускаються:

- веб-процес (HTTP);
- воркер: `bin/console messenger:consume async scheduler_default --time-limit=3600`;
- керований сервіс PostgreSQL і Redis (або контейнери на тому самому хості).

### Підготовка бази даних при розгортанні

Перед першим запуском і при кожному оновленні виконується `bin/console doctrine:migrations:migrate --no-interaction`. Адреса БД передається через `DATABASE_URL`. Демо-дані (fixtures) завантажуються лише у dev/demo-середовищі.

### CI/CD

GitHub Actions виконує на кожен push і pull request: перевірку стилю, PHPStan, Deptrac, PHPUnit (з PostgreSQL і Redis як сервісами), а для гілки `main` — збірку образу. Автоматичне розгортання з `main` на цільове середовище є цільовим станом.
