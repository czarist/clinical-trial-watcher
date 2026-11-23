<?php

declare(strict_types=1);

namespace App\Exceptions;

final class TeamActionFailed extends ApplicationException
{
    private int $httpStatus = 422;

    public static function alreadyOwnsTeam(): self
    {
        return new self(__('errors.team.already_owns_team'));
    }

    public static function alreadyMember(): self
    {
        return new self(__('errors.team.already_member'));
    }

    public static function ownerCannotLeave(): self
    {
        return new self(__('errors.team.owner_cannot_leave'));
    }

    public static function invitationNotFound(): self
    {
        $exception = new self(__('errors.team.invitation_not_found'));
        $exception->httpStatus = 404;

        return $exception;
    }

    #[\Override]
    public function status(): int
    {
        return $this->httpStatus;
    }
}
