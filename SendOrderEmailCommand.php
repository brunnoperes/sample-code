<?php

namespace App\Command;

use App\Model\Rejection\ModelCancellation;
use App\Sylius\Bundle\ShopBundle\EmailManager\BankTransferOrderEmailManager;
use App\Sylius\Bundle\ShopBundle\EmailManager\OrderCancellationEmailManagerInterface;
use App\Sylius\Bundle\ShopBundle\EmailManager\OrderEmailManager;
use App\Sylius\Bundle\ShopBundle\EmailManager\OrderRejectionEmailManagerInterface;
use App\Sylius\Bundle\ShopBundle\EmailManager\PartnerPaymentTermsOrderNotificationEmail;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\AdminBundle\EmailManager\ShipmentEmailManagerInterface;
use Sylius\Bundle\OrderBundle\Doctrine\ORM\OrderRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SendOrderEmailCommand extends Command
{
  protected static $defaultName = 'app:send-order-email';

  protected $logger;

  /** @var OrderRepository */
  protected $orderRepository;

  /** @var ShipmentEmailManagerInterface */
  protected $shipmentEmailManager;

  /** @var BankTransferOrderEmailManager */
  protected $bankTransferEmailManager;

  /** @var OrderEmailManager */
  protected $orderEmailManager;

  protected $orderCancellationEmailManager;

  /** @var PartnerPaymentTermsOrderNotificationEmail */
  protected $partnerPaymentTermsOrderNotificationEmail;

  /** @var OrderRejectionEmailManagerInterface */
  protected $orderRejectionEmailManager;

  public function __construct(
    LoggerInterface $logger,
    OrderRepository $orderRepository,
    OrderEmailManager $orderEmailManager,
    ShipmentEmailManagerInterface $shipmentEmailManager,
    BankTransferOrderEmailManager $bankTransferEmailManager,
    OrderCancellationEmailManagerInterface $orderCancellationEmailManager,
    PartnerPaymentTermsOrderNotificationEmail $partnerPaymentTermsOrderNotificationEmail,
    OrderRejectionEmailManagerInterface $orderRejectionEmailManager
  ) {
    parent::__construct();
    $this->logger = $logger;
    $this->orderRepository = $orderRepository;
    $this->orderEmailManager = $orderEmailManager;
    $this->shipmentEmailManager = $shipmentEmailManager;
    $this->bankTransferEmailManager = $bankTransferEmailManager;
    $this->orderCancellationEmailManager = $orderCancellationEmailManager;
    $this->partnerPaymentTermsOrderNotificationEmail = $partnerPaymentTermsOrderNotificationEmail;
    $this->orderRejectionEmailManager = $orderRejectionEmailManager;
  }

  protected function configure()
  {
    $this->setDescription('Command to send order email');
    $this->addArgument('order_id', InputArgument::REQUIRED, 'order id');
    $this->addOption(
      'template',
      null,
      InputOption::VALUE_REQUIRED,
      'Which email template do you want to send?',
      'orderConfirmation'
    );

    $this->addOption('email', null, InputOption::VALUE_REQUIRED, "email that override's customer email", '');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);

    $orderId = $input->getArgument('order_id');

    /** @var OrderInterface $order */
    $order = $this->orderRepository->find($orderId);

    if (!$order) {
      $io->writeln(sprintf('Order was not found with id  %s', $orderId));

      return 1;
    }
    $emailTemplate = $input->getOption('template');

    //this email will override customer's email,  it will not modify the actual customer's email
    $email = $input->getOption('email');
    if (!empty($email)) {
      $order->getCustomer()->setEmail($email);
    }

    switch ($emailTemplate) {
      case 'orderConfirmation':
        $io->writeln(sprintf('Sending email confirmation for order %s', $orderId));
        $this->orderEmailManager->sendConfirmationEmail($order);
        break;

      case 'bankTransferEmail':
        $io->writeln(sprintf('Sending bank transfer email confirmation for order %s', $orderId));
        $this->bankTransferEmailManager->sendConfirmationEmail($order);
        break;

      case 'shipmentConfirmation':
        $io->writeln(sprintf('Sending shipment email confirmation for order %s', $orderId));
        $this->shipmentEmailManager->sendConfirmationEmail($order->getShipments()->first());
        break;

      case 'order_cancellation':
        $io->writeln(sprintf('Sending order cancellation email confirmation for order %s', $orderId));

        $orderCancellationModel = new ModelCancellation();
        $orderCancellationModel->setOrder($order);
        $orderCancellationModel->setModelName('Test Model Name');
        $orderCancellationModel->setOrderItemEmail(false);

        $this->orderCancellationEmailManager->sendOrderCancellationEmail($orderCancellationModel);
        break;

      case 'partnerPaymentTermsNotificationEmail':
        $io->writeln(sprintf('Sending partner payment terms order notification email for order %s', $orderId));
        $this->partnerPaymentTermsOrderNotificationEmail->sendOrderNotificationEmail($order->getLastPayment());
        break;

      default:
        break;
    }

    return 0;
  }
}
