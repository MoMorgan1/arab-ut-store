<?php

use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentProvider;
use App\Exceptions\AI\AgentConfigurationException;
use App\Services\AI\FakeAgentModel;
use Tests\TestCase;

uses(TestCase::class);

test('resolver defers fake adapter construction until fake resolution is requested', function () {
    expect(app()->resolved(FakeAgentModel::class))->toBeFalse();

    $resolver = app(AgentModelResolver::class);

    expect($resolver)->toBeInstanceOf(AgentModelResolver::class)
        ->and(app()->resolved(FakeAgentModel::class))->toBeFalse()
        ->and($resolver->resolve(AgentProvider::Fake))->toBeInstanceOf(FakeAgentModel::class)
        ->and(app()->resolved(FakeAgentModel::class))->toBeTrue();
});

test('unsupported providers fail closed without constructing the fake adapter', function () {
    try {
        app(AgentModelResolver::class)->resolve(AgentProvider::OpenAi);
    } catch (AgentConfigurationException $exception) {
        expect($exception->errorCode)->toBe(AgentErrorCode::ConfigurationInvalid)
            ->and(app()->resolved(FakeAgentModel::class))->toBeFalse();

        return;
    }

    $this->fail('Expected unsupported provider resolution to throw.');
});
