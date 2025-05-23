<?php

namespace FluentCrm\Framework\Validator;

use Exception;

class ValidationException extends Exception
{
    private $errors;

    public function __construct($message = "", $code = 0 , Exception $previous = NULL, $errors = [])
    {
        $this->errors = $errors;

        parent::__construct($message, $code, $previous);
    }

    public function errors()
    {
        return $this->errors;
    }
}
