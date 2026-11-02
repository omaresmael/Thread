<?php

namespace Omaresmael\Thread\Tests\Unit;

use Omaresmael\Thread\Exceptions\ThreadUnavailableException;
use Omaresmael\Thread\Requirements;
use Omaresmael\Thread\Tests\TestCase;

class RequirementsTest extends TestCase
{
    public function test_reports_each_missing_requirement(): void
    {
        $this->assertSame([], Requirements::missing(true, true));
        $this->assertSame(['PHP is not thread-safe (ZTS)', 'ext-parallel is not loaded'], Requirements::missing(false, false));
        $this->assertSame(['ext-parallel is not loaded'], Requirements::missing(true, false));
    }

    public function test_ensure_throws_on_this_runtime_when_unsupported(): void
    {
        if (PHP_ZTS && extension_loaded('parallel')) {
            Requirements::ensure();
            $this->addToAssertionCount(1);

            return;
        }
        $this->expectException(ThreadUnavailableException::class);
        $this->expectExceptionMessage('omaresmael/thread requires ZTS PHP with ext-parallel');
        Requirements::ensure();
    }
}
