<?php

namespace App\Entity;

use App\Repository\InvitacionChatRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvitacionChatRepository::class)]
class InvitacionChat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $token = null;

    #[ORM\Column(length: 10)]
    private ?string $estado = null;

    /**
     * @var Collection<int, Chat>
     */
    #[ORM\OneToMany(targetEntity: Chat::class, mappedBy: 'invitacionChat')]
    private Collection $tokenChat;

    /**
     * @var Collection<int, Usuario>
     */
    #[ORM\OneToMany(targetEntity: Usuario::class, mappedBy: 'invitacionChat')]
    private Collection $tokenUsuarioInvitador;

    /**
     * @var Collection<int, Usuario>
     */
    #[ORM\OneToMany(targetEntity: Usuario::class, mappedBy: 'invitacionesChat')]
    private Collection $tokenUsuarioInvitado;

    public function __construct()
    {
        $this->tokenChat = new ArrayCollection();
        $this->tokenUsuarioInvitador = new ArrayCollection();
        $this->tokenUsuarioInvitado = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEstado(): ?string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): static
    {
        $this->estado = $estado;

        return $this;
    }

    /**
     * @return Collection<int, Chat>
     */
    public function getTokenChat(): Collection
    {
        return $this->tokenChat;
    }

    public function addTokenChat(Chat $tokenChat): static
    {
        if (!$this->tokenChat->contains($tokenChat)) {
            $this->tokenChat->add($tokenChat);
            $tokenChat->setInvitacionChat($this);
        }

        return $this;
    }

    public function removeTokenChat(Chat $tokenChat): static
    {
        if ($this->tokenChat->removeElement($tokenChat)) {
            // set the owning side to null (unless already changed)
            if ($tokenChat->getInvitacionChat() === $this) {
                $tokenChat->setInvitacionChat(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Usuario>
     */
    public function getTokenUsuarioInvitador(): Collection
    {
        return $this->tokenUsuarioInvitador;
    }

    public function addTokenUsuarioInvitador(Usuario $tokenUsuarioInvitador): static
    {
        if (!$this->tokenUsuarioInvitador->contains($tokenUsuarioInvitador)) {
            $this->tokenUsuarioInvitador->add($tokenUsuarioInvitador);
            $tokenUsuarioInvitador->setInvitacionChat($this);
        }

        return $this;
    }

    public function removeTokenUsuarioInvitador(Usuario $tokenUsuarioInvitador): static
    {
        if ($this->tokenUsuarioInvitador->removeElement($tokenUsuarioInvitador)) {
            // set the owning side to null (unless already changed)
            if ($tokenUsuarioInvitador->getInvitacionChat() === $this) {
                $tokenUsuarioInvitador->setInvitacionChat(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Usuario>
     */
    public function getTokenUsuarioInvitado(): Collection
    {
        return $this->tokenUsuarioInvitado;
    }

    public function addTokenUsuarioInvitado(Usuario $tokenUsuarioInvitado): static
    {
        if (!$this->tokenUsuarioInvitado->contains($tokenUsuarioInvitado)) {
            $this->tokenUsuarioInvitado->add($tokenUsuarioInvitado);
            $tokenUsuarioInvitado->setInvitacionesChat($this);
        }

        return $this;
    }

    public function removeTokenUsuarioInvitado(Usuario $tokenUsuarioInvitado): static
    {
        if ($this->tokenUsuarioInvitado->removeElement($tokenUsuarioInvitado)) {
            // set the owning side to null (unless already changed)
            if ($tokenUsuarioInvitado->getInvitacionesChat() === $this) {
                $tokenUsuarioInvitado->setInvitacionesChat(null);
            }
        }

        return $this;
    }
}
