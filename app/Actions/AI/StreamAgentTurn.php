<?php

namespace App\Actions\AI;

use App\Contracts\AI\AgentModelResolver;
use App\Contracts\AI\AgentSleeper;
use App\Contracts\AI\MonotonicClock;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentModelEventType;
use App\Enums\AI\AgentRunStatus;
use App\Exceptions\AI\AgentConfigurationException;
use App\Exceptions\AI\AgentDeadlineExceeded;
use App\Exceptions\AI\InvalidAgentRequestException;
use App\Exceptions\AI\SensitiveAgentContentException;
use App\Models\AgentTurn;
use App\Services\AI\AgentTurnRetryPolicy;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AppStreamEvent;
use App\ValueObjects\Chat\ChatOwner;
use Generator;

final readonly class StreamAgentTurn
{
    public function __construct(
        private AgentRuntimeConfig $config,
        private MonotonicClock $clock,
        private BuildAgentModelRequest $buildAgentModelRequest,
        private AgentModelResolver $agentModelResolver,
        private BlockAgentPromptRange $blockAgentPromptRange,
        private FailAgentTurn $failAgentTurn,
        private StartAgentRun $startAgentRun,
        private FinalizeAgentTurn $finalizeAgentTurn,
        private PrepareAutomaticAgentRetry $prepareAutomaticAgentRetry,
        private AgentTurnRetryPolicy $retryPolicy,
        private AgentSleeper $sleeper,
    ) {}

    /**
     * @return Generator<int, AppStreamEvent, mixed, void>
     */
    public function execute(AgentTurn $turn, ChatOwner $owner, string $displayCurrency): Generator
    {
        yield AppStreamEvent::turnCreated($turn);

        $deadline = AgentDeadline::afterSeconds(
            $this->clock,
            $this->config->requestTimeoutSeconds(),
        );

        try {
            try {
                $deadline->throwIfExpired();
                $request = $this->buildAgentModelRequest->execute($turn, $owner, $displayCurrency);
                $deadline->throwIfExpired();
                $provider = $this->config->provider();
                $agentModel = $this->agentModelResolver->resolve($provider);
                $deadline->throwIfExpired();
            } catch (SensitiveAgentContentException) {
                $this->blockAgentPromptRange->execute($turn);
                $this->failAgentTurn->execute(
                    $turn,
                    null,
                    AgentErrorCode::SensitiveContentBlocked,
                );
                yield AppStreamEvent::failed(
                    $turn->fresh(),
                    AgentErrorCode::SensitiveContentBlocked,
                );

                return;
            } catch (InvalidAgentRequestException) {
                $this->failAgentTurn->execute(
                    $turn,
                    null,
                    AgentErrorCode::InvalidAgentRequest,
                );
                yield AppStreamEvent::failed(
                    $turn->fresh(),
                    AgentErrorCode::InvalidAgentRequest,
                );

                return;
            } catch (AgentConfigurationException) {
                $this->failAgentTurn->execute(
                    $turn,
                    null,
                    AgentErrorCode::ConfigurationInvalid,
                );
                yield AppStreamEvent::failed(
                    $turn->fresh(),
                    AgentErrorCode::ConfigurationInvalid,
                );

                return;
            }

            $automatic429Used = false;

            while ($turn->fresh()->attempt_count < $this->config->maxAttempts()) {
                $deadline->throwIfExpired();
                $run = $this->startAgentRun->execute($turn, $provider);
                $startedAt = $this->clock->nowMilliseconds();
                $text = '';

                foreach ($agentModel->stream($request, $deadline) as $providerEvent) {
                    $deadline->throwIfExpired();

                    if ($providerEvent->type === AgentModelEventType::Delta) {
                        $remaining = max(
                            0,
                            $this->config->maxResponseCharacters() - mb_strlen($text),
                        );
                        $visibleDelta = mb_substr((string) $providerEvent->delta, 0, $remaining);
                        $text .= $visibleDelta;

                        if ($visibleDelta !== '') {
                            yield AppStreamEvent::delta($turn->public_id, $visibleDelta);
                        }

                        continue;
                    }

                    if ($providerEvent->type === AgentModelEventType::Completed) {
                        $message = $this->finalizeAgentTurn->execute(
                            $turn,
                            $run,
                            $text,
                            $providerEvent,
                            max(0, $this->clock->nowMilliseconds() - $startedAt),
                        );
                        yield AppStreamEvent::completed($turn->fresh(), $message);

                        return;
                    }

                    $errorCode = $providerEvent->errorCode
                        ?? AgentErrorCode::ProviderTerminalFailure;
                    $runningTurn = $turn->fresh();

                    if ($this->retryPolicy->canAutomaticallyRetry(
                        $runningTurn,
                        $run,
                        $errorCode,
                    ) && ! $automatic429Used) {
                        $automatic429Used = true;
                        $this->prepareAutomaticAgentRetry->execute(
                            $runningTurn,
                            $run,
                        );
                        $waitMilliseconds = min(
                            $providerEvent->retryAfterMilliseconds ?? 0,
                            $this->config->retryAfterCapMilliseconds(),
                            $deadline->remainingMilliseconds(),
                        );
                        $this->sleeper->sleepMilliseconds(
                            $waitMilliseconds,
                            $deadline,
                        );

                        continue 2;
                    }

                    $this->failAgentTurn->execute($turn, $run, $errorCode);
                    $failedTurn = $turn->fresh();
                    yield AppStreamEvent::failed($failedTurn, $errorCode);

                    return;
                }

                $this->failAgentTurn->execute(
                    $turn,
                    $run,
                    AgentErrorCode::ProviderIncomplete,
                );
                yield AppStreamEvent::failed(
                    $turn->fresh(),
                    AgentErrorCode::ProviderIncomplete,
                );

                return;
            }
        } catch (AgentDeadlineExceeded) {
            $timeoutRun = null;

            if (isset($run) && $run->fresh()->status === AgentRunStatus::Running) {
                $timeoutRun = $run;
            }
            $this->failAgentTurn->execute(
                $turn,
                $timeoutRun,
                AgentErrorCode::ProviderTimeout,
            );
            yield AppStreamEvent::failed(
                $turn->fresh(),
                AgentErrorCode::ProviderTimeout,
            );
        }
    }
}
