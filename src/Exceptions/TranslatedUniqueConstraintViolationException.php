<?php

namespace Easi\DB2\Exceptions;

use Illuminate\Database\UniqueConstraintViolationException;

class TranslatedUniqueConstraintViolationException extends UniqueConstraintViolationException
{
    use TranslatesQueryMessage;
}
