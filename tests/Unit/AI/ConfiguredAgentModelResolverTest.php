<?php

use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentProvider;
use App\Services\AI\FakeAgentModel;
use App\Services\AI\OpenAiResponsesAgentModel;
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

test('resolver defers openai adapter construction until openai resolution is requested', function () {
    expect(app()->resolved(OpenAiResponsesAgentModel::class))->toBeFalse()
        ->and(app()->resolved(FakeAgentModel::class))->toBeFalse();

    $resolver = app(AgentModelResolver::class);

    expect($resolver)->toBeInstanceOf(AgentModelResolver::class)
        ->and(app()->resolved(OpenAiResponsesAgentModel::class))->toBeFalse()
        ->and($resolver->resolve(AgentProvider::OpenAi))->toBeInstanceOf(OpenAiResponsesAgentModel::class)
        ->and(app()->resolved(OpenAiResponsesAgentModel::class))->toBeTrue()
        ->and(app()->resolved(FakeAgentModel::class))->toBeFalse();
});
