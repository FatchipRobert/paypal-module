<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidSolutionCatalysts\PayPal\Core\Webhook\Handler;

use OxidEsales\Eshop\Application\Model\Order as EshopModelOrder;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\PayPal\Model\PayPalOrder as PayPalModelOrder;
use OxidSolutionCatalysts\PayPalApi\Exception\ApiException;
use OxidSolutionCatalysts\PayPalApi\Model\Orders\Capture;
use OxidSolutionCatalysts\PayPalApi\Model\Orders\Order as OrderResponse;
use OxidSolutionCatalysts\PayPalApi\Model\Orders\OrderCaptureRequest;
use OxidSolutionCatalysts\PayPal\Core\Constants;
use OxidSolutionCatalysts\PayPal\Core\ServiceFactory;
use Psr\Log\LoggerInterface;

class CheckoutOrderApprovedHandler extends WebhookHandlerBase
{
    public const WEBHOOK_EVENT_NAME = 'CHECKOUT.ORDER.APPROVED';

    public function handleWebhookTasks(
        PayPalModelOrder $paypalOrderModel,
        string $payPalTransactionId,
        string $payPalOrderId,
        array $eventPayload,
        EshopModelOrder $order
    ): void {
        if ($this->needsCapture($eventPayload)) {
            try {
                //NOTE: capture will trigger CHECKOUT.ORDER.COMPLETED event which will mark order paid
                $this->getPaymentService()
                    ->doCapturePayPalOrder(
                        $order,
                        $payPalOrderId,
                        $paypalOrderModel->getPaymentMethodId()
                    );
                $order->setOrderNumber(); //ensure the order has a number
            } catch (\Exception $exception) {
                /** @var LoggerInterface $logger */
                $logger = $this->getServiceFromContainer('OxidSolutionCatalysts\PayPal\Logger');
                $logger->debug(
                    "Error during " . self::WEBHOOK_EVENT_NAME . " for PayPal order_id '" .
                    $payPalOrderId . "'",
                    [$exception]
                );
            }
        }
    }

    protected function getPayPalOrderIdFromResource(array $eventPayload): string
    {
        return (string) $eventPayload['id'];
    }

    protected function getPayPalTransactionIdFromResource(array $eventPayload): string
    {
        $transactionId = isset($eventPayload['payments']['captures'][0]) ?
            $eventPayload['payments']['captures'][0]['id'] : '';

        return $transactionId;
    }

    protected function getStatusFromResource(array $eventPayload): string
    {
        return isset($eventPayload['status']) ? $eventPayload['status'] : '';
    }

    /**
     * Captures payment for given order
     *
     * @param string $orderId
     *
     * @return OrderResponse
     * @throws ApiException
     */
    private function capturePayment(string $orderId): OrderResponse
    {
        /** @var ServiceFactory $serviceFactory */
        $serviceFactory = Registry::get(ServiceFactory::class);
        $service = $serviceFactory->getOrderService();
        $request = new OrderCaptureRequest();

        return $service->capturePaymentForOrder('', $orderId, $request, '');
    }

    private function needsCapture(array $eventPayload): bool
    {
        return !$this->isCompleted($eventPayload) &&
            isset($eventPayload['intent']) &&
            ($eventPayload['intent'] === Constants::PAYPAL_ORDER_INTENT_CAPTURE);
    }

    private function isCompleted(array $eventPayload): bool
    {
        return (
            isset($eventPayload['status']) &&
            isset($eventPayload['purchase_units'][0]['payments']['captures'][0]['status']) &&
            $this->getStatusFromResource($eventPayload) == OrderResponse::STATUS_COMPLETED &&
            $eventPayload['purchase_units'][0]['payments']['captures'][0]['status'] == Capture::STATUS_COMPLETED
        );
    }
}
