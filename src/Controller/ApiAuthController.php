<?php

namespace App\Controller;

use App\Entity\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class ApiAuthController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Request body must be JSON'], Response::HTTP_BAD_REQUEST);
        }

        $email = $data['email'] ?? null;
        $plainPassword = $data['password'] ?? null;
        $nombre = $data['nombre'] ?? null;
        $activoRaw = $data['activo'] ?? null;

        if (!$email || !$plainPassword) {
            return new JsonResponse(['error' => 'Missing email or password'], Response::HTTP_BAD_REQUEST);
        }

        if ($activoRaw === null) {
            return new JsonResponse(['error' => 'Missing activo field'], Response::HTTP_BAD_REQUEST);
        }

        $activo = filter_var($activoRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($activo === null) {
            return new JsonResponse(['error' => 'Invalid activo value, must be boolean'], Response::HTTP_BAD_REQUEST);
        }

        $repo = $em->getRepository(Usuario::class);
        if ($repo->findOneBy(['email' => $email])) {
            return new JsonResponse(['error' => 'Email already used'], Response::HTTP_CONFLICT);
        }

        $user = new Usuario();
        $user->setEmail($email);
        $user->setNombre($nombre ?? '');
        $user->setActivo($activo);

        $hashed = $passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashed);

        $token = bin2hex(random_bytes(30));
        $user->setToken($token);

        $em->persist($user);
        $em->flush();

        return new JsonResponse([
            'message' => 'El usuario se ha registrado correctamente',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nombre' => $user->getNombre(),
                'activo' => $user->isActivo(),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Request body must be JSON'], Response::HTTP_BAD_REQUEST);
        }

        $email = $data['email'] ?? null;
        $plainPassword = $data['password'] ?? null;

        if (!$email || !$plainPassword) {
            return new JsonResponse(['error' => 'Missing email or password'], Response::HTTP_BAD_REQUEST);
        }

        $repo = $em->getRepository(Usuario::class);
        /** @var Usuario|null $user */
        $user = $repo->findOneBy(['email' => $email]);

        if (!$user) {
            return new JsonResponse(['error' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        // Verify password: use password_verify to be compatible with Symfony hashers
        if (!password_verify($plainPassword, $user->getPassword())) {
            return new JsonResponse(['error' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        // rotate token
        $token = bin2hex(random_bytes(30));
        $user->setToken($token);
        $em->persist($user);
        $em->flush();

        return new JsonResponse([
            'message' => 'Has iniciado sesión correctamente',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nombre' => $user->getNombre(),
            ],
        ], Response::HTTP_OK);
    }

    public function listUsers(EntityManagerInterface $em): JsonResponse
    {
        $repo = $em->getRepository(Usuario::class);
        $users = $repo->findAll();

        $data = array_map(function(Usuario $u) {
            return [
                'id' => $u->getId(),
                'email' => $u->getEmail(),
                'nombre' => $u->getNombre(),
                'activo' => $u->isActivo(),
            ];
        }, $users);

        return new JsonResponse(['users' => $data], Response::HTTP_OK);
    }

    public function home(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return new JsonResponse(['success' => false, 'message' => 'Missing or invalid Authorization header'], Response::HTTP_UNAUTHORIZED);
        }

        $token = substr($authHeader, 7);
        $user = $em->getRepository(\App\Entity\Usuario::class)->findOneBy(['token' => $token]);
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token'], Response::HTTP_UNAUTHORIZED);
        }

        // Build usuario data
        $usuario = [
            'nombre' => $user->getNombre(),
            'email' => $user->getEmail(),
            'latitud' => $user->getLatitud(),
            'longitud' => $user->getLongitud(),
        ];

        // Find active chats related to this user
        $chats = $em->getRepository(\App\Entity\Chat::class)->findAll();
        $chatsActivos = [];
        foreach ($chats as $chat) {
            if (!$chat->isActivo()) {
                continue;
            }
            $inv = $chat->getInvitacionChat();
            if (!$inv) continue;

            $related = false;
            if ($inv->getTokenUsuarioInvitador()->contains($user) || $inv->getTokenUsuarioInvitado()->contains($user)) {
                $related = true;
            }

            if ($related) {
                $chatsActivos[] = [
                    'tokenChat' => $chat->getId(),
                    'tipo' => $chat->getTipo(),
                    'fecha_entrada' => (new \DateTimeImmutable('now'))->format(DATE_ATOM),
                ];
            }
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Home cargado',
            'data' => [
                'usuario' => $usuario,
                'chats_activos' => $chatsActivos,
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/api/perfil', name: 'api_perfil', methods: ['GET'])]
    public function profile(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Accept tokenusuario as query param (?tokenusuario=...) or header (X-TOKEN-USUARIO or Authorization: Bearer ...)
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
            return new JsonResponse(['error' => 'Missing tokenusuario'], Response::HTTP_BAD_REQUEST);
        }

        $user = $em->getRepository(Usuario::class)->findOneBy(['token' => $tokenUsuario]);
        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $data = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'nombre' => $user->getNombre(),
            'latitud' => $user->getLatitud(),
            'longitud' => $user->getLongitud(),
            'activo' => $user->isActivo(),
            'baneado' => $user->isBaneado(),
            'token' => $user->getToken(),
            'roles' => $user->getRoles(),
        ];

        return new JsonResponse(['user' => $data], Response::HTTP_OK);
    }
}
