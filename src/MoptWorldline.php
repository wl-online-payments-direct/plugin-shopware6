<?php declare(strict_types=1);

/**
 * @author Mediaopt GmbH
 * @package MoptWorldline
 */

namespace MoptWorldline;

use MoptWorldline\Service\CredentialMigrationService;
use MoptWorldline\Service\Payment;
use MoptWorldline\Service\PaymentMethodHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use MoptWorldline\Service\CustomField;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class MoptWorldline extends Plugin
{
    const PLUGIN_NAME = 'MoptWorldline';
    const PLUGIN_VERSION = '3.2.11';
    const PLUGIN_ID = 'MoptWorldline';
    const PLUGIN_CREATOR = 'Mediaopt GmbH';
    private const ENCRYPTION_INTRODUCED_VERSION = '3.2.7';

    /**
     * @param InstallContext $installContext
     */
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $customField = new CustomField($this->container);
        $customField->addCustomFields($installContext);

        /** @var EntityRepository $paymentMethodRep */
        $paymentMethodRep = $this->container->get('payment_method.repository');
        /** @var EntityRepository $salesChannelPaymentMethodRep */
        $salesChannelPaymentMethodRep = $this->container->get('sales_channel_payment_method.repository');
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        /** @var EntityRepository $salesChannelRep */
        $salesChannelRep = $this->container->get('sales_channel.repository');

        foreach (Payment::METHODS_LIST as $method) {
            $methodId = PaymentMethodHelper::addPaymentMethod(
                $paymentMethodRep,
                $pluginIdProvider,
                $installContext->getContext(),
                $method
            );

            PaymentMethodHelper::linkPaymentMethod(
                $methodId,
                null,
                true,
                $salesChannelRep,
                $salesChannelPaymentMethodRep,
                $installContext->getContext()
            );
        }
    }

    /**
     * @param UninstallContext $uninstallContext
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        $context = $uninstallContext->getContext();

        $this->setAllPluginPaymentMethodsStatus(false, $context);

        if (!$uninstallContext->keepUserData()) {
            $this->detachPluginPaymentMethodsFromSalesChannels($context);
        }
    }

    /**
     * @param UpdateContext $updateContext
     *
     * @return void
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        if (version_compare($updateContext->getCurrentPluginVersion(), self::ENCRYPTION_INTRODUCED_VERSION, '<')) {
            $migrationService = $this->container->get(CredentialMigrationService::class);
            $migrationService->migrate($updateContext->getContext());
        }
    }

    /**
     * @param ActivateContext $activateContext
     */
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->setPaymentMethodsStatus(true, $activateContext->getContext());
    }

    /**
     * @param DeactivateContext $deactivateContext
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
        $this->setAllPluginPaymentMethodsStatus(false, $deactivateContext->getContext());
    }

    /**
     * @param bool $status
     * @param Context $context
     * @return void
     */
    private function setPaymentMethodsStatus(bool $status, Context $context)
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');
        foreach (Payment::METHODS_LIST as $method) {
            PaymentMethodHelper::setPaymentMethodStatus($paymentMethodRepository, $status, $context, $method['id']);
        }
    }

    /**
     * Set active=$status on every payment_method row whose handler is owned by
     * this plugin (handlerIdentifier = Payment::class). Covers both static
     * methods from METHODS_LIST and dynamic ones added via the admin UI.
     *
     * @param bool $status
     * @param Context $context
     * @return void
     */
    private function setAllPluginPaymentMethodsStatus(bool $status, Context $context): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', Payment::class));

        $methodIds = $paymentMethodRepository->searchIds($criteria, $context)->getIds();
        if (empty($methodIds)) {
            return;
        }

        $updates = [];
        foreach ($methodIds as $methodId) {
            $updates[] = ['id' => $methodId, 'active' => $status];
        }

        $paymentMethodRepository->update($updates, $context);
    }

    /**
     * Delete sales_channel_payment_method join rows for every payment method
     * this plugin owns. Called on uninstall when keepUserData() is false, so
     * removed methods stop appearing in the storefront checkout list.
     *
     * @param Context $context
     * @return void
     */
    private function detachPluginPaymentMethodsFromSalesChannels(Context $context): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');
        /** @var EntityRepository $salesChannelPaymentRepository */
        $salesChannelPaymentRepository = $this->container->get('sales_channel_payment_method.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', Payment::class));
        $methodIds = $paymentMethodRepository->searchIds($criteria, $context)->getIds();
        if (empty($methodIds)) {
            return;
        }

        $linkCriteria = new Criteria();
        $linkCriteria->addFilter(new EqualsFilter('paymentMethodId', $methodIds));

        $links = $salesChannelPaymentRepository->search($linkCriteria, $context);
        $deletes = [];
        foreach ($links as $link) {
            $deletes[] = [
                'paymentMethodId' => $link->getPaymentMethodId(),
                'salesChannelId' => $link->getSalesChannelId(),
            ];
        }

        if (!empty($deletes)) {
            $salesChannelPaymentRepository->delete($deletes, $context);
        }
    }
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
