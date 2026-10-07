<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use App\Catalog\Application\CatalogService;
use App\Identity\Infrastructure\Security\User;
use App\Inventory\Application\InventoryService;
use App\Order\Application\OrderService;
use App\Pricing\Application\PricingService;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Application\RecordBrowser;
use App\Shared\Domain\Exception\DomainError;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_MANAGER')]
final class StaffController extends AbstractController
{
    private const TABLES = ['products' => 'product', 'categories' => 'category', 'variants' => 'product_variant', 'warehouses' => 'warehouse', 'stock' => 'stock_item', 'lists' => 'price_list', 'prices' => 'price'];

    public function __construct(private readonly RecordBrowser $records, private readonly CatalogService $catalog, private readonly InventoryService $inventory, private readonly PricingService $pricing, private readonly OrderService $orders, private readonly ShoppingSession $shopping, private readonly Connection $connection)
    {
    }

    #[Route('/admin', name: 'staff_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        return $this->render('staff/home.html.twig', ['counts' => (object) ['products' => $this->records->page('product', 1, 1)->total, 'stock' => $this->records->page('stock_item', 1, 1)->total, 'orders' => $this->orders->listing(null, true, 1, 1)->total], 'csrf' => $this->shopping->csrf($request)]);
    }

    #[Route('/admin/orders', name: 'staff_orders', methods: ['GET'])]
    public function orders(Request $request): Response
    {
        return $this->render('staff/orders.html.twig', ['rows' => $this->orders->listing(null, true, $this->page($request), 20), 'csrf' => $this->shopping->csrf($request)]);
    }

    #[Route('/admin/orders/{number}', name: 'staff_order', methods: ['GET', 'POST'])]
    public function order(string $number, Request $request): Response
    {
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $this->shopping->validate($request);
            try {
                $this->write($request, fn () => $this->orders->transition($number, $request->request->getString('transition'), $this->actor(), true));

                return $this->redirectToRoute('staff_order', ['number' => $number], 303);
            } catch (ApiProblem|DomainError $problem) {
                $error = $problem->getMessage();
                $status = 409;
            }
        }
        try {
            $order = $this->orders->get($number, $this->actor(), true);
        } catch (ApiProblem) {
            throw $this->createNotFoundException();
        }

        return $this->render('staff/order.html.twig', ['order' => $order, 'history' => $this->orders->history($number, $this->actor(), true, ''), 'error' => $error, 'csrf' => $this->shopping->csrf($request)], new Response(status: $status));
    }

    #[Route('/admin/manage/{section}', name: 'staff_manage', methods: ['GET', 'POST'])]
    public function manage(string $section, Request $request): Response
    {
        if (!isset(self::TABLES[$section])) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(match ($section) {'stock', 'warehouses' => 'INVENTORY_WRITE', 'prices', 'lists' => 'PRICING_WRITE', default => 'CATALOG_WRITE'});
        $edit = $request->query->getString('edit');
        $record = null;
        if ('' !== $edit && in_array($section, ['products', 'categories', 'variants'], true)) {
            try {
                $record = $this->records->find(self::TABLES[$section], $edit);
            } catch (ApiProblem) {
                throw $this->createNotFoundException();
            }
        }
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $this->shopping->validate($request);
            if ('archive' === $request->request->getString('operation')) {
                $this->denyAccessUnlessGranted('CATALOG_ARCHIVE');
            }
            try {
                $this->write($request, fn () => $this->save($section, $request, $record?->id));
                $this->addFlash('success', 'Зміни збережено.');

                return $this->redirectToRoute('staff_manage', ['section' => $section], 303);
            } catch (ApiProblem|DomainError|UniqueConstraintViolationException|ForeignKeyConstraintViolationException|\JsonException $problem) {
                $error = match (true) {
                    $problem instanceof UniqueConstraintViolationException => 'Такий код або назва URL уже використовується.',
                    $problem instanceof ForeignKeyConstraintViolationException => 'Пов’язаний запис недоступний.',
                    $problem instanceof \JsonException => 'Перевірте формат JSON.',
                    default => $problem->getMessage(),
                };
                $status = 422;
            }
        }

        return $this->render('staff/manage.html.twig', ['section' => $section, 'rows' => $this->records->page(self::TABLES[$section], $this->page($request), 20), 'record' => $record, 'values' => $request->isMethod('POST') ? $request->request->all() : [], 'choices' => (object) ['categories' => $this->choices('category'), 'products' => $this->choices('product'), 'variants' => $this->choices('product_variant'), 'warehouses' => $this->choices('warehouse'), 'lists' => $this->choices('price_list')], 'error' => $error, 'csrf' => $this->shopping->csrf($request)], new Response(status: $status));
    }

    private function save(string $section, Request $request, ?string $id): void
    {
        $form = $request->request;
        $text = static fn (string $key): string => $form->getString($key);
        $object = static function (string $key) use ($text): \stdClass {
            $value = json_decode($text($key), false, 32, JSON_THROW_ON_ERROR);
            if (!$value instanceof \stdClass) {
                throw new ApiProblem(422, 'INVALID_JSON', 'Очікується JSON-об’єкт.');
            }

            return $value;
        };
        if ('products' === $section && 'archive' === $text('operation')) {
            $this->catalog->archive($id ?? '');

            return;
        }
        match ($section) {
            'categories' => $this->catalog->category(new Input((object) ['name' => $text('name'), 'slug' => $text('slug'), 'is_active' => $form->getBoolean('is_active')]), $id),
            'products' => $this->catalog->product(new Input((object) ['name' => $text('name'), 'slug' => $text('slug'), 'category_id' => $text('category_id'), 'status' => $text('status'), 'description' => '' === $text('description') ? null : $text('description'), 'attributes' => $object('attributes')]), $id),
            'variants' => $this->catalog->variant(new Input((object) ['sku' => $text('sku'), 'options' => $object('options'), 'is_active' => $form->getBoolean('is_active')]), $text('product_id'), $id),
            'warehouses' => $this->inventory->warehouse(new Input((object) ['code' => $text('code'), 'name' => $text('name')])),
            'stock' => $this->inventory->adjust($text('variant_id'), new Input((object) ['warehouse_id' => $text('warehouse_id'), 'delta' => $form->getInt('delta')])),
            'lists' => $this->pricing->priceList(new Input((object) ['code' => $text('code'), 'currency' => 'UAH', 'priority' => $form->getInt('priority')])),
            'prices' => $this->pricing->prices($text('price_list_id'), new Input((object) ['prices' => [(object) ['variant_id' => $text('variant_id'), 'amount_minor' => $form->getInt('amount_minor'), 'min_quantity' => $form->getInt('min_quantity', 1)]]])),
            default => throw new \LogicException('Unsupported section.'),
        };
    }

    private function write(Request $request, callable $action): void
    {
        $this->connection->transactional(function () use ($request, $action): void {
            $action();
            $changes = $request->request->all();
            unset($changes['_token']);
            $this->connection->insert('audit_log', ['id' => Uuid::v7()->toRfc4122(), 'actor_id' => $this->actor(), 'action' => 'POST', 'resource' => $request->getPathInfo(), 'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
        });
    }

    private function actor(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user->id;
    }

    private function page(Request $request): int
    {
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > 100000) {
            throw new BadRequestHttpException();
        }

        return $page;
    }

    private function choices(string $table): \stdClass
    {
        $result = (object) ['items' => []];
        $page = 1;
        do {
            $batch = $this->records->page($table, $page++, 100);
            array_push($result->items, ...$batch->items);
        } while (count($result->items) < $batch->total);

        return $result;
    }
}
