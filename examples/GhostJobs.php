<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;

class ExportInvoices implements ShouldQueue
{
    public function __construct(public Request $request)
    {
    }

    public function handle(): void
    {
        $userId = auth()->id();
        $format = request('format');
    }
}

class WelcomeMail implements ShouldQueue
{
    public function content(): void
    {
        $email = session('pending_email');
    }
}
