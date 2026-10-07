<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use App\Identity\Application\RegisterUser;
use App\Identity\Infrastructure\Security\User;
use App\Order\Application\OrderService;
use App\Shared\Application\ApiProblem;
use App\Shared\Application\Input;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class AccountController extends AbstractController
{
    #[Route('/login', name: 'storefront_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authentication, ShoppingSession $shopping): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('storefront_account');
        }

        return $this->render('storefront/auth.html.twig', ['register' => false, 'error' => null !== $authentication->getLastAuthenticationError() ? 'Не вдалося увійти. Перевірте дані або спробуйте пізніше.' : null, 'email' => $request->getSession()->get('login_email', ''), 'csrf' => $shopping->csrf($request)]);
    }

    #[Route('/register', name: 'storefront_register', methods: ['GET', 'POST'])]
    public function register(Request $request, RegisterUser $register, ShoppingSession $shopping, RateLimiterFactoryInterface $registrationLimiter): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('storefront_account');
        }
        $error = null;
        $status = 200;
        $email = '';
        if ($request->isMethod('POST')) {
            $shopping->validate($request);
            $email = $request->request->getString('email');
            try {
                if (!$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                    throw new ApiProblem(429, 'RATE_LIMITED', 'Забагато спроб. Спробуйте пізніше.');
                }
                $register->register(new Input((object) ['email' => $email, 'password' => $request->request->getString('password')]));
                $request->getSession()->set('login_email', strtolower(trim($email)));
                $this->addFlash('success', 'Обліковий запис створено. Увійдіть зі своїм паролем.');

                return $this->redirectToRoute('storefront_login', [], 303);
            } catch (ApiProblem $problem) {
                $error = $problem->getMessage();
                $status = $problem->status;
            } catch (UniqueConstraintViolationException) {
                $error = 'Не вдалося створити обліковий запис із цими даними. Спробуйте увійти.';
                $status = 409;
            }
        }

        return $this->render('storefront/auth.html.twig', ['register' => true, 'error' => $error, 'email' => $email, 'csrf' => $shopping->csrf($request)], new Response(status: $status));
    }

    #[Route('/account', name: 'storefront_account', methods: ['GET'])]
    public function account(Request $request, OrderService $orders, ShoppingSession $shopping): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > 100000) {
            throw new BadRequestHttpException();
        }

        return $this->render('storefront/account.html.twig', ['customer' => $user, 'orders' => $orders->listing($user->id, false, $page, 10), 'csrf' => $shopping->csrf($request)]);
    }

    #[Route('/logout', name: 'storefront_logout', methods: ['POST'])]
    public function logout(Request $request, ShoppingSession $shopping, TokenStorageInterface $tokens): Response
    {
        $shopping->validate($request);
        $tokens->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('storefront_home', [], 303);
    }
}
