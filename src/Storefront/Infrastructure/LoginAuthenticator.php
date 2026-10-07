<?php

declare(strict_types=1);

namespace App\Storefront\Infrastructure;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    protected function getLoginUrl(Request $request): string
    {
        return '/login';
    }

    public function authenticate(Request $request): Passport
    {
        $csrf = $request->getSession()->get('shopping_csrf');
        if (!is_string($csrf) || !hash_equals($csrf, $request->request->getString('_token'))) {
            throw new CustomUserMessageAuthenticationException('Оновіть сторінку та спробуйте ще раз.');
        }
        $email = strtolower(trim($request->request->getString('email')));
        $request->getSession()->set('login_email', $email);

        return new Passport(new UserBadge($email), new PasswordCredentials($request->request->getString('password')));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $request->getSession()->set('shopping_csrf', bin2hex(random_bytes(32)));

        return new RedirectResponse('/account', 303);
    }
}
