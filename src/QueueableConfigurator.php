<?php declare(strict_types = 1);

namespace LastDragon_ru\LaraASP\Queue;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\DateFactory;
use LastDragon_ru\LaraASP\Queue\Configs\CronableConfig;
use LastDragon_ru\LaraASP\Queue\Configs\MailableConfig;
use LastDragon_ru\LaraASP\Queue\Configs\QueueableConfig;
use LastDragon_ru\LaraASP\Queue\Contracts\ConfigurableQueueable;
use LastDragon_ru\LaraASP\Queue\Contracts\Cronable;

use function array_keys;
use function is_int;
use function is_string;

/**
 * Queueable configurator.
 */
class QueueableConfigurator {
    protected Container  $container;
    protected Repository $config;

    public function __construct(Container $container, Repository $config) {
        $this->container = $container;
        $this->config    = $config;
    }

    public function config(ConfigurableQueueable $queueable): QueueableConfig {
        $config     = null;
        $properties = $this->getQueueableProperties();

        if ($queueable instanceof Mailable) {
            $config = new MailableConfig($this->container, $this->config, $queueable, $properties);
        } elseif ($queueable instanceof Cronable) {
            $config = new CronableConfig($this->container, $this->config, $queueable, $properties);
        } else {
            $config = new QueueableConfig($this->container, $this->config, $queueable, $properties);
        }

        return $config;
    }

    public function configure(ConfigurableQueueable $queueable): void {
        $config     = $this->config($queueable);
        $properties = array_keys($this->getQueueableProperties());
        $preparers  = [
            'retryUntil' => static function (mixed $value): ?DateTimeInterface {
                if (is_string($value)) {
                    $value = DateFactory::now()->add($value);
                } else {
                    $value = null;
                }

                return $value;
            },
            'delay'      => static function (mixed $value): DateInterval|int|null {
                if (is_string($value)) {
                    $value = new DateInterval($value);
                } elseif (is_int($value)) {
                    // no action
                } else {
                    $value = null;
                }

                return $value;
            },
        ];

        foreach ($properties as $property) {
            if ($config->isRedefined($property)) {
                $value                  = $config->get($property);
                $queueable->{$property} = isset($preparers[$property])
                    ? $preparers[$property]($value)
                    : $value;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getQueueableProperties(): array {
        // TODO [laravel] [update] Check available queue properties.
        /** SEE {@link https://laravel.com/docs/8.x/queues} */
        return [
            'connection'              => null,  // Connection name for the job
            'queue'                   => null,  // Queue name for the job
            'timeout'                 => null,  // Number of seconds the job can run
            'tries'                   => null,  // Number of times the job may be attempted
            'maxExceptions'           => null,  // Number of exceptions allowed for the job before fail
            'backoff'                 => null,  // Retry delay for the failed job
            'deleteWhenMissingModels' => null,  // Allow deleting the job if the model does not exist anymore
            'retryUntil'              => null,  // The \DateTime indicating when the job should timeout
            'afterCommit'             => null,  // The job should be dispatched after commit
            'delay'                   => null,  // Dispatching delay
        ];
    }
}
