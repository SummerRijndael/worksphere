<?php

namespace App\Enums;

enum InternalTeamRole: string
{
    case Manager = 'manager';
    case Agent = 'agent';
    case Lead = 'lead';

    /**
     * @return array<string, string>
     */
    public static function asSelectArray(): array
    {
        return [
            self::Manager->value => 'Manager',
            self::Agent->value => 'Agent',
            self::Lead->value => 'Lead',
        ];
    }
}
