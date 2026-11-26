<?php

namespace App\Enums;

enum GatewayType: string
{
    case Mock = 'mock';
    case OpenAiCompatible = 'openai_compatible';
    case LiteLLM = 'litellm';
    case OmniRoute = 'omni_route';
    case Custom = 'custom';
}
