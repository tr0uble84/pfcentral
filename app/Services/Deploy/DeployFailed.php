<?php

namespace App\Services\Deploy;

use RuntimeException;

/** The firewall answered but refused or undid the change; $answer is its full answer (plan, undone flag). */
class DeployFailed extends RuntimeException
{
    public function __construct(string $message, public array $answer = [])
    {
        parent::__construct($message);
    }
}
