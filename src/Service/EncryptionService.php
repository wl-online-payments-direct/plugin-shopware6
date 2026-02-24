<?php declare(strict_types=1);

namespace MoptWorldline\Service;

class EncryptionService
{
    private const ENCRYPTION_PREFIX = 'ENC:';
    private string $appSecret;

    public function __construct(string $appSecret)
    {
        $this->appSecret = $appSecret;
    }

    /**
     * @param string $data
     *
     * @return string
     *
     * @throws \Exception
     */
    public function encrypt(string $data): string
    {
        if (empty($data)) {
            return '';
        }

        $key = hash('sha256', $this->appSecret, true);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $ciphertext = sodium_crypto_secretbox($data, $nonce, $key);

        return self::ENCRYPTION_PREFIX . base64_encode($nonce . $ciphertext);
    }

    /**
     * @param string $encryptedData
     *
     * @return string
     * @throws \Exception
     */
    public function decrypt(string $encryptedData): string
    {
        if (empty($encryptedData)) {
            return '';
        }

        if (strpos($encryptedData, self::ENCRYPTION_PREFIX) !== false) {
            $encryptedData = substr($encryptedData, strlen(self::ENCRYPTION_PREFIX));
        }

        $decoded = base64_decode($encryptedData);

        if (!$decoded || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \Exception('Decryption failed.');
        }

        $key = hash('sha256', $this->appSecret, true);

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if (!$decrypted) {
            throw new \Exception('Decryption failed.');
        }

        return $decrypted;
    }

    /**
     * @param string $data
     *
     * @return bool
     */
    public function isEncrypted(string $data): bool
    {
        return strpos($data, self::ENCRYPTION_PREFIX) !== false;
    }
}
