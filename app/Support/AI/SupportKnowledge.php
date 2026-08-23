<?php

namespace App\Support\AI;

use App\ValueObjects\AI\SupportKnowledgeTopic;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Reads the curated support knowledge base. The file is authored copy, not user
 * input, so a malformed file is a deployment fault: it fails closed rather than
 * letting the assistant answer from a half-loaded corpus.
 */
final class SupportKnowledge
{
    /** @var list<SupportKnowledgeTopic>|null */
    private ?array $topics = null;

    /** @return list<SupportKnowledgeTopic> */
    public function topics(): array
    {
        return $this->topics ??= $this->load();
    }

    /** @return list<SupportKnowledgeTopic> */
    private function load(): array
    {
        $path = resource_path('ai-assistant/knowledge/arab-ut.json');

        if (! File::exists($path)) {
            throw new RuntimeException('The support knowledge base is missing.');
        }

        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== 'knowledge.v1') {
            throw new RuntimeException('The support knowledge base declares an unsupported version.');
        }

        $topics = $decoded['topics'] ?? null;

        if (! is_array($topics) || $topics === []) {
            throw new RuntimeException('The support knowledge base contains no topics.');
        }

        return array_map(SupportKnowledgeTopic::fromArray(...), array_values($topics));
    }
}
