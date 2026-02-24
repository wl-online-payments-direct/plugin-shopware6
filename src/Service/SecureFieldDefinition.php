<?php declare(strict_types=1);

namespace MoptWorldline\Service;

use MoptWorldline\Bootstrap\Form;

final class SecureFieldDefinition
{
    private const ENCRYPTED_FIELDS = [
        Form::API_KEY_FIELD,
        Form::API_SECRET_FIELD,
        Form::WEBHOOK_KEY_FIELD,
        Form::WEBHOOK_SECRET_FIELD,
        Form::LIVE_API_KEY_FIELD,
        Form::LIVE_API_SECRET_FIELD,
        Form::LIVE_WEBHOOK_KEY_FIELD,
        Form::LIVE_WEBHOOK_SECRET_FIELD,
    ];

    /**
     * @return string[]
     */
    public function getFields(): array
    {
        return self::ENCRYPTED_FIELDS;
    }

    /**
     * Checks whether a field should be encrypted
     *
     * @param string $key
     *
     * @return bool
     */
    public function isSecureField(string $key): bool
    {
        return in_array($key, self::ENCRYPTED_FIELDS);
    }
}
