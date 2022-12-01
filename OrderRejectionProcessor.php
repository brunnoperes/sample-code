<?php

/**
 * Created by PhpStorm.
 * User: winsonw
 * Date: 2020-06-19
 * Time: 17:09.
 */

namespace App\Service\OrderRejection;

use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Entity\Product\ProductVariant;
use App\Entity\RejectionEmailTracker;
use App\Model\Rejection\Material;
use App\Model\Rejection\Model;
use App\Model\Rejection\ModelRejection;
use App\Model\Rejection\ModelRejectionInterface;
use App\Model\Rejection\RejectionReason;
use App\Repository\RejectionEmailTrackerRepository;
use App\Sylius\Bundle\ShopBundle\EmailManager\OrderRejectionEmailManagerInterface;
use App\Utils\Md5Utils;
use Psr\Log\LoggerInterface;
use SM\Factory\FactoryInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\OrderTransitions;

class OrderRejectionProcessor
{
  /** @var OrderRejectionEmailManagerInterface */
  private $orderRejectionEmailManager;

  /** @var RejectionEmailTrackerRepository */
  private $rejectionEmailTrackerRepository;

  private $logger;

  /** @var FactoryInterface */
  private $stateMachineFactory;

  /**
   * OrderRejectionProcessor constructor.
   */
  public function __construct(
    OrderRejectionEmailManagerInterface $orderRejectionEmailManager,
    RejectionEmailTrackerRepository $rejectionEmailTrackerRepository,
    LoggerInterface $logger,
    FactoryInterface $stateMachineFactory
  ) {
    $this->orderRejectionEmailManager = $orderRejectionEmailManager;
    $this->rejectionEmailTrackerRepository = $rejectionEmailTrackerRepository;
    $this->logger = $logger;
    $this->stateMachineFactory = $stateMachineFactory;
  }

  /**
   * @param array          $orderInfo   order info array in sw order status response
   * @param OrderInterface $syliusOrder Sylius order
   */
  public function process(OrderInterface $syliusOrder, array $orderInfo, array $emailTrackersMap)
  {
    $orderRejections = $this->createOrderRejectionFromOrderInfo($orderInfo, $syliusOrder, $emailTrackersMap);

    if (!$orderRejections) {
      return;
    }

    $this->setModelFixHoldStateForOrder($syliusOrder);

    /** @var ModelRejectionInterface $orderRejection */
    foreach ($orderRejections as $modelId => $orderRejection) {
      try {
        /** @var Model $model */
        $model = $orderRejection->getModel();

        /** @var RejectionEmailTracker $emailTracker */
        $emailTracker = isset($emailTrackersMap[$modelId]) ? $emailTrackersMap[$modelId] : null;

        /** @var Material[] $modelMaterial */
        $modelMaterials = $model->getMaterials();
        if (is_null($emailTracker)) {
          $emailTracker = new RejectionEmailTracker();
          $emailTracker->setOrder($syliusOrder);
          $emailTracker->setSentCount(0);
          $emailTracker->setModelId($modelId);
        }

        // collect all the rejection ids, this is used to track only the new rejection email will be sent in future.
        $newRejectionIds = [];
        /** @var Material $material */
        foreach ($modelMaterials as $material) {
          /** @var RejectionReason $rejection */
          foreach ($material->getRejections() as $rejection) {
            $newRejectionIds[] = $rejection->getDeviationId();
          }
          $emailTracker->setDeviationIds(array_merge($emailTracker->getDeviationIds() ?? [], $newRejectionIds));
          $emailTracker->setSentCount($emailTracker->getSentCount() + 1);
          $rejectionKey = $this->rejectionKey = Md5Utils::md5(sprintf('%s.%s', $modelId, $syliusOrder->getId()));
          $emailTracker->setRejectionKey($rejectionKey);
          $orderRejection->setRejectionKey($rejectionKey);
          $orderRejection->setMaterial($material);

          $orderItemIds = array_unique(
            array_merge($emailTracker->getOrderItemIds(), $orderRejection->getOrderItemIds())
          );
          $emailTracker->setOrderItemIds($orderItemIds);

          $this->orderRejectionEmailManager->sendOrderRejectionEmail($orderRejection);
          $this->rejectionEmailTrackerRepository->saveOrUpdate($emailTracker);
        }
      } catch (\Exception $exception) {
        $this->logger->error($exception->getMessage(), ['stackTrace' => $exception->getTraceAsString()]);
      }
    }
  }

  /**
   * Parse orderInfo from SW order status response to OrderRejection.
   *
   * @return array[]
   */
  private function createOrderRejectionFromOrderInfo(
    array $orderInfo,
    OrderInterface $syliusOrder,
    array $emailTrackersMap
  ): array {
    $modelRejectionByModelId = [];
    $orderProducts = $orderInfo['orderProducts'];
    $orderItemIdByModelMaterialId = $this->getOrderItemIdByModelAndMaterialMap($syliusOrder);

    foreach ($orderProducts as $orderProduct) {
      $models = $orderProduct['models'];
      $materialName = $orderProduct['optionDescription'] ?? '';

      foreach ($models as $model) {
        $modelId = $model['modelId'];
        $rejection = $model['rejection'];
        if (empty($modelId) || empty($rejection)) {
          continue;
        }
        $emailTracker = isset($emailTrackersMap[$modelId]) ? $emailTrackersMap[$modelId] : null;
        $existingDeviationIds = $emailTracker ? $emailTracker->getDeviationIds() ?? [] : [];
        $rejectionReasonList = [];

        $materialId = $model['materialId'];
        $rejectionReasons = $rejection['rejectionReasons'];
        $affectedMaterials = $rejection['affectedMaterials'];

        foreach ($rejectionReasons as $rejectionReason) {
          $deviationId = isset($rejectionReason['deviationId']) ? $rejectionReason['deviationId'] : '';
          //if reason id is in email tracker, means this rejection reason was sent before
          if ($emailTracker && in_array($deviationId, $existingDeviationIds)) {
            continue;
          }

          $reasonId = $rejectionReason['reasonId'];
          $reason = isset($rejectionReason['reason']) ? $rejectionReason['reason'] : '';
          $comment = isset($rejectionReason['comment']) ? $rejectionReason['comment'] : '';

          $rejectionReasonEntity = new RejectionReason($deviationId, $reasonId, $reason, $comment);
          $rejectionReasonEntity->setModelId($model['modelId']);

          foreach ($rejectionReason['images'] as $image) {
            $rejectionReasonEntity->addImage($image);
          }
          $rejectionReasonList[] = $rejectionReasonEntity;
        } //end of rejection loop

        if (empty($rejectionReasonList)) {
          continue;
        }

        $material = new Material($materialId, $materialName, $affectedMaterials, $rejectionReasonList);
        if (!isset($modelRejectionByModelId[$modelId])) {
          $modelRejection = new ModelRejection($syliusOrder, new Model($modelId, $model['title']));
          $modelRejectionByModelId[$modelId] = $modelRejection;
        }

        /** @var ModelRejectionInterface $modelRejection */
        $modelRejection = $modelRejectionByModelId[$modelId];
        $modelRejection->getModel()->addMaterial($material);

        $orderItemId = $orderItemIdByModelMaterialId[$modelId . '_' . $materialId];
        $modelRejection->addOrderItemId($orderItemId);
      } //end of model loop
    }

    return $modelRejectionByModelId;
  }

  private function setModelFixHoldStateForOrder(Order $order): void
  {
    $orderStateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);

    if (Order::STATE_MODEL_FIX_HOLD !== $orderStateMachine->getState()) {
      $orderStateMachine->apply(Order::TRANSITION_REVIEW_MODEL_FIX);
    }
  }

  private function getOrderItemIdByModelAndMaterialMap(Order $order): array
  {
    $orderItemIdByProductConfigId = [];

    /** @var OrderItem $orderItem */
    foreach ($order->getItems() as $orderItem) {
      /** @var ProductVariant $productVariant */
      $productVariant = $orderItem->getVariant();
      $orderItemIdByProductConfigId[$productVariant->getModelMaterialId()] = $orderItem->getId();
    }

    return $orderItemIdByProductConfigId;
  }
}
