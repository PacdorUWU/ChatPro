<?php

namespace App\Controller;

use App\Entity\Usuario;
use App\Entity\Chat;
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

        // Update geolocation if provided in request body
        $latitud = $data['latitud'] ?? null;
        $longitud = $data['longitud'] ?? null;
        if ($latitud !== null && is_numeric($latitud)) {
            $user->setLatitud((float)$latitud);
        }
        if ($longitud !== null && is_numeric($longitud)) {
            $user->setLongitud((float)$longitud);
        }

        // Fallback: try to detect geolocation from client IP if lat/long still missing
        if ($user->getLatitud() === null || $user->getLongitud() === null) {
            $clientIp = $request->getClientIp();
            if ($clientIp) {
                // Use a simple public IP geolocation service (ip-api.com). If unavailable, skip gracefully.
                $geoJson = @file_get_contents("http://ip-api.com/json/{$clientIp}?fields=status,message,lat,lon");
                if ($geoJson !== false) {
                    $geo = json_decode($geoJson, true);
                    if (!empty($geo) && isset($geo['status']) && $geo['status'] === 'success') {
                        if ($user->getLatitud() === null && isset($geo['lat'])) {
                            $user->setLatitud((float)$geo['lat']);
                        }
                        if ($user->getLongitud() === null && isset($geo['lon'])) {
                            $user->setLongitud((float)$geo['lon']);
                        }
                    }
                }
            }
        }

        $em->persist($user);
        $em->flush();

        // create response and set AUTH_TOKEN cookie (HttpOnly, SameSite=Lax)
        $response = new JsonResponse([
            'message' => 'Se ha podido iniciar sesión',
            'token' => $token,
            'user' => [
                'nombre' => $user->getNombre(),
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

    #[Route('/api/home', name: 'api_home', methods: ['GET'])]
    public function home(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // token extraction (accepts ?tokenusuario=, X-TOKEN-USUARIO, Authorization: Bearer ...)
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
            return new JsonResponse(['success' => false, 'message' => 'Missing tokenusuario'], Response::HTTP_BAD_REQUEST);
        }

        $user = $em->getRepository(Usuario::class)->findOneBy(['token' => $tokenUsuario]);
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // brief profile
        $usuario = [
            'nombre' => $user->getNombre(),
            'email' => $user->getEmail(),
            'latitud' => $user->getLatitud(),
            'longitud' => $user->getLongitud(),
        ];

        // collect chats related to user (invitacionChat / invitacionesChat) and fallback to all active chats
        $chatsCollection = [];
        $invitacion = $user->getInvitacionChat();
        if ($invitacion) {
            foreach ($invitacion->getTokenChat() as $chat) {
                if ($chat->isActivo()) {
                    $chatsCollection[$chat->getId()] = $chat;
                }
            }
        }
        $invitaciones = $user->getInvitacionesChat();
        if ($invitaciones) {
            foreach ($invitaciones->getTokenChat() as $chat) {
                if ($chat->isActivo()) {
                    $chatsCollection[$chat->getId()] = $chat;
                }
            }
        }

        if (empty($chatsCollection)) {
            $repo = $em->getRepository(Chat::class);
            $activeChats = $repo->findBy(['activo' => true]);
            foreach ($activeChats as $chat) {
                $chatsCollection[$chat->getId()] = $chat;
            }
        }

        $resultChats = [];
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($chatsCollection as $chat) {
            $resultChats[] = [
                'tokenChat' => $chat->getId(),
                'tipo' => $chat->getTipo(),
                'fecha_entrada' => $nowUtc->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        $dataOut = [
            'usuario' => $usuario,
            'chats_activos' => array_values($resultChats),
        ];

        return new JsonResponse(['success' => true, 'message' => 'Home cargado', 'data' => $dataOut], Response::HTTP_OK);
    }

    #[Route('/api/chat/privado', name: 'api_chat_privado', methods: ['GET'])]
    public function chatPrivado(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // token extraction
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
            return new JsonResponse(['success' => false, 'message' => 'Missing tokenusuario'], Response::HTTP_BAD_REQUEST);
        }

        $user = $em->getRepository(Usuario::class)->findOneBy(['token' => $tokenUsuario]);
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $chats = [];

        // collect from invitacionChat (user as invitador)
        $invitacion = $user->getInvitacionChat();
        if ($invitacion) {
            foreach ($invitacion->getTokenChat() as $chat) {
                if ($chat->isActivo() && strtolower($chat->getTipo()) === 'privado') {
                    $chats[$chat->getToken()] = $chat;
                }
            }
        }

        // collect from invitacionesChat (user as invitado)
        $invitaciones = $user->getInvitacionesChat();
        if ($invitaciones) {
            foreach ($invitaciones->getTokenChat() as $chat) {
                if ($chat->isActivo() && strtolower($chat->getTipo()) === 'privado') {
                    $chats[$chat->getToken()] = $chat;
                }
            }
        }

        // build response
        $data = [];
        foreach ($chats as $chat) {
            $inv = $chat->getInvitacionChat();
            $participants = [];
            if ($inv) {
                foreach ($inv->getTokenUsuarioInvitador() as $u) {
                    $participants[] = ['token' => $u->getToken(), 'nombre' => $u->getNombre()];
                }
                foreach ($inv->getTokenUsuarioInvitado() as $u) {
                    $participants[] = ['token' => $u->getToken(), 'nombre' => $u->getNombre()];
                }

                // deduplicate by token
                $seen = [];
                $participants = array_values(array_filter(array_map(function($p) use (&$seen) {
                    if (empty($p['token']) || isset($seen[$p['token']])) return null;
                    $seen[$p['token']] = true;
                    return $p;
                }, $participants)));
            }

            $data[] = [
                'tokenChat' => $chat->getToken(),
                'tipo' => $chat->getTipo(),
                'participantes' => $participants,
            ];
        }

        return new JsonResponse(['success' => true, 'message' => 'Chats privados listados', 'data' => $data], Response::HTTP_OK);
    }
}
