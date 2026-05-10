<?php

namespace App\Contracts\Ai;

/**
 * Contract for any AI client (chat completion).
 * Future: split AiClient out as microservice — keep this interface stable.
 */
interface AiClientContract
{
    /**
     * Send chat completion. Returns:
     *   ['content' => string, 'tool_calls' => array, 'usage' => array, 'provider' => string, 'model' => string, 'finish_reason' => string]
     */
    public function chat(array $messages, array $options = []): array;
}
