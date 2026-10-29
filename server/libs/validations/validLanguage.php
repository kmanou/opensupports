<?php

namespace CustomValidations;

use Respect\Validation\Rules\AbstractRule;

class ValidLanguage extends AbstractRule {

    public function validate($language): bool {
        return in_array($language, \Language::LANGUAGES);
    }
}