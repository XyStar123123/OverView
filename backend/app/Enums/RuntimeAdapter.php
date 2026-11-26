<?php

namespace App\Enums;

enum RuntimeAdapter: string
{
    case Mock = 'mock';
    case OpenHands = 'openhands';
    case LangGraph = 'langgraph';
    case Custom = 'custom';
}
