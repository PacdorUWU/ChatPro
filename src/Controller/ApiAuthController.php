<?php

namespace App\Controller;

use App\Entity\Usuario;
use App\Entity\Chat;
use App\Entity\InvitacionChat;
use App\Entity\Mensajes;
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

    #[Route('/api/chat/general', name: 'api_chat_general', methods: ['GET'])]
    public function chatGeneral(Request $request, EntityManagerInterface $em): JsonResponse
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

        try {
            $repo = $em->getRepository(Chat::class);
            $chats = $repo->findBy(['tipo' => 'Publico']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => 'Database connection error'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $data = [];
        foreach ($chats as $chat) {
            $data[] = [
                'token' => $chat->getToken(),
                'tipo' => $chat->getTipo(),
                'fecha_creacion' => null,
                'activo' => $chat->isActivo(),
            ];
        }

        return new JsonResponse(['success' => true, 'message' => 'Chats públicos listados', 'data' => $data], Response::HTTP_OK);
    }

    #[Route('/api/chat/invitar', name: 'api_chat_invitar', methods: ['POST'])]
    public function chatInvitar(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Extract inviter token (same logic used elsewhere)
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

        $inviter = $em->getRepository(Usuario::class)->findOneBy(['token' => $tokenUsuario]);
        if (!$inviter) {
            return new JsonResponse(['success' => false, 'message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $rawContent = $request->getContent();
        $data = json_decode($rawContent, true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'message' => 'Request body must be JSON'], Response::HTTP_BAD_REQUEST);
        }

        $tokenChat = $data['tokenchat'] ?? null;
        $tokenTarget = $data['tokenUsuario'] ?? null;

        if (!$tokenChat || !$tokenTarget) {
            return new JsonResponse(['success' => false, 'message' => 'Missing tokenchat or tokenUsuario'], Response::HTTP_BAD_REQUEST);
        }

        // find target user
        $target = $em->getRepository(Usuario::class)->findOneBy(['token' => $tokenTarget]);
        if (!$target) {
            return new JsonResponse(['success' => false, 'error' => '404', 'message' => 'Usuario no encontrado'], Response::HTTP_NOT_FOUND);
        }

        // target must be online/active
        if (!$target->isActivo()) {
            return new JsonResponse(['success' => false, 'message' => 'Usuario no disponible'], Response::HTTP_BAD_REQUEST);
        }

        // Try to find existing chat
        $chat = $em->getRepository(Chat::class)->findOneBy(['token' => $tokenChat]);

        if ($chat) {
            // only allow inviting to private chats
            if (strtolower($chat->getTipo()) !== 'privado') {
                return new JsonResponse(['success' => false, 'message' => 'Solo se pueden invitar a chats privados'], Response::HTTP_BAD_REQUEST);
            }
            $invitacion = $chat->getInvitacionChat();
            if (!$invitacion) {
                // create an invitation and attach it
                $invitacion = new InvitacionChat();
                $invitacion->setToken(bin2hex(random_bytes(16)));
                $invitacion->setEstado('aceptada');
                $em->persist($invitacion);
                $chat->setInvitacionChat($invitacion);
            }
        } else {
            // create invitation and chat
            $invitacion = new InvitacionChat();
            $invitacion->setToken(bin2hex(random_bytes(16)));
            $invitacion->setEstado('aceptada');
            $em->persist($invitacion);

            $chat = new Chat();
            $chat->setTipo('Privado');
            $chat->setActivo(true);
            $chat->setToken($tokenChat);
            $chat->setInvitacionChat($invitacion);
            $em->persist($chat);
        }

        // add inviter and target to invitation participants
        $invitacion->addTokenUsuarioInvitador($inviter);
        $invitacion->addTokenUsuarioInvitado($target);

        $em->persist($invitacion);
        $em->flush();

        // cleanup: delete any private chats that have no participants
        $repo = $em->getRepository(Chat::class);
        $privateChats = $repo->findBy(['tipo' => 'Privado']);
        foreach ($privateChats as $pc) {
            $inv = $pc->getInvitacionChat();
            $countInvitadores = $inv ? count($inv->getTokenUsuarioInvitador()) : 0;
            $countInvitados = $inv ? count($inv->getTokenUsuarioInvitado()) : 0;
            if ($countInvitadores === 0 && $countInvitados === 0) {
                if ($inv) {
                    $em->remove($inv);
                }
                $em->remove($pc);
            }
        }
        $em->flush();

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return new JsonResponse(['success' => true, 'message' => 'Usuario invitado al chat', 'data' => ['fecha_entrada' => $nowUtc->format('Y-m-d\\TH:i:s\\Z')]], Response::HTTP_OK);
    }

    #[Route('/api/chat/privado/salir', name: 'api_chat_privado_salir', methods: ['POST'])]
    public function chatPrivadoSalir(Request $request, EntityManagerInterface $em): JsonResponse
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

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'message' => 'Request body must be JSON'], Response::HTTP_BAD_REQUEST);
        }

        $tokenChat = $data['tokenchat'] ?? null;
        if (!$tokenChat) {
            return new JsonResponse(['success' => false, 'message' => 'Missing tokenchat'], Response::HTTP_BAD_REQUEST);
        }

        $chat = $em->getRepository(Chat::class)->findOneBy(['token' => $tokenChat]);
        if (!$chat) {
            return new JsonResponse(['success' => false, 'message' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        if (strtolower($chat->getTipo()) !== 'privado') {
            return new JsonResponse(['success' => false, 'message' => 'Solo se puede salir de chats privados'], Response::HTTP_BAD_REQUEST);
        }

        $invitacion = $chat->getInvitacionChat();
        if (!$invitacion) {
            // no invitation: remove the chat and return success
            $em->remove($chat);
            $em->flush();
            return new JsonResponse(['success' => true, 'message' => 'Saliste del chat', 'data' => null], Response::HTTP_OK);
        }

        // Remove user if present in either invitador or invitado lists
        $invitacion->removeTokenUsuarioInvitador($user);
        $invitacion->removeTokenUsuarioInvitado($user);

        $em->persist($invitacion);
        $em->flush();

        // If no participants left, remove invitation and chat
        $countInvitadores = count($invitacion->getTokenUsuarioInvitador());
        $countInvitados = count($invitacion->getTokenUsuarioInvitado());
        if ($countInvitadores === 0 && $countInvitados === 0) {
            $em->remove($invitacion);
            $em->remove($chat);
            $em->flush();
        }

        return new JsonResponse(['success' => true, 'message' => 'Saliste del chat', 'data' => null], Response::HTTP_OK);
    }

    #[Route('/api/chat/privado/cambiar', name: 'api_chat_privado_cambiar', methods: ['POST'])]
    public function chatPrivadoCambiar(Request $request, EntityManagerInterface $em): JsonResponse
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

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'message' => 'Request body must be JSON'], Response::HTTP_BAD_REQUEST);
        }

        $tokenChat = $data['tokenchat'] ?? null;
        if (!$tokenChat) {
            return new JsonResponse(['success' => false, 'message' => 'Missing tokenchat'], Response::HTTP_BAD_REQUEST);
        }

        // determine desired active value (must be boolean)
        if (!array_key_exists('activo', $data)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing activo'], Response::HTTP_BAD_REQUEST);
        }
        $activo = filter_var($data['activo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($activo === null) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid activo value, must be boolean'], Response::HTTP_BAD_REQUEST);
        }

        $repo = $em->getRepository(Chat::class);
        $chat = $repo->findOneBy(['token' => $tokenChat]);

        if ($chat) {
            if (strtolower($chat->getTipo()) !== 'privado') {
                return new JsonResponse(['success' => false, 'message' => 'Solo se pueden cambiar chats privados'], Response::HTTP_BAD_REQUEST);
            }
        } else {
            // create chat + invitation if activating
            if ($activo) {
                $invitacion = new InvitacionChat();
                $invitacion->setToken(bin2hex(random_bytes(16)));
                $invitacion->setEstado('aceptada');
                $em->persist($invitacion);
                $chat = new Chat();
                $chat->setTipo('Privado');
                $chat->setActivo(true);
                $chat->setToken($tokenChat);
                $chat->setInvitacionChat($invitacion);
                $em->persist($chat);
            } else {
                return new JsonResponse(['success' => false, 'message' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
        }

        if ($activo) {
            // Leaving any other private chats the user is currently in
            $this->leaveOtherPrivateChats($user, $chat, $em);

            // ensure user is part of this chat's invitation participants
            $invitacion = $chat->getInvitacionChat();
            if (!$invitacion) {
                $invitacion = new InvitacionChat();
                $invitacion->setToken(bin2hex(random_bytes(16)));
                $invitacion->setEstado('aceptada');
                $em->persist($invitacion);
                $chat->setInvitacionChat($invitacion);
            }

            // add as invited if not present
            $invitacion->addTokenUsuarioInvitado($user);
            $chat->setActivo(true);
            $em->persist($invitacion);
            $em->persist($chat);
            $em->flush();

            return new JsonResponse(['success' => true, 'message' => 'Chat activado', 'data' => null], Response::HTTP_OK);
        } else {
            // Deactivating: remove user from this chat (same as salir)
            $invitacion = $chat->getInvitacionChat();
            if ($invitacion) {
                $invitacion->removeTokenUsuarioInvitador($user);
                $invitacion->removeTokenUsuarioInvitado($user);
                $em->persist($invitacion);
                $em->flush();

                $countInvitadores = count($invitacion->getTokenUsuarioInvitador());
                $countInvitados = count($invitacion->getTokenUsuarioInvitado());
                if ($countInvitadores === 0 && $countInvitados === 0) {
                    $em->remove($invitacion);
                    $em->remove($chat);
                    $em->flush();
                }
            } else {
                // no invitacion: just deactivate or remove chat
                $em->remove($chat);
                $em->flush();
            }

            return new JsonResponse(['success' => true, 'message' => 'Chat desactivado', 'data' => null], Response::HTTP_OK);
        }
    }

    #[Route('/api/invitacion/responder', name: 'api_invitacion_responder', methods: ['POST'])]
    public function invitacionResponder(Request $request, EntityManagerInterface $em): JsonResponse
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

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'message' => 'Request body must be JSON'], Response::HTTP_BAD_REQUEST);
        }

        $tokenInvitacion = $data['tokeninvitacion'] ?? null;
        $accion = $data['accion'] ?? null;

        if (!$tokenInvitacion || !$accion) {
            return new JsonResponse(['success' => false, 'message' => 'Missing tokeninvitacion or accion'], Response::HTTP_BAD_REQUEST);
        }

        $accion = strtolower($accion);
        if (!in_array($accion, ['aceptar', 'rechazar'])) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid accion, must be "aceptar" or "rechazar"'], Response::HTTP_BAD_REQUEST);
        }

        $invitacion = $em->getRepository(InvitacionChat::class)->findOneBy(['token' => $tokenInvitacion]);
        if (!$invitacion) {
            return new JsonResponse(['success' => false, 'message' => 'Invitation not found'], Response::HTTP_NOT_FOUND);
        }

        // confirm the current user is one of the invited users
        $isInvitado = false;
        foreach ($invitacion->getTokenUsuarioInvitado() as $invited) {
            if ($invited->getId() === $user->getId()) {
                $isInvitado = true;
                break;
            }
        }
        if (!$isInvitado) {
            // as fallback, also allow users who have invitacionesChat pointer to this invitacion
            if ($user->getInvitacionesChat() && $user->getInvitacionesChat()->getId() === $invitacion->getId()) {
                $isInvitado = true;
            }
        }

        if (!$isInvitado) {
            return new JsonResponse(['success' => false, 'message' => 'No autorizado para gestionar esta invitación'], Response::HTTP_FORBIDDEN);
        }

        // Handle actions
        if ($accion === 'aceptar') {
            // Mark related chats active and detach invitation, then remove invitation
            foreach ($invitacion->getTokenChat() as $chat) {
                $chat->setActivo(true);
                // detach the invitation to avoid orphan references
                $chat->setInvitacionChat(null);
                $em->persist($chat);
            }

            // detach invitation reference from any users (invitador/invitado)
            foreach ($invitacion->getTokenUsuarioInvitador() as $u) {
                $u->setInvitacionChat(null);
                $em->persist($u);
            }
            foreach ($invitacion->getTokenUsuarioInvitado() as $u) {
                $u->setInvitacionesChat(null);
                $em->persist($u);
            }

            $em->remove($invitacion);
            $em->flush();

            return new JsonResponse(['success' => true, 'message' => 'Invitación aceptada', 'data' => null], Response::HTTP_OK);
        }

        // rechazar
        foreach ($invitacion->getTokenChat() as $chat) {
            $inv = $chat->getInvitacionChat();
            $countInvitadores = $inv ? count($inv->getTokenUsuarioInvitador()) : 0;
            $countInvitados = $inv ? count($inv->getTokenUsuarioInvitado()) : 0;

            // If the only participant is the invited user and they reject, remove the chat as well
            if ($countInvitadores === 0 && $countInvitados <= 1) {
                $em->remove($chat);
            } else {
                // otherwise just detach the invitation
                $chat->setInvitacionChat(null);
                $em->persist($chat);
            }
        }

        // detach invitation reference from users before removal
        foreach ($invitacion->getTokenUsuarioInvitador() as $u) {
            $u->setInvitacionChat(null);
            $em->persist($u);
        }
        foreach ($invitacion->getTokenUsuarioInvitado() as $u) {
            $u->setInvitacionesChat(null);
            $em->persist($u);
        }

        $em->remove($invitacion);
        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Invitación rechazada', 'data' => null], Response::HTTP_OK);
    }

    private function leaveOtherPrivateChats(Usuario $user, Chat $exceptChat, EntityManagerInterface $em): void
    {
        // collect private chats where the user participates, excluding the target chat
        $chatsToCheck = [];
        $invitacion = $user->getInvitacionChat();
        if ($invitacion) {
            foreach ($invitacion->getTokenChat() as $c) {
                if ($c->isActivo() && strtolower($c->getTipo()) === 'privado') {
                    $chatsToCheck[] = $c;
                }
            }
        }
        $invitaciones = $user->getInvitacionesChat();
        if ($invitaciones) {
            foreach ($invitaciones->getTokenChat() as $c) {
                if ($c->isActivo() && strtolower($c->getTipo()) === 'privado') {
                    $chatsToCheck[] = $c;
                }
            }
        }

        foreach ($chatsToCheck as $pc) {
            if ($pc->getToken() === $exceptChat->getToken()) continue;

            $inv = $pc->getInvitacionChat();
            if ($inv) {
                $inv->removeTokenUsuarioInvitador($user);
                $inv->removeTokenUsuarioInvitado($user);
                $em->persist($inv);
                $em->flush();

                $countInvitadores = count($inv->getTokenUsuarioInvitador());
                $countInvitados = count($inv->getTokenUsuarioInvitado());
                if ($countInvitadores === 0 && $countInvitados === 0) {
                    $em->remove($inv);
                    $em->remove($pc);
                    $em->flush();
                }
            } else {
                $em->remove($pc);
                $em->flush();
            }
        }
    }

    #[Route('/api/mensaje', name: 'api_mensaje', methods: ['GET', 'POST'])]
    public function mensaje(Request $request, EntityManagerInterface $em): JsonResponse
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

        $tokenChat = $request->query->get('tokenchat') ?? $request->request->get('tokenchat');
        if (!$tokenChat) {
            return new JsonResponse(['success' => false, 'message' => 'Missing tokenchat'], Response::HTTP_BAD_REQUEST);
        }

        $chat = $em->getRepository(Chat::class)->findOneBy(['token' => $tokenChat]);
        if (!$chat) {
            return new JsonResponse(['success' => false, 'message' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if user is authorized for this chat
        $authorized = false;
        if (strtolower($chat->getTipo()) === 'publico') {
            $authorized = true;
        } elseif (strtolower($chat->getTipo()) === 'privado') {
            // Check if user is invitador or invitado
            $inv = $chat->getInvitacionChat();
            if ($inv) {
                foreach ($inv->getTokenUsuarioInvitador() as $u) {
                    if ($u->getId() === $user->getId()) {
                        $authorized = true;
                        break;
                    }
                }
                if (!$authorized) {
                    foreach ($inv->getTokenUsuarioInvitado() as $u) {
                        if ($u->getId() === $user->getId()) {
                            $authorized = true;
                            break;
                        }
                    }
                }
            }
        }

        if (!$authorized) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized access to chat'], Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('POST')) {
            // Send message
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return new JsonResponse(['success' => false, 'message' => 'Request body must be JSON'], Response::HTTP_BAD_REQUEST);
            }

            $contenido = $data['contenido'] ?? null;
            if (!$contenido) {
                return new JsonResponse(['success' => false, 'message' => 'Missing contenido'], Response::HTTP_BAD_REQUEST);
            }

            $mensaje = new Mensajes();
            $mensaje->setContenido($contenido);
            $mensaje->setTokenUsuario($user);
            $mensaje->setTokenChat($chat);
            $mensaje->setFechaEnvio(new \DateTime());
            $mensaje->setToken(bin2hex(random_bytes(16))); // Generate unique token for message

            $em->persist($mensaje);
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Mensaje enviado',
                'data' => [
                    'tokenchat' => $chat->getToken(),
                    'contenido' => $mensaje->getContenido(),
                    'fecha_envio' => $mensaje->getFechaEnvio()->format('Y-m-d\TH:i:s\Z'),
                ]
            ], Response::HTTP_CREATED);
        } elseif ($request->isMethod('GET')) {
            // List messages
            $mensajes = $chat->getMensajes();
            $data = [];
            foreach ($mensajes as $msg) {
                $data[] = [
                    'token' => $msg->getToken(),
                    'contenido' => $msg->getContenido(),
                    'fecha_envio' => $msg->getFechaEnvio() ? $msg->getFechaEnvio()->format('Y-m-d\TH:i:s\Z') : null,
                    'usuario' => [
                        'token' => $msg->getTokenUsuario()->getToken(),
                        'nombre' => $msg->getTokenUsuario()->getNombre(),
                    ],
                ];
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Mensajes listados',
                'data' => $data
            ], Response::HTTP_OK);
        }

        return new JsonResponse(['success' => false, 'message' => 'Method not allowed'], Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Route('/api/actualizar', name: 'api_actualizar', methods: ['GET'])]
    public function actualizar(Request $request, EntityManagerInterface $em): JsonResponse
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

        $mensajesRepo = $em->getRepository(Mensajes::class);
        $chatRepo = $em->getRepository(Chat::class);
        $invRepo = $em->getRepository(InvitacionChat::class);
        $usuarioRepo = $em->getRepository(Usuario::class);

        $messagesProcessed = 0;

        // General chats (public)
        $publicChats = $chatRepo->findBy(['tipo' => 'Publico', 'activo' => true]);
        $chatGeneral = [];
        foreach ($publicChats as $chat) {
            $msgs = $mensajesRepo->findBy(['tokenChat' => $chat], ['fechaEnvio' => 'DESC'], 10);
            $arr = [];
            foreach (array_reverse($msgs) as $m) {
                $arr[] = [
                    'token' => $m->getToken(),
                    'contenido' => $m->getContenido(),
                    'fecha_envio' => $m->getFechaEnvio() ? $m->getFechaEnvio()->format('Y-m-d\\TH:i:s\\Z') : null,
                    'usuario' => [
                        'token' => $m->getTokenUsuario()->getToken(),
                        'nombre' => $m->getTokenUsuario()->getNombre(),
                    ],
                ];
                $messagesProcessed++;
            }
            $chatGeneral[] = [
                'tokenChat' => $chat->getToken(),
                'mensajes' => $arr,
            ];
        }

        // Private chats for this user
        $privateChats = [];
        $invitacion = $user->getInvitacionChat();
        if ($invitacion) {
            foreach ($invitacion->getTokenChat() as $c) {
                if ($c->isActivo() && strtolower($c->getTipo()) === 'privado') {
                    $privateChats[$c->getToken()] = $c;
                }
            }
        }
        $invitaciones = $user->getInvitacionesChat();
        if ($invitaciones) {
            foreach ($invitaciones->getTokenChat() as $c) {
                if ($c->isActivo() && strtolower($c->getTipo()) === 'privado') {
                    $privateChats[$c->getToken()] = $c;
                }
            }
        }

        $chatPrivado = [];
        foreach ($privateChats as $chat) {
            $msgs = $mensajesRepo->findBy(['tokenChat' => $chat], ['fechaEnvio' => 'DESC'], 20);
            $arr = [];
            foreach (array_reverse($msgs) as $m) {
                $arr[] = [
                    'token' => $m->getToken(),
                    'contenido' => $m->getContenido(),
                    'fecha_envio' => $m->getFechaEnvio() ? $m->getFechaEnvio()->format('Y-m-d\\TH:i:s\\Z') : null,
                    'usuario' => [
                        'token' => $m->getTokenUsuario()->getToken(),
                        'nombre' => $m->getTokenUsuario()->getNombre(),
                    ],
                ];
                $messagesProcessed++;
            }
            $inv = $chat->getInvitacionChat();
            $participants = [];
            if ($inv) {
                foreach ($inv->getTokenUsuarioInvitador() as $u) {
                    $participants[] = ['token' => $u->getToken(), 'nombre' => $u->getNombre()];
                }
                foreach ($inv->getTokenUsuarioInvitado() as $u) {
                    $participants[] = ['token' => $u->getToken(), 'nombre' => $u->getNombre()];
                }
            }
            $chatPrivado[] = [
                'tokenChat' => $chat->getToken(),
                'mensajes' => $arr,
                'participantes' => array_values(array_unique($participants, SORT_REGULAR)),
            ];
        }

        // Recent invitations involving this user
        $recentInvs = $invRepo->findBy([], ['id' => 'DESC'], 20);
        $invitacionesList = [];
        foreach ($recentInvs as $inv) {
            $involves = false;
            foreach ($inv->getTokenUsuarioInvitado() as $u) {
                if ($u->getId() === $user->getId()) $involves = true;
            }
            foreach ($inv->getTokenUsuarioInvitador() as $u) {
                if ($u->getId() === $user->getId()) $involves = true;
            }
            if (!$involves) continue;

            $chatsTokens = [];
            foreach ($inv->getTokenChat() as $c) {
                $chatsTokens[] = $c->getToken();
            }

            $invitors = [];
            foreach ($inv->getTokenUsuarioInvitador() as $u) {
                $invitors[] = ['token' => $u->getToken(), 'nombre' => $u->getNombre()];
            }
            $invitees = [];
            foreach ($inv->getTokenUsuarioInvitado() as $u) {
                $invitees[] = ['token' => $u->getToken(), 'nombre' => $u->getNombre()];
            }

            $invitacionesList[] = [
                'token' => $inv->getToken(),
                'estado' => $inv->getEstado(),
                'chats' => $chatsTokens,
                'invitadores' => $invitors,
                'invitados' => $invitees,
            ];
        }

        // Nearby users within 5 km
        $nearby = [];
        $allUsers = $usuarioRepo->findAll();
        $originLat = $user->getLatitud();
        $originLon = $user->getLongitud();
        if ($originLat !== null && $originLon !== null) {
            foreach ($allUsers as $u) {
                if ($u->getId() === $user->getId()) continue;
                if (!$u->isActivo()) continue;
                if ($u->getLatitud() === null || $u->getLongitud() === null) continue;
                $lat1 = deg2rad($originLat);
                $lon1 = deg2rad($originLon);
                $lat2 = deg2rad($u->getLatitud());
                $lon2 = deg2rad($u->getLongitud());
                $dlat = $lat2 - $lat1;
                $dlon = $lon2 - $lon1;
                $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
                $c = 2 * atan2(sqrt($a), sqrt(1-$a));
                $distanceKm = 6371 * $c;
                if ($distanceKm <= 5) {
                    $nearby[] = ['token' => $u->getToken(), 'nombre' => $u->getNombre(), 'distancia_km' => round($distanceKm, 3)];
                }
            }
        }

        // sort nearby by distance
        usort($nearby, function($a, $b) { return ($a['distancia_km'] <=> $b['distancia_km']); });

        $response = [
            'chatGeneralActualizado' => count($chatGeneral) > 0,
            'chatPrivadoActualizado' => count($chatPrivado) > 0,
            'mensajesProcesados' => $messagesProcessed,
            'invitacionesNuevas' => count($invitacionesList),
            'invitaciones' => $invitacionesList,
            'usuariosCercanos' => count($nearby),
            'usuarios' => $nearby,
            'chatGeneral' => $chatGeneral,
            'chatPrivado' => $chatPrivado,
        ];

        return new JsonResponse(['success' => true, 'message' => 'Chats actualizados', 'data' => $response], Response::HTTP_OK);
    }
}
