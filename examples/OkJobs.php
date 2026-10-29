<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

abstract class Job implements ShouldQueue
{
    use SerializesModels;
}

class ExportInvoices extends Job
{
    public function __construct(public int $userId, public string $format)
    {
    }

    public function handle(): void
    {
        Invoice::export($this->userId, $this->format);
    }
}

class HomeController
{
    public function index()
    {
        return request('q');
    }
}
