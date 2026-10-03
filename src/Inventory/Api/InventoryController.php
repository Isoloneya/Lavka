<?php

declare(strict_types=1);

namespace App\Inventory\Api;

use App\Inventory\Application\InventoryService;
use App\Shared\Application\Input;
use App\Shared\Application\RecordBrowser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('INVENTORY_WRITE')]
final class InventoryController extends AbstractController
{
    #[Route('/api/v1/admin/warehouses', methods: ['POST'])]
    public function createWarehouse(Request $request, InventoryService $inventory): JsonResponse
    {
        return $this->json($inventory->warehouse(Input::fromJson($request->getContent())), 201);
    }

    #[Route('/api/v1/admin/warehouses', methods: ['GET'])]
    public function warehouses(Request $request, RecordBrowser $browser): JsonResponse
    {
        return $this->json($browser->page('warehouse', $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }

    #[Route('/api/v1/admin/stock', methods: ['GET'])]
    public function stock(Request $request, RecordBrowser $browser): JsonResponse
    {
        $page = $browser->page('stock_item', $request->query->getInt('page', 1), $request->query->getInt('page_size', 20));
        foreach ($page->items as $item) {
            $item->available = $item->quantity - $item->reserved;
        }

        return $this->json($page);
    }

    #[Route('/api/v1/admin/stock/{variantId}', methods: ['PATCH'], requirements: ['variantId' => '[0-9a-fA-F-]{36}'])]
    public function adjust(string $variantId, Request $request, InventoryService $inventory): JsonResponse
    {
        return $this->json($inventory->adjust($variantId, Input::fromJson($request->getContent())));
    }
}
