<?php declare(strict_types=1);

/**
 * @author Mediaopt GmbH
 * @package MoptWorldline\Service
 */

namespace MoptWorldline\Service;

use Monolog\Level;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use MoptWorldline\Service\SecureConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

class CronTaskHandler extends ScheduledTaskHandler
{
    private EntityRepository $salesChannelRepository;
    private SecureConfigService $secureConfigService;
    private EntityRepository $orderRepository;
    private EntityRepository $customerRepository;
    private OrderTransactionStateHandler $transactionStateHandler;
    private TranslatorInterface $translator;
    private StateMachineRegistry $stateMachineRegistry;

    public function __construct(
        EntityRepository             $scheduledTaskRepository,
        EntityRepository             $salesChannelRepository,
        SecureConfigService          $secureConfigService,
        EntityRepository             $orderRepository,
        EntityRepository             $customerRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        TranslatorInterface          $translator,
        StateMachineRegistry         $stateMachineRegistry
    )
    {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->secureConfigService = $secureConfigService;
        $this->orderRepository = $orderRepository;
        $this->customerRepository = $customerRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->translator = $translator;
        $this->stateMachineRegistry = $stateMachineRegistry;
        parent::__construct($scheduledTaskRepository);
    }

    public function run(): void
    {
        try {
            $oldOrderProcessor = new OldOrderProcessor(
                $this->salesChannelRepository,
                $this->secureConfigService,
                $this->orderRepository,
                $this->customerRepository,
                $this->transactionStateHandler,
                $this->translator,
                $this->stateMachineRegistry,
            );

            $oldOrderProcessor->process();
        } catch (\Throwable $e) {
            LogHelper::addLog(Level::Error, $e->getMessage(), $e->getTrace());
        }
    }
}
