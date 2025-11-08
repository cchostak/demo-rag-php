<?php

if (!function_exists('askOpenAI')) {
    function askOpenAI(array $messages, string $model) {
        global $openaiApiKey;
        if (empty($openaiApiKey)) {
            throw new Exception('OPENAI_API_KEY is not set');
        }
        $client = \OpenAI::client($openaiApiKey);
        $response = $client->chat()->create([
            'model' => $model,
            'messages' => $messages
        ]);
        return $response->choices[0]->message->content;
    }
}
