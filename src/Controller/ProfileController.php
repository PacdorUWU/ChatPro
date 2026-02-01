<?php

namespace App\Controller;

use App\Entity\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig', [
            'controller_name' => 'ProfileController',
        ]);
    }

    #[Route('/profile/update', name: 'app_profile_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $nombre = $request->request->get('nombre');
        $email = $request->request->get('email');

        $user->setNombre($nombre);
        $user->setEmail($email);

        $em->flush();

        $this->addFlash('success', 'Perfil actualizado correctamente.');

        return $this->redirectToRoute('app_profile');
    }
}