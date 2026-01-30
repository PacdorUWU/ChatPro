<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Entity\Usuario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;


class NuevoUsuario extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;
    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        $nombre = 'Prueba';
        $email = 'ejemplo@ejemplo.com';
        $plainPassword = '12345678';
        $user = new Usuario();
        $user->setNombre($nombre);
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN' ]);
        // Encriptación de la contraseña
        $hashed = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashed);
        $user->setToken(bin2hex(random_bytes(32)));
        // Guarda el registro en la base de datos.
        $manager->persist($user);
        $manager->flush();
    }
}
