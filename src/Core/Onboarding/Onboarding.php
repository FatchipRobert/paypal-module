<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidSolutionCatalysts\PayPal\Core\Onboarding;

use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\PayPalApi\Onboarding as ApiOnboardingClient;
use OxidSolutionCatalysts\PayPal\Core\Config as PayPalConfig;
use OxidSolutionCatalysts\PayPal\Core\Constants;
use OxidSolutionCatalysts\PayPal\Core\PayPalSession;
use OxidSolutionCatalysts\PayPal\Traits\ServiceContainer;
use OxidSolutionCatalysts\PayPal\Service\ModuleSettings;
use OxidSolutionCatalysts\PayPal\Exception\OnboardingException;
use OxidSolutionCatalysts\PayPalApi\Exception\ApiException;

class Onboarding
{
    use ServiceContainer;

    public function autoConfigurationFromCallback(): array
    {
        $credentials = [];
        try {
            //fetch and save credentials
            $credentials = $this->fetchCredentials();
            $this->saveCredentials($credentials);
        } catch (\Exception $exception) {
            throw OnboardingException::autoConfiguration($exception->getMessage());
        }

        return $credentials;
    }

    public function fetchCredentials(): array
    {
        $credentials = [];

        $onboardingResponse = $this->getOnboardingPayload();
        $this->saveSandboxMode($onboardingResponse['isSandBox']);

        $nonce = Registry::getSession()->getVariable('PAYPAL_MODULE_NONCE');
        Registry::getSession()->deleteVariable('PAYPAL_MODULE_NONCE');

        try {
            /** @var ApiOnboardingClient $apiClient */
            $apiClient = $this->getOnboardingClient($onboardingResponse['isSandBox']);
            $apiClient->authAfterWebLogin($onboardingResponse['authCode'], $onboardingResponse['sharedId'], $nonce);

            $credentials = $apiClient->getCredentials();
        } catch (ApiException $exception) {
            Registry::getLogger()->error($exception->getMessage(), [$exception]);
        }

        return $credentials;
    }

    public function getOnboardingPayload(): array
    {
        $response = json_decode(PayPalSession::getOnboardingPayload(), true);

        if (!is_array($response) ||
            !isset($response['authCode']) ||
            !isset($response['sharedId']) ||
            !isset($response['isSandBox'])
        ) {
            throw OnboardingException::mandatoryDataNotFound();
        }

        return $response;
    }

    public function saveSandboxMode(bool $isSandbox): void
    {
        $moduleSettings = $this->getServiceFromContainer(ModuleSettings::class);
        $moduleSettings->saveSandboxMode($isSandbox);
    }

    public function saveCredentials(array $credentials): array
    {
        if (!isset($credentials['client_id']) ||
            !isset($credentials['client_secret'])
        ) {
            throw OnboardingException::mandatoryDataNotFound();
        }

        $moduleSettings = $this->getServiceFromContainer(ModuleSettings::class);
        $moduleSettings->saveClientId($credentials['client_id']);
        $moduleSettings->saveClientSecret($credentials['client_secret']);

        return [
            'client_id' => $moduleSettings->getClientId(),
            'client_secret' => $moduleSettings->getClientSecret()
        ];
    }

    public function getOnboardingClient(bool $isSandbox): ApiOnboardingClient
    {
        $paypalConfig = oxNew(PayPalConfig::class);

        $client = new ApiOnboardingClient(
            Registry::getLogger(),
            $isSandbox ? $paypalConfig->getClientSandboxUrl() : $paypalConfig->getClientLiveUrl(),
            '',
            '',
            PayPalSession::getMerchantIdInPayPal()
    );

        return $client;
    }
}
