<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\PayPal\Tests\Integration\Webhook;

use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\Eshop\Application\Model\Order as EshopModelOrder;
use OxidSolutionCatalysts\PayPal\Core\ServiceFactory;
use OxidSolutionCatalysts\PayPal\Core\Webhook\Handler\PaymentCaptureDeniedHandler;
use OxidSolutionCatalysts\PayPal\Core\Webhook\Event as WebhookEvent;
use OxidSolutionCatalysts\PayPal\Service\OrderRepository;
use OxidSolutionCatalysts\PayPal\Model\PayPalOrder as PayPalOrderModel;
use OxidSolutionCatalysts\PayPalApi\Model\Orders\Order as ApiOrderResponse;
use OxidSolutionCatalysts\PayPalApi\Service\Orders as PayPalApiOrders;
use OxidSolutionCatalysts\PayPal\Exception\WebhookEventException;

final class PaymentCaptureDeniedHandlerTest extends WebhookHandlerBaseTestCase
{
    public function testRequestMissingData(): void
    {
        $event = new WebhookEvent([], 'PAYMENT.CAPTURE.DENIED');

        $this->expectException(WebhookEventException::class);
        $this->expectExceptionMessage(WebhookEventException::mandatoryDataNotFound()->getMessage());

        $handler = oxNew(PaymentCaptureDeniedHandler::class);
        $handler->handle($event);
    }

    public function testEshopOrderNotFoundByPayPalOrderId(): void
    {
        $data = [
            'resource' => [
                'id' => self::TEST_RESOURCE_ID
            ]
        ];
        $event = new WebhookEvent($data, 'PAYMENT.CAPTURE.COMPLETED');

        $this->expectException(WebhookEventException::class);
        $this->expectExceptionMessage(
            WebhookEventException::byPayPalTransactionId(self::TEST_RESOURCE_ID)->getMessage()
        );

        $handler = oxNew(PaymentCaptureDeniedHandler::class);
        $handler->handle($event);
    }

    /**
     * @dataProvider dataProviderWebhookEvent
     */
    public function testPaymentCaptureDenied(string $fixture): void
    {
        $data = $this->getRequestData($fixture);
        $event = new WebhookEvent($data, 'PAYMENT.CAPTURE.DENIED');

        $orderMock = $this->prepareOrderMock('order_oxid', 'markOrderPaymentFailed');
        $paypalOrderMock = $this->preparePayPalOrderMock($data['resource']['id']);

        $orderRepositoryMock = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderRepositoryMock->expects($this->once())
            ->method('getShopOrderByPayPalTransactionId')
            ->willReturn($orderMock);
        $orderRepositoryMock->expects($this->once())
            ->method('paypalOrderByOrderIdAndPayPalId')
            ->willReturn($paypalOrderMock);

        $orderServiceMock = $this->getMockBuilder(PayPalApiOrders::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderServiceMock->expects($this->any())
            ->method('showOrderDetails')
            ->willReturn($this->getOrderDetails());

        $serviceFactoryMock = $this->getMockBuilder(ServiceFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $serviceFactoryMock->expects($this->once())
            ->method('getOrderService')
            ->willReturn($orderServiceMock);

        EshopRegistry::set(ServiceFactory::class, $serviceFactoryMock);

        $handler = $this->getMockBuilder(PaymentCaptureDeniedHandler::class)
            ->setMethods(['getServiceFromContainer'])
            ->getMock();
        $handler->expects($this->any())
            ->method('getServiceFromContainer')
            ->willReturn($orderRepositoryMock);
        $handler->handle($event);
    }

    public function dataProviderWebhookEvent(): array
    {
        return [
            'api_v1' => [
                'payment_capture_denied_v1.json'
            ],
            'api_v2' => [
               'payment_capture_denied_v2.json'
            ],
        ];
    }

    private function getOrderDetails(): ApiOrderResponse
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/checkout_order_completed_with_pui.json');

        return new ApiOrderResponse(json_decode($json, true));
    }
}
