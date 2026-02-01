<?php

namespace App\Controller;

use App\Entity\Usuario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;
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

        // If user is inactive, activate them
        if (!$user->isActivo()) {
            $user->setActivo(true);
        }

        // Update geolocation if provided
        $latitud = $data['latitud'] ?? null;
        $longitud = $data['longitud'] ?? null;
        if ($latitud !== null && is_numeric($latitud)) {
            $user->setLatitud((float)$latitud);
        }
        if ($longitud !== null && is_numeric($longitud)) {
            $user->setLongitud((float)$longitud);
        }

        $em->persist($user);
        $em->flush();

        // create response and set AUTH_TOKEN cookie (HttpOnly, SameSite=Lax)
        $response = new JsonResponse([
            'message' => 'Has iniciado sesión correctamente',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'nombre' => $user->getNombre(),
                'activo' => $user->isActivo(),
                'latitud' => $user->getLatitud(),
                'longitud' => $user->getLongitud(),
            ],
        ], Response::HTTP_OK);

        // cookie expiry: 30 days
        $expires = new \DateTimeImmutable('+30 days');
        $cookie = new Cookie('AUTH_TOKEN', $token, $expires, '/', null, $request->isSecure(), true, false, 'lax');
        $response->headers->setCookie($cookie);

        return $response;
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $token = null;

        // 1) Authorization header: Bearer <token>
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        // 2) X-TOKEN-USUARIO header
        if (!$token) {
            $tokenHeader = $request->headers->get('X-TOKEN-USUARIO');
            if ($tokenHeader) {
                $token = $tokenHeader;
            }
        }

        // 3) query param ?tokenusuario=...
        if (!$token) {
            $tokenQuery = $request->query->get('tokenusuario');
            if ($tokenQuery) {
                $token = $tokenQuery;
            }
        }

        // 4) body JSON { "token": "..." } or { "tokenusuario": "..." }
        if (!$token) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data)) {
                if (!empty($data['token'])) {
                    $token = $data['token'];
                } elseif (!empty($data['tokenusuario'])) {
                    $token = $data['tokenusuario'];
                }
            }
        }

        // 5) cookie AUTH_TOKEN (final fallback)
        if (!$token) {
            $cookieToken = $request->cookies->get('AUTH_TOKEN');
            if ($cookieToken) {
                $token = $cookieToken;
            }
        }

        if (!$token) {
            return new JsonResponse(['success' => false, 'message' => 'Missing or invalid token'], Response::HTTP_UNAUTHORIZED);
        }

        $user = $em->getRepository(Usuario::class)->findOneBy(['token' => $token]);
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token'], Response::HTTP_UNAUTHORIZED);
        }

        // mark inactive and invalidate token
        $user->setActivo(false);
        $user->setToken('');

        $em->persist($user);
        $em->flush();

        // clear cookie by setting expiry in the past
        $expired = new \DateTimeImmutable('-1 day');
        $clear = new Cookie('AUTH_TOKEN', '', $expired, '/', null, false, true, false, 'lax');

        $response = new JsonResponse(['success' => true, 'message' => 'Logout successful'], Response::HTTP_OK);
        $response->headers->setCookie($clear);

        return $response;
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
