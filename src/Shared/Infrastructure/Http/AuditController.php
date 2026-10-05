<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\RecordBrowser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AuditController extends AbstractController
{
    #[Route('/api/v1/admin/audit-log', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request, RecordBrowser $browser): JsonResponse
    {
        return $this->json($browser->page('audit_log', $request->query->getInt('page', 1), $request->query->getInt('page_size', 20)));
    }
}
