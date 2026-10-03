<?php

declare(strict_types=1);

namespace App\Pricing\Api;

use App\Identity\Infrastructure\Security\User;
use App\Pricing\Application\PricingService;
use App\Shared\Application\Input;
use App\Shared\Application\RecordBrowser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PricingController extends AbstractController
{
    #[Route('/api/v1/admin/price-lists/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[Route('/api/v1/admin/promotion-rules/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id, Request $request, RecordBrowser $browser): JsonResponse
    {
        $browser->delete(str_contains($request->getPathInfo(), '/price-lists/') ? 'price_list' : 'promotion_rule', $id);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/price-lists', methods: ['POST'])]
    #[IsGranted('PRICING_WRITE')]
    public function createList(Request $request, PricingService $pricing): JsonResponse
    {
        return $this->json($pricing->priceList(Input::fromJson($request->getContent())), 201);
    }

    #[Route('/api/v1/admin/price-lists', methods: ['GET'])]
    #[IsGranted('PRICING_WRITE')]
    public function lists(Request $request, RecordBrowser $browser): JsonResponse
    {
        return $this->json($browser->page('price_list', $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }

    #[Route('/api/v1/admin/price-lists/{id}/prices', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('PRICING_WRITE')]
    public function prices(string $id, Request $request, PricingService $pricing): JsonResponse
    {
        return $this->json($pricing->prices($id, Input::fromJson($request->getContent())));
    }

    #[Route('/api/v1/admin/price-lists/{id}/prices', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('PRICING_WRITE')]
    public function listPrices(string $id, Request $request, PricingService $pricing): JsonResponse
    {
        return $this->json($pricing->listPrices($id, $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }

    #[Route('/api/v1/admin/promotion-rules', methods: ['POST'])]
    #[Route('/api/v1/admin/promotion-rules/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('PRICING_WRITE')]
    public function promotion(Request $request, PricingService $pricing, ?string $id = null): JsonResponse
    {
        return $this->json($pricing->promotion(Input::fromJson($request->getContent()), $id), null === $id ? 201 : 200);
    }

    #[Route('/api/v1/admin/promotion-rules', methods: ['GET'])]
    #[IsGranted('PRICING_WRITE')]
    public function rules(Request $request, RecordBrowser $browser): JsonResponse
    {
        return $this->json($browser->page('promotion_rule', $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }

    #[Route('/api/v1/pricing/calculate', methods: ['POST'])]
    #[Route('/api/v1/admin/promotion-rules/preview', methods: ['POST'])]
    public function calculate(Request $request, PricingService $pricing): JsonResponse
    {
        $preview = str_ends_with($request->getPathInfo(), '/preview');
        if ($preview) {
            $this->denyAccessUnlessGranted('PRICING_WRITE');
        }
        $user = $this->getUser();

        return $this->json($pricing->calculate(Input::fromJson($request->getContent()), $user instanceof User ? $user->customerGroup : null, $preview));
    }
}
