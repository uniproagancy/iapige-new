<?php

return [
    'managed' => true,
    'enabled' => env('IAPI_SALES_CHAT_ENABLED', false),
    'openai_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('IAPI_SALES_CHAT_MODEL', 'gpt-6.1-terra'),
    'reasoning_effort' => env('IAPI_SALES_CHAT_REASONING', 'low'),
];
