<?php

namespace App\Commands;

use App\Facades\Git;
use GrahamCampbell\GitHub\Facades\GitHub;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class UpdateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'update';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'composer update';

    /**
     * @var string
     */
    protected string $repo;

    /**
     * @var string
     */
    protected string $base_path;

    /**
     * @var string
     */
    protected string $parent_branch;

    /**
     * @var string
     */
    protected string $new_branch;

    /**
     * @var string
     */
    protected string $out;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->init();

        if (! $this->exists()) {
            return;
        }

        $this->process('install');

        $output = $this->process(
            'update',
            [
                env('COMPOSER_PACKAGES', ''),
                env('COMPOSER_PACKAGES') ? '--with-dependencies' : '',
            ]
        );

        $this->output($output);

        if (! Git::hasChanges()) {
            $this->info('no changes');

            return;
        }

        $this->commitPush();

        $this->createPullRequest();
    }

    /**
     * @return void
     */
    protected function init(): void
    {
        $this->info('init');

        $this->repo = env('GITHUB_REPOSITORY', '');

        $this->base_path = env('GITHUB_WORKSPACE', '').env('COMPOSER_PATH', '');

        $this->parent_branch = Git::getCurrentBranchName();

        $this->new_branch = 'cu/'.Str::random(8);
        if (env('APP_SINGLE_BRANCH')) {
            $this->new_branch = $this->parent_branch . env('APP_SINGLE_BRANCH_POSTFIX', '-updated');

            $this->info('Using single-branch approach. Branch name: ' . $this->new_branch);
        }

        $token = env('GITHUB_TOKEN');

        GitHub::authenticate($token, 'http_token');

        Git::setRemoteUrl(
            'origin',
            "https://{$token}@github.com/{$this->repo}.git"
        );

        Git::execute(['config', '--local', 'user.name', env('GIT_NAME', 'cu')]);
        Git::execute(['config', '--local', 'user.email', env('GIT_EMAIL', 'cu@composer-update')]);

        if (!env('APP_SINGLE_BRANCH') || !in_array($this->new_branch, Git::getBranches() ?? [])) {
            $this->info('Creating branch ' . $this->new_branch);

            Git::createBranch($this->new_branch, true);
        } elseif (!env('APP_SINGLE_BRANCH')) {
            $this->info('Merging from ' . $this->parent_branch);

            Git::merge($this->parent_branch, [
                '--strategy=theirs',
                '--quiet',
            ]);
        }

        $this->token();
    }

    /**
     * @return bool
     */
    protected function exists(): bool
    {
        return File::exists($this->base_path.'/composer.json')
            && File::exists($this->base_path.'/composer.lock');
    }

    /**
     * @param  string  $command
     *
     * @return string
     */
    protected function process(string $command, array $arguments = []): string
    {
        $this->info($command . ' ' . implode(' ', $arguments));

        /**
         * @var Process $process
         */
        $process = app('process.'.$command, $arguments)
            ->setWorkingDirectory($this->base_path)
            ->setTimeout(600)
            ->setEnv(
                [
                    'COMPOSER_MEMORY_LIMIT' => '-1',
                ]
            )
            ->mustRun();

        $output = $process->getOutput();
        if (blank($output)) {
            $output = $process->getErrorOutput();
        }

        return $output ?? '';
    }

    /**
     * Set GitHub token for composer.
     *
     * @return void
     */
    protected function token(): void
    {
        /**
         * @var Process $process
         */
        $process = app('process.token')
            ->setWorkingDirectory($this->base_path)
            ->setTimeout(60)
            ->mustRun();
    }

    /**
     * @param  string  $output
     *
     * @return void
     */
    protected function output(string $output): void
    {
        $this->out = Str::of($output)
                        ->explode(PHP_EOL)
                        ->filter(fn ($item) => Str::contains($item, ' - '))
                        ->reject(fn ($item) => Str::contains($item, 'Downloading '))
                        ->takeUntil(fn ($item) => Str::contains($item, ':'))
                        ->implode(PHP_EOL).PHP_EOL;

        $this->line($this->out);
    }

    /**
     * @return void
     */
    protected function commitPush(): void
    {
        $this->info('commit');

        Git::addAllChanges()
           ->commit(env('GIT_COMMIT_PREFIX', '') . 'composer update ' . today()->toDateString() . PHP_EOL . PHP_EOL . $this->out)
           ->push('origin', [$this->new_branch, '--force']);
    }

    /**
     * @return void
     */
    protected function createPullRequest(): void
    {
        $this->info('Pull Request');

        $date = env('APP_SINGLE_BRANCH') ? '' : today()->toDateString();

        $pullData = [
            'base'  => Str::afterLast(env('GITHUB_REF'), '/'),
            'head'  => $this->new_branch,
            'title' => env('GIT_COMMIT_PREFIX', '') . 'Composer update ' . $date,
            'body'  => $this->out,
        ];

        $createPullRequest = true;

        if (env('APP_SINGLE_BRANCH')) {
            $pullRequests = Github::pullRequest()->all(
                Str::before($this->repo, '/'),
                Str::afterLast($this->repo, '/'),
                [
                    'base' => $this->parent_branch,
                    'state' => 'open'
                ]
            );

            if (count($pullRequests) > 0) {
                $createPullRequest = false;
            }
        }

        if ($createPullRequest) {
            GitHub::pullRequest()->create(
                Str::before($this->repo, '/'),
                Str::afterLast($this->repo, '/'),
                $pullData
            );
        }
    }
}
