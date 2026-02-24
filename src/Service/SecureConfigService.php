<?php declare(strict_types=1);

namespace MoptWorldline\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class SecureConfigService
{
    private SystemConfigService $systemConfigService;
    private EncryptionService $encryptionService;
    private SecureFieldDefinition $secureFieldDefinition;

    /**
     * @param SystemConfigService $systemConfigService
     * @param EncryptionService $encryptionService
     * @param SecureFieldDefinition $secureFieldDefinition
     */
    public function __construct(
        SystemConfigService $systemConfigService,
        EncryptionService $encryptionService,
        SecureFieldDefinition $secureFieldDefinition
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->encryptionService = $encryptionService;
        $this->secureFieldDefinition = $secureFieldDefinition;
    }

    /**
     * @param string $key
     * @param string|null $salesChannelId
     *
     * @return bool|float|int|mixed|array|string|null
     */
    public function get(string $key, ?string $salesChannelId = null)
    {
        $value = $this->systemConfigService->get($key, $salesChannelId);

        if ($this->secureFieldDefinition->isSecureField($key) && is_string($value) && !empty($value)) {
            if ($this->encryptionService->isEncrypted($value)) {
                try {
                    return $this->encryptionService->decrypt($value);
                } catch (\Exception $e) {
                    return null;
                }
            }
        }

        return $value;
    }

    /**
     * @param string $key
     * @param $value
     * @param string|null $salesChannelId
     *
     * @return void
     */
    public function set(string $key, $value, ?string $salesChannelId = null): void
    {
        $this->systemConfigService->set($key, $value, $salesChannelId);
    }

    /**
     * @param string $key
     * @param string|null $salesChannelId
     *
     * @return void
     */
    public function delete(string $key, ?string $salesChannelId = null): void
    {
        $this->systemConfigService->delete($key, $salesChannelId);
    }
}
