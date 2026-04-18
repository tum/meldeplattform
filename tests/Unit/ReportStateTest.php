<?php

namespace Tests\Unit;

use App\Models\Report;
use Tests\TestCase;

class ReportStateTest extends TestCase
{
    public function test_status_helpers(): void
    {
        $r = new Report;
        $r->state = Report::STATE_OPEN;
        $this->assertFalse($r->isClosed());
        $this->assertFalse($r->isSpam());
        $this->assertSame('Open', $r->statusLabel());

        $r->state = Report::STATE_DONE;
        $this->assertTrue($r->isClosed());
        $this->assertFalse($r->isSpam());
        $this->assertSame('Done', $r->statusLabel());

        $r->state = Report::STATE_SPAM;
        $this->assertFalse($r->isClosed());
        $this->assertTrue($r->isSpam());
        $this->assertSame('Spam', $r->statusLabel());
    }

    public function test_status_color_is_css_safe(): void
    {
        $r = new Report;
        $r->state = Report::STATE_OPEN;
        $this->assertStringNotContainsString(';', $r->statusColor());
        $this->assertStringNotContainsString('<', $r->statusColor());
    }
}
