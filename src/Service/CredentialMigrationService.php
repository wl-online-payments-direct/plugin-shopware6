<?php declare(strict_types=1);

namespace MoptWorldline\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CredentialMigrationService
{
    private SystemConfigService $systemConfigService;
    private Connection $connection;
    private EncryptionService $encryptionService;
    private SecureFieldDefinition $secureFieldDefinition;

    public function __construct(
        SystemConfigService $systemConfigService,
        Connection $connection,
        EncryptionService $encryptionService,
        SecureFieldDefinition $secureFieldDefinition
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->connection = $connection;
        $this->encryptionService = $encryptionService;
        $this->secureFieldDefinition = $secureFieldDefinition;
    }

    /**
     * Migrate all credential fields to encrypted format
     *
     * @param Context $context
     *
     * @return void
     */
    public function migrate(Context $context): void
    {
        try {
            $salesChannelIds = $this->getSalesChannelIds();
            $salesChannelIds[] = null;

            foreach ($this->secureFieldDefinition->getFields() as $field) {
                foreach ($salesChannelIds as $salesChannelId) {
                    $this->migrateField($field, $salesChannelId);
                }
            }
        } catch (\Exception $e) {
            error_log('Worldline encryption migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Get all active sales channel IDs
     *
     * @return array
     */
    private function getSalesChannelIds(): array
    {
        try {
            $result = $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(id)) FROM sales_channel WHERE active = 1'
            );
            return $result ?: [];
        } catch (\Exception $e) {
            error_log('Failed to get sales channel IDs: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Migrate a single field to encrypted format
     *
     * @param string $field
     * @param string|null $salesChannelId
     *
     * @return void
     */
    private function migrateField(string $field, ?string $salesChannelId): void
    {
        try {
            if ($salesChannelId !== null) {
                $value = $this->getDirectConfigValue($field, $salesChannelId);
                if ($value === null) {
                    return;
                }
            } else {
                $value = $this->systemConfigService->get($field, null);
            }

            if (empty($value) || !is_string($value)) {
                return;
            }

            if ($this->encryptionService->isEncrypted($value)) {
                return;
            }

            $encryptedValue = $this->encryptionService->encrypt($value);
            $this->systemConfigService->set($field, $encryptedValue, $salesChannelId);

        } catch (\Exception $e) {
            error_log("Failed to migrate field {$field} for sales channel {$salesChannelId}: " . $e->getMessage());
        }
    }

    /**
     * Get configuration value directly from database
     *
     * @param string $key
     * @param string $salesChannelId
     *
     * @return string|null
     */
    private function getDirectConfigValue(string $key, string $salesChannelId): ?string
    {
        try {
            $sql = '
                SELECT configuration_value
                FROM system_config
                WHERE configuration_key = :key
                  AND sales_channel_id = UNHEX(:salesChannelId)
            ';

            $result = $this->connection->fetchOne($sql, [
                'key' => $key,
                'salesChannelId' => $salesChannelId
            ]);

            if ($result === false) {
                return null;
            }

            $decoded = json_decode($result, true);
            return $decoded['_value'] ?? null;
        } catch (\Exception $e) {
            error_log("Failed to get direct config value for {$key}: " . $e->getMessage());

            return null;
        }
    }
}