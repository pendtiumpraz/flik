<?php

namespace App\Contracts\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract for any AI client (chat completion).
 * Future: split AiClient out as microservice — keep this interface stable.
 */
interface AiClientContract
{
    /**
     * Send chat completion. Returns:
     *   ['content' => string, 'tool_calls' => array, 'usage' => array, 'provider' => string, 'model' => string, 'finish_reason' => string]
     *
     * @param  string      $taskType  Free-form label persisted with the usage log (e.g. "chat.recommend").
     * @param  Model|null  $subject   Optional related Eloquent model (Movie, User, ...) for the usage log.
     */
    public function chat(array $messages, array $options = [], string $taskType = 'chat.generic', ?Model $subject = null): array;
}
