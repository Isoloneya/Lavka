<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\CatalogQuery;
use App\Identity\Infrastructure\Security\User;
use App\Pricing\Domain\PricingRepository;
use App\Shared\Application\ApiProblem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class StorefrontController extends AbstractController
{
    #[Route('/', name: 'storefront_home', methods: ['GET'])]
    public function home(CatalogQuery $catalog, KernelInterface $kernel): Response
    {
        return $this->render('storefront/home.html.twig', [
            'products' => $catalog->products(1, 6, '', null, 'created_at', 'UAH', $this->customerGroup(), null, null),
            'has_video' => is_file($kernel->getProjectDir().'/public/storefront/media/campaign.mp4'),
        ]);
    }

    #[Route('/shop', name: 'storefront_catalog', methods: ['GET'])]
    public function catalog(Request $request, CatalogQuery $catalog): Response
    {
        $page = $request->query->getInt('page', 1);
        $sort = $request->query->getString('sort', 'created_at');
        $search = trim($request->query->getString('q'));
        $category = $request->query->getString('category');
        if ($page < 1 || $page > 100000 || !in_array($sort, ['created_at', 'name', '-name', 'price', '-price'], true) || mb_strlen($search) > 200 || strlen($category) > 255) {
            throw new BadRequestHttpException('Некоректні параметри каталогу.');
        }

        return $this->render('storefront/catalog.html.twig', [
            'products' => $catalog->products($page, 12, $search, '' === $category ? null : $category, $sort, 'UAH', $this->customerGroup(), null, null),
            'categories' => $catalog->categories(1, 100),
            'filters' => ['q' => $search, 'category' => $category, 'sort' => $sort],
        ]);
    }

    #[Route('/shop/{slug}', name: 'storefront_product', methods: ['GET'])]
    public function product(string $slug, CatalogQuery $catalog, PricingRepository $pricing, Request $request, ShoppingSession $shopping): Response
    {
        try {
            $product = $catalog->product($slug);
        } catch (ApiProblem $error) {
            if (404 !== $error->status) {
                throw $error;
            }

            throw $this->createNotFoundException('Товар не знайдено.');
        }
        foreach ($product->variants as $variant) {
            try {
                $variant->price_minor = $pricing->price($variant->id, 'UAH', $this->customerGroup(), 1);
            } catch (ApiProblem $error) {
                if ('PRICE_NOT_FOUND' !== $error->errorCode) {
                    throw $error;
                }
                $variant->price_minor = null;
            }
        }

        return $this->render('storefront/product.html.twig', ['product' => $product, 'csrf' => $shopping->csrf($request)]);
    }

    private function customerGroup(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->customerGroup : null;
    }
}
