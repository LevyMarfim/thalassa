<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HomeControllerTest extends WebTestCase
{
    public function testAnonymousHomeRedirectsToLogin(): void
    {
        static::createClient()->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedHomeShowsUser(): void
    {
        $client = static::createClient();
        $username = sprintf('home-%s', uniqid());
        $client->loginUser($this->createUser($username));

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $username);
    }

    private function createUser(string $username): User
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail(sprintf('%s@example.com', $username));
        $user->setUsername($username);
        $user->setPassword(
            $container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password-123')
        );

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();

        return $user;
    }
}
