<?php

declare(strict_types=1);

namespace App\Domain\Video\ValueObjects;

use App\Domain\Shared\ValueObjects\Uuid;

/** Dono do vídeo: id do fiapx-auth e snapshot de e-mail e nome do token (usados na notificação). */
final readonly class Owner
{
    public string $userId;

    public string $email;

    public string $name;

    public function __construct(string $userId, string $email, string $name)
    {
        $this->userId = Uuid::assert($userId, 'Id do usuário');
        $email = strtolower(trim($email));
        if ($email === '' || mb_strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mail do usuário inválido.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Nome do usuário inválido.');
        }
        $this->email = $email;
        $this->name = $name;
    }
}
