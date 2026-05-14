<?php declare(strict_types=1);

namespace MoptWorldline\Subscriber;

use MoptWorldline\Service\EncryptionService;
use MoptWorldline\Service\SecureFieldDefinition;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigDomainLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class ConfigEncryptionSubscriber implements EventSubscriberInterface
{
    private EncryptionService $encryptionService;
    private SecureFieldDefinition $secureFieldDefinition;
    private RequestStack $requestStack;

    public function __construct(
        EncryptionService $encryptionService,
        SecureFieldDefinition $secureFieldDefinition,
        RequestStack $requestStack
    ) {
        $this->encryptionService = $encryptionService;
        $this->secureFieldDefinition = $secureFieldDefinition;
        $this->requestStack = $requestStack;
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeSystemConfigChangedEvent::class => 'onBeforeSystemConfigChanged',
            SystemConfigDomainLoadedEvent::class => 'onSystemConfigLoaded',
        ];
    }

    /**
     * @param BeforeSystemConfigChangedEvent $event
     *
     * @return void
     */
    public function onBeforeSystemConfigChanged(BeforeSystemConfigChangedEvent $event): void
    {
        $key = $event->getKey();
        $value = $event->getValue();

        if (!$this->secureFieldDefinition->isSecureField($key) || !is_string($value) || empty($value)) {
            return;
        }

        if ($this->encryptionService->isEncrypted($value)) {
            return;
        }

        try {
            $encryptedValue = $this->encryptionService->encrypt($value);
            $event->setValue($encryptedValue);
        } catch (\Exception $e) {
            $violations = new ConstraintViolationList([
                new ConstraintViolation(
                    'Failed to encrypt configuration value. Please try again.',
                    'Failed to encrypt configuration value. Please try again.',
                    [],
                    null,
                    '/' . $key,
                    $value
                ),
            ]);
            throw new ConstraintViolationException($violations, []);
        }
    }
    /**
     * @param SystemConfigDomainLoadedEvent $event
     *
     * @return void
     */
    public function onSystemConfigLoaded(SystemConfigDomainLoadedEvent $event): void
    {
        $config = $event->getConfig();
        $decryptionFailed = false;

        foreach($config as $key => $value) {
            if ($this->secureFieldDefinition->isSecureField($key) && $this->encryptionService->isEncrypted($value)) {
                try {
                    $config[$key] = $this->encryptionService->decrypt($value);
                } catch (\Exception $e) {
                    $config[$key] = '';
                    $decryptionFailed = true;
                }
            }
        }

        if ($decryptionFailed) {
            $request = $this->requestStack->getCurrentRequest();
            if ($request && $request->hasSession()) {
                $request->getSession()->set('worldline.decryption_failed', true);
            }
        }

        $event->setConfig($config);
    }
}