<?php
declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Contract\TenantScopedInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class TenantPermissionVoter extends Voter
{
    public function __construct(private readonly TenantContextProviderInterface $context)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, TenantPermissions::all(), true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $context = $this->context->current();
        if ($context === null) {
            return false;
        }

        $organizationId = $this->subjectOrganizationId($subject);
        if ($subject !== null && $organizationId === null) {
            return false;
        }

        if ($organizationId !== null && !$context->belongsTo($organizationId)) {
            return false;
        }

        return $context->allows($attribute);
    }

    private function subjectOrganizationId(mixed $subject): ?OrganizationId
    {
        if ($subject === null) {
            return null;
        }

        if ($subject instanceof OrganizationId) {
            return $subject;
        }

        if ($subject instanceof TenantScopedInterface) {
            return $subject->organizationId();
        }

        if (!is_string($subject)) {
            return null;
        }

        try {
            return OrganizationId::fromString($subject);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
