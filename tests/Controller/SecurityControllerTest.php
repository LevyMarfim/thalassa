<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsPublic(): void
    {
        $crawler = static::createClient()->request('GET', '/login');

        self::assertResponseIsSuccessful();
        // The Stimulus csrf-protection controller must bind to the token input,
        // otherwise the raw "csrf-token" placeholder is submitted and rejected.
        self::assertCount(1, $crawler->filter('form input[name="_csrf_token"][data-controller="csrf-protection"]'));
    }

    public function testAnonymousLogoutRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/logout');

        self::assertResponseRedirects('/login');
    }

    public function testLoginAndLogoutCycle(): void
    {
        $email = sprintf('login-%s@example.com', uniqid());
        $username = sprintf('login-%s', uniqid());
        $plainPassword = 'very-long-test-password-2';

        $client = static::createClient();
        $this->createUser($email, $username, $plainPassword);

        $crawler = $client->request('GET', '/login');
        $form = $crawler->filter('form')->form([
            '_username' => $username,
            '_password' => $plainPassword,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/');
        self::assertNotNull(
            static::getContainer()->get(TokenStorageInterface::class)->getToken()
        );

        $user = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getLastAccessAt());
        self::assertTrue($user->isActive());

        $client->request('GET', '/logout');

        self::assertResponseRedirects();
        self::assertNull(
            static::getContainer()->get(TokenStorageInterface::class)->getToken()
        );
    }

    public function testLoginRejectsBadCredentials(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $form = $crawler->filter('form')->form([
            '_username' => 'unknown-user',
            '_password' => 'wrong-password-123',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertNull(
            static::getContainer()->get(TokenStorageInterface::class)->getToken()
        );
    }

    private function createUser(string $email, string $username, string $plainPassword): void
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword(
            $container->get(UserPasswordHasherInterface::class)->hashPassword($user, $plainPassword)
        );

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();
    }
}
