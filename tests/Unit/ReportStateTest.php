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
        $this->assertSame(__('status_open'), $r->statusLabel());

        $r->state = Report::STATE_DONE;
        $this->assertTrue($r->isClosed());
        $this->assertFalse($r->isSpam());
        $this->assertSame(__('status_done'), $r->statusLabel());

        $r->state = Report::STATE_SPAM;
        $this->assertFalse($r->isClosed());
        $this->assertTrue($r->isSpam());
        $this->assertSame(__('status_spam'), $r->statusLabel());
    }
}
