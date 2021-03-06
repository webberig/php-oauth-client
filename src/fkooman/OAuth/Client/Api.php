<?php

/**
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Lesser General Public License for more details.
 *
 *  You should have received a copy of the GNU Lesser General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace fkooman\OAuth\Client;

/**
 * API for talking to OAuth 2.0 protected resources.
 *
 * @author François Kooman <fkooman@tuxed.net>
 */
class Api
{
    const RANDOM_LENGTH = 8;

    private $clientConfigId;
    private $clientConfig;
    private $tokenStorage;
    private $httpClient;

    public function __construct($clientConfigId, ClientConfigInterface $clientConfig, StorageInterface $tokenStorage, \Guzzle\Http\Client $httpClient)
    {
        $this->setClientConfigId($clientConfigId);
        $this->setClientConfig($clientConfig);
        $this->setTokenStorage($tokenStorage);
        $this->setHttpClient($httpClient);
    }

    public function setClientConfigId($clientConfigId)
    {
        if (!is_string($clientConfigId) || 0 >= strlen($clientConfigId)) {
            throw new ApiException("clientConfigId should be a non-empty string");
        }
        $this->clientConfigId = $clientConfigId;
    }

    public function setClientConfig(ClientConfigInterface $clientConfig)
    {
        $this->clientConfig = $clientConfig;
    }

    public function setTokenStorage(StorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;
    }

    public function setHttpClient(\Guzzle\Http\Client $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function getRefreshToken(Context $context)
    {
        return $this->tokenStorage->getRefreshToken($this->clientConfigId, $context->getUserId(), $context->getScope());
    }

    public function getAccessToken(Context $context)
    {
        // do we have a valid access token?
        $accessToken = $this->tokenStorage->getAccessToken($this->clientConfigId, $context->getUserId(), $context->getScope());
        if (false !== $accessToken) {
            // check if expired
            if ($accessToken->getIssueTime() + $accessToken->getExpiresIn() < time()) {
                // expired, delete it
                $this->tokenStorage->deleteAccessToken($accessToken);

                return false;
            }

            return $accessToken;
        }

        // no valid access token, is there a refresh_token?
        $refreshToken = $this->getRefreshToken($context);
        if (false !== $refreshToken) {
            // obtain a new access token with refresh token
            $tokenRequest = new TokenRequest($this->httpClient, $this->clientConfig);
            $tokenResponse = $tokenRequest->withRefreshToken($refreshToken->getRefreshToken());
            if (false === $tokenResponse) {
                // unable to fetch with RefreshToken, delete it
                $this->tokenStorage->deleteRefreshToken($refreshToken);

                return false;
            }
            // we got a new token
            $scope = (null !== $tokenResponse->getScope()) ? $tokenResponse->getScope() : $context->getScope();
            $accessToken = new AccessToken(
                array(
                    "client_config_id" => $this->clientConfigId,
                    "user_id" => $context->getUserId(),
                    "scope" => $scope,
                    "access_token" => $tokenResponse->getAccessToken(),
                    "token_type" => $tokenResponse->getTokenType(),
                    "issue_time" => time(),
                    "expires_in" => $tokenResponse->getExpiresIn()
                )
            );
            $this->tokenStorage->storeAccessToken($accessToken);
            if (null !== $tokenResponse->getRefreshToken()) {
                $refreshToken = new RefreshToken(
                    array(
                        "client_config_id" => $this->clientConfigId,
                        "user_id" => $context->getUserId(),
                        "scope" => $scope,
                        "refresh_token" => $tokenResponse->getRefreshTokenToken(),
                        "issue_time" => time()
                    )
                );
                $this->tokenStorage->storeRefreshToken($refreshToken);
            }

            return $accessToken;
        }
        // no access token, and refresh token didn't work either or was not there, probably the tokens were revoked
        return false;
    }

    public function deleteAccessToken(Context $context)
    {
        $accessToken = $this->getAccessToken($context);
        if (false !== $accessToken) {
            $this->tokenStorage->deleteAccessToken($accessToken);
        }
    }

    public function deleteRefreshToken(Context $context)
    {
        $refreshToken = $this->getRefreshToken($context);
        if (false !== $refreshToken) {
            $this->tokenStorage->deleteRefreshToken($refreshToken);
        }
    }

    public function getAuthorizeUri(Context $context, $stateValue = null)
    {
        // allow caller to override a random generated state
        // FIXME: is this actually used anywhere?
        if (null === $stateValue) {
            $stateValue = bin2hex(openssl_random_pseudo_bytes(self::RANDOM_LENGTH));
        } else {
            if (!is_string($stateValue) || 0 >= strlen($stateValue)) {
                throw new ApiException("state should be a non-empty string");
            }
        }

        // try to get a new access token
        $this->tokenStorage->deleteStateForUser($this->clientConfigId, $context->getUserId());
        $state = new State(
            array(
                "client_config_id" => $this->clientConfigId,
                "user_id" => $context->getUserId(),
                "scope" => $context->getScope(),
                "issue_time" => time(),
                "state" => $stateValue
            )
        );
        if (false === $this->tokenStorage->storeState($state)) {
            throw new ApiException("unable to store state");
        }

        $q = array (
            "client_id" => $this->clientConfig->getClientId(),
            "response_type" => "code",
            "state" => $state->getState(),
        );
        if (null !== $context->getScope()) {
            $q['scope'] = $context->getScope();
        }
        if ($this->clientConfig->getRedirectUri()) {
            $q['redirect_uri'] = $this->clientConfig->getRedirectUri();
        }

        $separator = (false === strpos($this->clientConfig->getAuthorizeEndpoint(), "?")) ? "?" : "&";
        $authorizeUri = $this->clientConfig->getAuthorizeEndpoint() . $separator . http_build_query($q, null, '&');

        return $authorizeUri;
    }
}
