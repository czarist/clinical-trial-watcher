<?php

declare(strict_types=1);

namespace App\Exceptions;

final class TeamActionFailed extends ApplicationException
{
    private int $httpStatus = 422;

    public static function alreadyOwnsTeam(): self
    {
        return new self('You already own a team. A Team AI subscription covers one team.');
    }

    public static function alreadyMember(): self
    {
        return new self('This person is already a member of the team.');
    }

    public static function ownerCannotLeave(): self
    {
        return new self('The team owner cannot leave the team. Delete the team instead.');
    }

    public static function invitationNotFound(): self
    {
        $exception = new self('Invitation not found.');
        $exception->httpStatus = 404;

        return $exception;
    }

    #[\Override]
    public function status(): int
    {
        return $this->httpStatus;
    }
}
