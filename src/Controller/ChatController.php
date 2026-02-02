<?php

namespace App\Controller;

use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ChatController extends AbstractController
{
    public function general(Request $request, ChatRepository $chatRepository, EntityManagerInterface $em): JsonResponse
    {
        // Accept tokenusuario as query param or header
        $tokenUsuario = $request->query->get('tokenusuario');
        if (!$tokenUsuario) {
            $authHeader = $request->headers->get('X-TOKEN-USUARIO') ?? $request->headers->get('Authorization');
            if ($authHeader) {
                if (str_starts_with($authHeader, 'Bearer ')) {
                    $tokenUsuario = substr($authHeader, 7);
                } else {
                    $tokenUsuario = $authHeader;
                }
            }
        }

        if (!$tokenUsuario) {
            return new JsonResponse(['error' => 'Missing tokenusuario'], 400);
        }

        $user = $em->getRepository(\App\Entity\Usuario::class)->findOneBy(['token' => $tokenUsuario]);
        if (!$user) {
            return new JsonResponse(['error' => 'Invalid token'], 401);
        }

        $chats = $chatRepository->findBy(['tipo' => 'Publico']);

        $data = [];
        foreach ($chats as $chat) {
            $data[] = [
                'token' => $chat->getToken(),
                'tipo' => $chat->getTipo(),
                'fecha_creacion' => $chat->getCreatedAt() ? $chat->getCreatedAt()->format('Y-m-d') : null,
                'activo' => $chat->isActivo(),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Chats públicos listados',
            'data' => $data,
        ]);
    }
}