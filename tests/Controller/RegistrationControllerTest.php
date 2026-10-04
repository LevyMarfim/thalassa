<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegisterPageIsPublic(): void
    {
        static::createClient()->request('GET', '/register');

        self::assertResponseIsSuccessful();
    }

    public function testRegisterCreatesUserWithHashedPassword(): void
    {
        $client = static::createClient();
        $email = sprintf('register-%s@example.com', uniqid());
        $username = sprintf('register-%s', uniqid());
        $plainPassword = 'very-long-test-password-1';

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form[name="registration_form"]')->form([
            'registration_form[email]' => $email,
            'registration_form[username]' => $username,
            'registration_form[plainPassword]' => $plainPassword,
            'registration_form[agreeTerms]' => '1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/login');

        $container = static::getContainer();
        $user = $container->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        self::assertInstanceOf(User::class, $user);
        self::assertSame($username, $user->getUsername());
        self::assertSame($username, $user->getUserIdentifier());
        self::assertNotSame($plainPassword, $user->getPassword());
        self::assertTrue(
            $container->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $plainPassword)
        );
    }

    public function testRegisterRejectsShortPassword(): void
    {
        $client = static::createClient();
        $email = sprintf('short-%s@example.com', uniqid());

        $crawler = $client->request('GET', '/register');
        $form = $crawler->filter('form[name="registration_form"]')->form([
            'registration_form[email]' => $email,
            'registration_form[username]' => sprintf('short-%s', uniqid()),
            'registration_form[plainPassword]' => 'abc',
            'registration_form[agreeTerms]' => '1',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertNull(
            static::getContainer()->get(EntityManagerInterface::class)
                ->getRepository(User::class)
                ->findOneBy(['email' => $email])
        );
    }
}
