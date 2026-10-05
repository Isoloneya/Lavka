<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class StaffVoter implements VoterInterface
{
    public function vote(TokenInterface $token, mixed $subject, array $attributes): int
    {
        foreach ($attributes as $attribute) {
            if (!in_array($attribute, ['CATALOG_WRITE', 'CATALOG_ARCHIVE', 'PRICING_WRITE', 'INVENTORY_WRITE'], true)) {
                continue;
            }
            $user = $token->getUser();
            if (!$user instanceof User) {
                return self::ACCESS_DENIED;
            }
            $allowed = 'CATALOG_ARCHIVE' === $attribute ? ['ROLE_ADMIN'] : ['ROLE_MANAGER', 'ROLE_ADMIN'];

            return in_array($user->role, $allowed, true) ? self::ACCESS_GRANTED : self::ACCESS_DENIED;
        }

        return self::ACCESS_ABSTAIN;
    }
}
