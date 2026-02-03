<?php

namespace App\Entity;

use App\Repository\MensajesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MensajesRepository::class)]
class Mensajes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $contenido = null;

    #[ORM\Column(nullable: true)]
    private ?bool $leido = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $fechaEnvio = null;

    #[ORM\Column(length: 255)]
    private ?string $token = null;

    #[ORM\ManyToOne(inversedBy: 'mensajes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Usuario $tokenUsuario = null;

    #[ORM\ManyToOne(inversedBy: 'mensajes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chat $tokenChat = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContenido(): ?string
    {
        return $this->contenido;
    }

    public function setContenido(string $contenido): static
    {
        $this->contenido = $contenido;

        return $this;
    }

    public function isLeido(): ?bool
    {
        return $this->leido;
    }

    public function setLeido(?bool $leido): static
    {
        $this->leido = $leido;

        return $this;
    }

    public function getFechaEnvio(): ?\DateTimeInterface
    {
        return $this->fechaEnvio;
    }

    public function setFechaEnvio(\DateTimeInterface $fechaEnvio): static
    {
        $this->fechaEnvio = $fechaEnvio;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getTokenUsuario(): ?Usuario
    {
        return $this->tokenUsuario;
    }

    public function setTokenUsuario(?Usuario $tokenUsuario): static
    {
        $this->tokenUsuario = $tokenUsuario;

        return $this;
    }

    public function getTokenChat(): ?Chat
    {
        return $this->tokenChat;
    }

    public function setTokenChat(?Chat $tokenChat): static
    {
        $this->tokenChat = $tokenChat;

        return $this;
    }
}
