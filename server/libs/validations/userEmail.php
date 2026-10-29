<?php

namespace CustomValidations;

use Respect\Validation\Rules\AbstractRule;

class UserEmail extends AbstractRule {

    public function validate($email): bool {
        $user= \User::getUser($email, 'email');

        return !$user->isNull();
    }
}