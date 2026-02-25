<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScrapeAll extends Command
{
    protected $signature = 'scrape:all {--continue-on-error}';

    protected $description = 'Run all project scrapers in sequence.';

    public function handle(): int
    {
        $commands = [
            [
                'key' => 'barrie-bids-tenders',
                'command' => 'scrape:barrie-tenders',
                'params' => ['limit'],
            ],
            [
                'key' => 'windsor-bids-tenders',
                'command' => 'scrape:windsor-bids',
                'params' => [],
            ],
            [
                'key' => 'toronto-bids-portal',
                'command' => 'scrape:toronto-bids',
                'params' => ['limit'],
            ],
            [
                'key' => 'merx-ottawa',
                'command' => 'scrape:ottawa-merx',
                'params' => ['max_pages'],
            ],
            [
                'key' => 'pei-tenders',
                'command' => 'scrape:pei-tenders',
                'params' => ['years'],
            ],
            [
                'key' => 'nova-scotia-procurement',
                'command' => 'scrape:nova-scotia',
                'params' => ['pages'],
            ],
            [
                'key' => 'infrastructure-ontario-projects',
                'command' => 'scrape:infrastructure-ontario',
                'params' => ['pages'],
            ],
            [
                'key' => 'sasktenders',
                'command' => 'scrape:sasktenders',
                'params' => ['pages'],
            ],
            [
                'key' => 'alberta-purchasing',
                'command' => 'scrape:alberta-purchasing',
                'params' => ['limit', 'pages'],
            ],
            [
                'key' => 'kenora-tenders',
                'command' => 'scrape:kenora-tenders',
                'params' => [],
            ],
            [
                'key' => 'bc-bid',
                'command' => 'scrape:bc-bid',
                'params' => ['expected_count', 'session_id', 'csrf_token'],
            ],
            [
                'key' => 'ontario-highway-programs',
                'command' => 'scrape:ontario-highway-programs',
                'params' => [],
            ],
        ];

        $settings = DB::table('scraper_settings')->get()->keyBy('source_site_key');

        $failed = false;

        foreach ($commands as $entry) {
            try {
                $command = $entry['command'];
                $setting = $settings->get($entry['key']);

                if ($setting && $setting->is_enabled === false) {
                    $this->warn("Skipping {$entry['command']} (disabled). ");
                    continue;
                }

                $options = [];
                $config = is_string($setting?->settings) ? json_decode($setting->settings, true) : ($setting?->settings ?? []);

                foreach ($entry['params'] as $param) {
                    if (isset($config[$param]) && $config[$param] !== '') {
                        $options[$this->normalizeOptionKey($param)] = $config[$param];
                    }
                }

                $this->info("Running {$command}...");
                $status = $this->call($command, $options);

                if ($status !== Command::SUCCESS) {
                    $this->error("{$command} failed with status {$status}.");
                    $failed = true;

                    if (!$this->option('continue-on-error')) {
                        return Command::FAILURE;
                    }
                }
            } catch (Throwable $exception) {
                $this->error("{$command} threw an exception: {$exception->getMessage()}");
                $failed = true;

                if (!$this->option('continue-on-error')) {
                    return Command::FAILURE;
                }
            }
        }

        if ($failed) {
            $this->warn('Completed with failures.');
            return Command::FAILURE;
        }

        $this->info('All scrapers completed successfully.');
        return Command::SUCCESS;
    }

    private function normalizeOptionKey(string $param): string
    {
        return match ($param) {
            'session_id' => 'session',
            'csrf_token' => 'csrf',
            'expected_count' => 'expected-count',
            'max_pages' => 'max-pages',
            default => str_replace('_', '-', $param),
        };
    }
}
