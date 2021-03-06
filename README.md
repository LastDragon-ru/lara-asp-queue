# Queue Helpers

> This package is the part of Awesome Set of Packages for Laravel.
>
> [Read more](https://github.com/LastDragon-ru/lara-asp).

This package provides additional capabilities for queued jobs and queued listeners like multilevel configuration support, job overriding (very useful for package development to provide base implementation and allow the application to extend it), easy define for cron jobs, and DI in constructor support.

# Installation

1. Run
    ```shell
    composer require lastdragon-ru/lara-asp-queue
    ```
1. Overwrite default event Dispatcher by adding following code into `bootstrap/app.php` (before all others singletons):
   ```php
   $app->singleton('events', \LastDragon_ru\LaraASP\Queue\EventsDispatcher::class);
   ```

   This is required if you want use configuration/DI for queued Listeners. Please see https://github.com/laravel/framework/issues/25272 for reason.

# Configuration

To add the configuration for job/listener/mailable you just need extends one the [base classes](https://github.com/LastDragon-ru/lara-asp/tree/master/packages/queue/src/Queueables):

```php
<?php declare(strict_types = 1);

namespace App\Jobs;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Date;
use LastDragon_ru\LaraASP\Queue\QueueableConfigurator;
use LastDragon_ru\LaraASP\Queue\Queueables\Job;

class MyJobWithConfig extends Job {
    /**
     * As a small bonus you can inject dependencies into the constructor, but 
     * keep in mind that you, probably, should not assign them to class 
     * properties (even private) or they will be serialized.
     */
    public function __construct(QueueableConfigurator $configurator, Application $app) {
        // $app ...
    
        parent::__construct($configurator);
    }

    /**
     * Default config.
     *
     * @param \Illuminate\Foundation\Application|null $app you can use DI here too
     *                                                    
     * @return array
     */
    public function getQueueConfig(Application $app = null): array {
        return [
                'queue'    => 'queue',
                'settings' => [
                    'expire' => '18 hours',
                ],
            ] + parent::getQueueConfig();
    }

    public function handle(QueueableConfigurator $configurator): void {
        // This is how we can get access to the actual config inside `handle`
        $config = $configurator->config($this);
        $expire = $config->setting('expire');
        $expire = Date::now()->sub($expire);

        Job::query()
            ->where('updated_at', '<', $expire)
            ->delete();
    }
}
```

Configurations have the following priority  (last win):

- own properties (`$this->connection`, `$this->queue`, etc)
- own config from `getQueueConfig()`
- app's config (`queue.queueables.<class>` from `config/queue.php` if present)
- `onConnection()`, `onQueue()`, etc calls

Thus, you can easily set settings for your jobs in app config, for example, we can set the `expire` setting on `8 hours`:

```php
<?php declare(strict_types = 1);

// config/queue.php

return [
    // .....

    /*
    |--------------------------------------------------------------------------
    | Queueables Configuration
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of custom queue jobs.
    |
    */
    'queueables' => [
        \App\Jobs\MyJobWithConfig::class => [
            'settings' => [
                'expire' => '8 hours',
            ],
        ],
    ],
];
```

# Cron jobs

Creating the cron jobs is similar. They just have two additional settings:

```php
<?php declare(strict_types = 1);

namespace App\Jobs;

use LastDragon_ru\LaraASP\Queue\QueueableConfigurator;
use LastDragon_ru\LaraASP\Queue\Queueables\CronJob;

class MyCronJob extends CronJob {
    public function getQueueConfig(): array {
        return [
                'cron'    => '0 * * * *', // Cron expression
                'enabled' => true,        // Status (`false` will disable the job)
            ] + parent::getQueueConfig();
    }

    public function handle(QueueableConfigurator $configurator): void {
        // ....
    }
}
```

But the registration of the jobs a slightly different. For `Kernel` you should use following way:

```php
<?php declare(strict_types = 1);

namespace App\Console;

use App\Jobs\MyCronJob;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use LastDragon_ru\LaraASP\Queue\Concerns\ConsoleKernelWithSchedule;

class Kernel extends ConsoleKernel {
    // !!! Add this trait
    use ConsoleKernelWithSchedule;

    /**
     * The Artisan commands provided by your application.
     *
     * @var string[]
     */
    protected $commands = [];

    // !!! Add this property and put all cron jobs inside
    /**
     * The application's command schedule.
     *
     * @var string[]
     */
    protected array $schedule = [
        MyCronJob::class,
    ];

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands() {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
```

And for package providers:

```php
<?php declare(strict_types = 1);

namespace LastDragon_ru\LaraASP\Migrator;

use Illuminate\Support\ServiceProvider;
use LastDragon_ru\LaraASP\Queue\Concerns\ProviderWithSchedule;

class Provider extends ServiceProvider {
    use ProviderWithSchedule;

    public function boot() {
        $this->bootSchedule([
            // Put all cron jobs provided in the package here
            PackageCronJob::class,
        ]);
    }
}
```

Finally, the package also discloses all settings in the job description:

```txt
$ php artisan schedule:list

+---------+-------------+---------------------------------------------------------------------------------+---------------------+
| Command | Interval    | Description                                                                     | Next Due            |
+---------+-------------+---------------------------------------------------------------------------------+---------------------+
|         | 0 0 * * *   | App\Jobs\JobsCleanupCronJob                                                     | 2021-03-14 00:00:00 |
|         |             | {"queue":"default","enabled":true,"settings":{"expire":"18 hours"}}             |                     |
|         | */5 * * * * | App\Jobs\SiteLogsCleanupCronJob                                                 | 2021-03-13 06:40:00 |
|         |             | {"queue":"default","enabled":true,"settings":{"expire":"30 days"}}              |                     |
+---------+-------------+---------------------------------------------------------------------------------+---------------------+
```


# Overriding package Jobs

The most interesting and useful thing for package developers is the ability to extend all package's jobs in the application. For example, our package provides the `PackageCronJob` and `UpdateSomethingJob`, their setting can be easily changed through the config, but can we extend it in the app? Yes!

We no need additional actions for `CronJob`, but should use `Container::make()` for `Job` and `Mails`:

```php
// Use
$this->app->make(UpdateSomethingJob::class)->dispatch();

// Instead of
UpdateSomethingJob::dispatch();
```

then inside the app

```php
<?php  declare(strict_types = 1);
      
namespace App\Jobs;

class CustomUpdateSomethingJob extends UpdateSomethingJob {
    public function handle(): void {
        // our implementation
    }
}
```

and finally, register it:

```php
<?php declare(strict_types = 1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register() {
        $this->app->bind(UpdateSomethingJob::class, CustomUpdateSomethingJob:class);
    }
}
```

🥳

The `CustomUpdateSomethingJob` will use the same settings name in `config/queue.php` as `UpdateSomethingJob`. Sometimes you may want to create a new job with its own config, in this case, you should break the config chain:

```php
<?php  declare(strict_types = 1);
      
namespace App\Jobs;

use LastDragon_ru\LaraASP\Queue\Concerns\WithConfig;

class CustomUpdateSomethingJobWithOwnConfig extends UpdateSomethingJob {
    use WithConfig; // Indicates that the job has its own config

    public function handle(): void {
        // our implementation
    }
}
```
