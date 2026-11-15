<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Every minute: tasks whose reminder time has come get one notification (in the app, and on the phone through mobile:push). */
final class TasksRemindCommand extends Command
{
    protected $signature = 'tasks:remind';

    protected $description = 'Send due task reminders as notifications';

    public function handle(): int
    {
        $n = (int) DB::connection('admin')->selectOne('select task_reminders_dispatch(?) as r', [200])->r;
        if ($n > 0) {
            $this->line("reminders {$n}");
        }

        return self::SUCCESS;
    }
}
