<?php

namespace App\Enums\AI;

enum AgentErrorCode: string
{
    case RateLimited = 'rate_limited';
    case ProviderConnectionFailed = 'provider_connection_failed';
    case ProviderTimeout = 'provider_timeout';
    case ProviderServerError = 'provider_server_error';
    case ProviderIncomplete = 'provider_incomplete';
    case StreamTerminated = 'stream_terminated';
    case StaleTurnRecovered = 'stale_turn_recovered';
    case SensitiveContentBlocked = 'sensitive_content_blocked';
    case ConfigurationInvalid = 'configuration_invalid';
    case InvalidAgentRequest = 'invalid_agent_request';
    case ProviderAuthenticationFailed = 'provider_authentication_failed';
    case ProviderPermissionDenied = 'provider_permission_denied';
    case ProviderRequestRejected = 'provider_request_rejected';
    case ProviderMalformed = 'provider_malformed';
    case ProviderTerminalFailure = 'provider_terminal_failure';
    case Cancelled = 'cancelled';

    public function isTransient(): bool
    {
        return match ($this) {
            self::RateLimited,
            self::ProviderConnectionFailed,
            self::ProviderTimeout,
            self::ProviderServerError,
            self::ProviderIncomplete,
            self::StreamTerminated,
            self::StaleTurnRecovered => true,
            default => false,
        };
    }
}
