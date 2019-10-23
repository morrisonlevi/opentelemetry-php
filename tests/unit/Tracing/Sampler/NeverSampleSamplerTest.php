<?php

require __DIR__.'/../../../../vendor/autoload.php';

use OpenTelemetry\Tracing\Sampler\NeverSampleSampler;
use PHPUnit\Framework\TestCase;

class NeverSamplerTest extends TestCase
{
    public function testNeverSampler()
    {
        $sampler = new NeverSampleSampler();
        $this->assertFalse($sampler->shouldSample());
    }
}