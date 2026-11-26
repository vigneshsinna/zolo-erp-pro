<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

class CompanyLicenseBlockedException extends AuthorizationException
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $licenseStatus,
        string $message = 'Company license is not active.',
    ) {
        parent::__construct($message);
    }
}
