<?php

namespace App\Controller;

use App\Entity\Usuario;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, Security $security, EntityManagerInterface $entityManager): Response
    {
        $user = new Usuario();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var string $plainPassword */
                $plainPassword = $form->get('plainPassword')->getData();

                // encode the plain password
                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
                $user->setRoles(['ROLE_USER']);
                $user->setActivo(true);

                $entityManager->persist($user);
                $entityManager->flush();

                // do anything else you need here, like send an email

                $this->addFlash('success', 'Cuenta creada exitosamente. Bienvenido!');

                return $security->login($user, 'form_login', 'main');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error al registrar: ' . $e->getMessage());
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
