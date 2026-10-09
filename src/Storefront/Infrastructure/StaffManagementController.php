<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use App\Identity\Application\CustomerGroups;
use App\Identity\Infrastructure\Persistence\UserRepository;
use App\Identity\Infrastructure\Security\User;
use App\Pricing\Application\PricingService;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use App\Shared\Application\RecordBrowser;
use App\Shared\Domain\Exception\DomainError;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
final class StaffManagementController extends AbstractController
{
    public function __construct(private readonly RecordBrowser $records, private readonly ShoppingSession $shopping, private readonly StaffAudit $audit)
    {
    }

    #[Route('/admin/promotions', name: 'staff_promotions', methods: ['GET', 'POST'])]
    #[IsGranted('PRICING_WRITE')]
    public function promotions(Request $request, PricingService $pricing): Response
    {
        $record = null;
        $id = $request->query->getString('edit');
        if ('' !== $id) {
            try {
                $record = $this->records->find('promotion_rule', $id);
            } catch (ApiProblem) {
                throw $this->createNotFoundException();
            }
        }
        $error = null;
        $preview = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $this->shopping->validate($request);
            try {
                if ('check' === $request->request->getString('operation')) {
                    $coupon = trim($request->request->getString('check_coupon'));
                    $preview = $pricing->calculate(new Input((object) ['currency' => 'UAH', 'coupon_code' => '' === $coupon ? null : $coupon, 'items' => [(object) ['sku' => $request->request->getString('check_sku'), 'quantity' => $request->request->getInt('check_quantity', 1)]]]), null);
                } else {
                    $data = (object) [
                        'name' => $request->request->getString('name'), 'scope' => $request->request->getString('scope'), 'priority' => $request->request->getInt('priority'),
                        'is_active' => $request->request->getBoolean('is_active'), 'stop_processing' => $request->request->getBoolean('stop_processing'),
                        'coupon_code' => '' === trim($request->request->getString('coupon_code')) ? null : trim($request->request->getString('coupon_code')),
                        'conditions' => json_decode($request->request->getString('conditions'), false, 32, JSON_THROW_ON_ERROR),
                        'actions' => json_decode($request->request->getString('actions'), false, 32, JSON_THROW_ON_ERROR),
                        'valid_from' => $this->date($request->request->getString('valid_from')), 'valid_to' => $this->date($request->request->getString('valid_to')),
                    ];
                    $this->audit->write($this->actor()->id, $request->getPathInfo(), (object) ['id' => $id, 'rule' => $data], fn () => $pricing->promotion(new Input($data), '' === $id ? null : $id));
                    $this->addFlash('success', 'Акцію збережено.');

                    return $this->redirectToRoute('staff_promotions', [], 303);
                }
            } catch (ApiProblem|DomainError|\JsonException|UniqueConstraintViolationException $problem) {
                $error = match (true) {
                    $problem instanceof \JsonException => 'Перевірте JSON умов і дій.',
                    $problem instanceof UniqueConstraintViolationException => 'Такий код купона вже використовується.',
                    default => $problem->getMessage(),
                };
                $status = 422;
            }
        }

        return $this->render('staff/promotions.html.twig', ['rows' => $this->records->page('promotion_rule', $this->page($request), 20), 'record' => $record, 'values' => $request->request->all(), 'error' => $error, 'preview' => $preview, 'csrf' => $this->shopping->csrf($request)], new Response(status: $status));
    }

    #[Route('/admin/groups', name: 'staff_groups', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function groups(Request $request, CustomerGroups $groups): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->shopping->validate($request);
            $data = (object) ['name' => $request->request->getString('name'), 'code' => $request->request->getString('code')];
            try {
                $this->audit->write($this->actor()->id, $request->getPathInfo(), $data, fn () => $groups->create(new Input($data)));

                return $this->redirectToRoute('staff_groups', [], 303);
            } catch (ApiProblem|UniqueConstraintViolationException $problem) {
                $error = $problem instanceof ApiProblem ? $problem->getMessage() : 'Група з таким кодом уже існує.';
            }
        }

        return $this->render('staff/groups.html.twig', ['rows' => $this->records->page('customer_group', $this->page($request), 20), 'values' => $request->request->all(), 'error' => $error, 'csrf' => $this->shopping->csrf($request)], new Response(status: null === $error ? 200 : 422));
    }

    #[Route('/admin/users', name: 'staff_users', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function users(Request $request, UserRepository $users, CustomerGroups $groups): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->shopping->validate($request);
            try {
                $id = $request->request->getString('id');
                $role = (new Input((object) ['role' => $request->request->getString('role')]))->choice('role', 'ROLE_CUSTOMER', 'ROLE_MANAGER', 'ROLE_ADMIN');
                $group = trim($request->request->getString('customer_group'));
                if ('' !== $group) {
                    $groups->requireCode($group);
                }
                if ($id === $this->actor()->id && 'ROLE_ADMIN' !== $role) {
                    throw new ApiProblem(422, 'SELF_ROLE_CHANGE', 'Власну роль адміністратора змінює інший адміністратор.');
                }
                $target = $users->find($id);
                $this->audit->write($this->actor()->id, $request->getPathInfo(), (object) ['id' => $id, 'role' => $role, 'customer_group' => $group], function () use ($target, $role, $group, $users): void {
                    $target->role = $role;
                    $target->customerGroup = '' === $group ? null : $group;
                    $users->save($target);
                });
                $this->addFlash('success', 'Користувача оновлено.');

                return $this->redirectToRoute('staff_users', ['page' => $this->page($request)], 303);
            } catch (ApiProblem $problem) {
                $error = $problem->getMessage();
            }
        }
        $allGroups = [];
        $page = 1;
        do {
            $batch = $this->records->page('customer_group', $page++, 100);
            array_push($allGroups, ...$batch->items);
        } while ([] !== $batch->items && count($allGroups) < $batch->total);

        return $this->render('staff/users.html.twig', ['rows' => $users->page($this->page($request), 20), 'groups' => $allGroups, 'error' => $error, 'csrf' => $this->shopping->csrf($request)], new Response(status: null === $error ? 200 : 422));
    }

    #[Route('/admin/audit', name: 'staff_audit', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function audit(Request $request): Response
    {
        return $this->render('staff/audit.html.twig', ['rows' => $this->records->page('audit_log', $this->page($request), 20)]);
    }

    private function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function page(Request $request): int
    {
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > 100000) {
            throw new BadRequestHttpException();
        }

        return $page;
    }

    private function date(string $value): ?string
    {
        if ('' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('Europe/Kyiv'));
        if (false === $date || $date->format('Y-m-d\TH:i') !== $value) {
            throw new ApiProblem(422, 'INVALID_DATE', 'Некоректна дата.');
        }

        return $date->format(\DateTimeInterface::ATOM);
    }
}
