<?php

namespace App\Console\Commands;

use App\Models\ScheduleOccurrenceOutcome;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PrepareNoShowDataset extends Command
{
    protected $signature = 'noshow:prepare-dataset
        {--approved-real-data : Confirm institutional/supervisor approval is in place}
        {--python=python : Python executable that runs the standard-library preparation script}';

    protected $description = 'Prepare a private outcome dataset and chronological majority baseline';

    public function handle(): int
    {
        if (! $this->option('approved-real-data')) {
            $this->error('Stopped: confirm institutional/supervisor approval before processing real user outcomes.');

            return self::FAILURE;
        }

        $script = base_path('../ml/no-show/src/prepare_dataset.py');
        if (! is_file($script)) {
            $this->error('Dataset preparation script was not found.');

            return self::FAILURE;
        }

        $directory = storage_path('app/private/no-show');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error('Could not create the private no-show output directory.');

            return self::FAILURE;
        }
        @chmod($directory, 0700);

        $input = fopen('php://memory', 'w+');
        if ($input === false) {
            $this->error('Could not create the in-memory data stream.');

            return self::FAILURE;
        }

        $columns = [
            'schedule_id', 'user_id', 'occurrence_start', 'occurrence_end', 'schedule_created_at',
            'category', 'priority', 'outcome', 'recorded_at',
        ];
        fputcsv($input, $columns);
        $missingOccurrenceEnd = 0;
        $outcomeRows = 0;

        foreach (ScheduleOccurrenceOutcome::query()
            ->with('schedule:id,user_id,category,priority,created_at')
            ->orderBy('id')
            ->lazyById(500) as $outcome) {
            if (! $outcome->schedule) {
                continue;
            }

            $outcomeRows++;
            if ($outcome->occurrence_end === null) {
                $missingOccurrenceEnd++;
            }

            fputcsv($input, [
                $outcome->schedule_id,
                $outcome->schedule->user_id,
                $outcome->occurrence_start->format('Y-m-d H:i:s'),
                $outcome->occurrence_end?->format('Y-m-d H:i:s') ?? '',
                $outcome->schedule->created_at->format('Y-m-d H:i:s'),
                $outcome->schedule->category->value,
                $outcome->schedule->priority,
                $outcome->outcome,
                $outcome->recorded_at->format('Y-m-d H:i:s'),
            ]);
        }

        rewind($input);
        $outputPath = $directory.DIRECTORY_SEPARATOR.'occurrence-features.csv';
        $reportPath = $directory.DIRECTORY_SEPARATOR.'baseline-report.json';
        $process = new Process([
            (string) $this->option('python'), $script,
            '--input', '-',
            '--output', $outputPath,
            '--report', $reportPath,
            '--evidence-type', 'approved-real',
        ]);
        $process->setInput($input);
        $process->setTimeout(120);
        $process->run();
        fclose($input);

        if (! $process->isSuccessful()) {
            $this->error(trim($process->getErrorOutput() ?: $process->getOutput()));

            return self::FAILURE;
        }

        $this->line($process->getOutput());
        $this->warn("Processed {$outcomeRows} explicit outcome records; {$missingOccurrenceEnd} lack a stored historical end time and were excluded.");
        $this->info('Derived files are under storage/app/private/no-show. Do not publish or commit them.');

        return self::SUCCESS;
    }
}
