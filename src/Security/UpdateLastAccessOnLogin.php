<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

// Stamps the last successful interactive login. The user comes from the
// Doctrine entity provider, so it is already managed: flush() is enough.
#[AsEventListener]
final class UpdateLastAccessOnLogin
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->setLastAccessAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
