<?php

namespace CustomValidations;

use Respect\Validation\Rules\AbstractRule;

class ValidDepartmentName extends AbstractRule {

    public function validate($name): bool {
        $department = \Department::getDataStore($name, 'name');
        return $department->isNull();
   }
}
