<?php

declare(strict_types=1);

namespace App\Catalog\Api;

use App\Catalog\Application\CatalogService;
use App\Catalog\Infrastructure\CatalogQuery;
use App\Identity\Infrastructure\Security\User;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Application\RecordBrowser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CatalogController extends AbstractController
{
    #[Route('/api/v1/admin/categories', methods: ['GET'])]
    #[Route('/api/v1/admin/products', methods: ['GET'])]
    #[IsGranted('CATALOG_WRITE')]
    public function adminList(Request $request, RecordBrowser $browser): JsonResponse
    {
        $table = str_ends_with($request->getPathInfo(), '/categories') ? 'category' : 'product';

        return $this->json($browser->page($table, $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }

    #[Route('/api/v1/admin/categories/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[Route('/api/v1/admin/products/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[Route('/api/v1/admin/variants/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('CATALOG_WRITE')]
    public function adminDetail(string $id, Request $request, RecordBrowser $browser): JsonResponse
    {
        $table = str_contains($request->getPathInfo(), '/categories/') ? 'category' : (str_contains($request->getPathInfo(), '/variants/') ? 'product_variant' : 'product');

        return $this->json($browser->find($table, $id));
    }

    #[Route('/api/v1/categories', methods: ['GET'])]
    public function categories(Request $request, CatalogQuery $query): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $size = $request->query->getInt('page_size', 20);
        if ($page < 1 || $page > 100000 || $size < 1 || $size > 100) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна пагінація.');
        }

        return $this->json($query->categories($page, $size));
    }

    #[Route('/api/v1/products', methods: ['GET'])]
    public function products(Request $request, CatalogQuery $query): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $size = $request->query->getInt('page_size', 20);
        $sort = $request->query->getString('sort', 'created_at');
        if ($page < 1 || $page > 100000 || $size < 1 || $size > 100 || !in_array($sort, ['name', '-name', 'created_at', 'price', '-price'], true)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректна пагінація або сортування.');
        }

        $currency = $request->query->getString('currency', 'UAH');
        (new Input((object) ['currency' => $currency]))->currency();
        $min = $request->query->has('price_min') ? $request->query->getInt('price_min') : null;
        $max = $request->query->has('price_max') ? $request->query->getInt('price_max') : null;
        if ((null !== $min && $min < 0) || (null !== $max && $max < 0) || (null !== $min && null !== $max && $min > $max)) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Некоректний діапазон цін.');
        }
        $user = $this->getUser();

        return $this->json($query->products($page, $size, $request->query->getString('q'), $request->query->get('category'), $sort, $currency, $user instanceof User ? $user->customerGroup : null, $min, $max));
    }

    #[Route('/api/v1/products/{slug}', methods: ['GET'])]
    public function product(string $slug, CatalogQuery $query): JsonResponse
    {
        return $this->json($query->product($slug));
    }

    #[Route('/api/v1/admin/categories', methods: ['POST'])]
    #[Route('/api/v1/admin/categories/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('CATALOG_WRITE')]
    public function categoryWrite(Request $request, CatalogService $catalog, ?string $id = null): JsonResponse
    {
        return $this->json($catalog->category(Input::fromJson($request->getContent()), $id), null === $id ? 201 : 200);
    }

    #[Route('/api/v1/admin/products', methods: ['POST'])]
    #[Route('/api/v1/admin/products/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('CATALOG_WRITE')]
    public function productWrite(Request $request, CatalogService $catalog, ?string $id = null): JsonResponse
    {
        return $this->json($catalog->product(Input::fromJson($request->getContent()), $id), null === $id ? 201 : 200);
    }

    #[Route('/api/v1/admin/products/{productId}/variants', methods: ['POST'], requirements: ['productId' => '[0-9a-fA-F-]{36}'])]
    #[Route('/api/v1/admin/variants/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('CATALOG_WRITE')]
    public function variantWrite(Request $request, CatalogService $catalog, ?string $productId = null, ?string $id = null): JsonResponse
    {
        return $this->json($catalog->variant(Input::fromJson($request->getContent()), $productId, $id), null === $id ? 201 : 200);
    }

    #[Route('/api/v1/admin/products/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('CATALOG_ARCHIVE')]
    public function archive(string $id, CatalogService $catalog): JsonResponse
    {
        $catalog->archive($id);

        return new JsonResponse(null, 204);
    }
}
