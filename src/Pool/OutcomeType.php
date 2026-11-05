<?php

namespace Omaresmael\Thread\Pool;

enum OutcomeType
{
    case Result;
    case Timeout;
    case Crash;
}
