<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

/** Trusted PHP class describing allowed user/model-supplied action fields. */
interface ActionInputSchemaInterface
{
    /** @return array<string, array<int, string>> */
    public function rules(): array;
}
