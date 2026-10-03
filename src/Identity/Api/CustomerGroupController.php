<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\Identity\Application\CustomerGroups;
use App\Shared\Application\Input;
use App\Shared\Application\RecordBrowser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class CustomerGroupController extends AbstractController
{
    #[Route('/api/v1/admin/customer-groups', methods: ['POST'])]
    public function create(Request $request, CustomerGroups $groups): JsonResponse
    {
        return $this->json($groups->create(Input::fromJson($request->getContent())), 201);
    }

    #[Route('/api/v1/admin/customer-groups', methods: ['GET'])]
    public function list(Request $request, RecordBrowser $browser): JsonResponse
    {
        return $this->json($browser->page('customer_group', $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }
}
