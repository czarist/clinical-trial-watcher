<?php

declare(strict_types=1);

return [

    /*
    | AI-written summaries in paid digests. When disabled, or when the provider fails or
    | returns invalid output, digests use the factual summary built from the recorded
    | changes. Basic alerts never depend on this.
    */
    'enabled' => (bool) env('AI_DIGESTS_ENABLED', false),

    /*
    | Any OpenAI-compatible chat completions API: base URL (ending before /chat/completions),
    | server-side API key and model name.
    */
    'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),

    'api_key' => env('AI_API_KEY'),

    'model' => env('AI_MODEL'),

    'timeout' => (int) env('AI_TIMEOUT', 30),

    /*
    | Bounded retries after a failed provider call, and the pause between them.
    */
    'retries' => (int) env('AI_RETRIES', 2),

    'retry_sleep_ms' => (int) env('AI_RETRY_SLEEP_MS', 2000),

    /*
    | Cost controls: characters of registry facts sent per request, output tokens per
    | request, and studies described in one digest (the rest are counted).
    */
    'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 12000),

    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1200),

    'max_items' => (int) env('AI_DIGEST_MAX_ITEMS', 10),

    'max_summary_chars' => 600,

    /*
    | Part of the summary cache key: change it when the prompt changes so cached summaries
    | written with the old prompt are not reused.
    */
    'prompt_version' => 'digest-v1',

];
