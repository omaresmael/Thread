<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;
use Monolog\ResettableInterface;

/**
 * Lets Monolog handlers and processors that buffer (or count) records start the next task clean.
 */
final class FlushMonologState implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (! $sandbox->resolved('log')) {
            return;
        }

        foreach ($sandbox->make('log')->getChannels() as $channel) {
            $logger = method_exists($channel, 'getLogger') ? $channel->getLogger() : $channel;

            if ($logger instanceof ResettableInterface) {
                $logger->reset();
            }
        }
    }
}
