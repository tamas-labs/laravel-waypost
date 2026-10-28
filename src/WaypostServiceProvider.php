<?php

declare(strict_types=1);

namespace Waypost;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;
use Waypost\Auth\Caller;
use Waypost\Auth\Gate;
use Waypost\Auth\TokenGuard;
use Waypost\Console\CreateSupervisorCommand;
use Waypost\Console\InstallCommand;
use Waypost\Http\ErrorRenderer;
use Waypost\Http\Middleware\FormatApiResponse;
use Waypost\Jobs\PruneIdempotencyKeys;
use Waypost\Models\Checkpoint;
use Waypost\Models\Device;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\Schedule as RoundSchedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Auth\Policies\CheckpointPolicy;
use Waypost\Auth\Policies\DevicePolicy;
use Waypost\Auth\Policies\ReportSubscriptionPolicy;
use Waypost\Auth\Policies\RoutePolicy;
use Waypost\Auth\Policies\SchedulePolicy;
use Waypost\Auth\Policies\SitePolicy;
use Waypost\Auth\Policies\UserPolicy;
use Waypost\Validation\SchemaValidator;

final class WaypostServiceProvider extends ServiceProvider
{
    public const string CONFIG_FILE = __DIR__ . '/../config/waypost.php';

    public const string MIGRATIONS_DIRECTORY = __DIR__ . '/../database/migrations';

    public const string ROUTES_FILE = __DIR__ . '/../routes/api.php';

    /** The policies of the package's gate (Waypost\Auth\Gate). */
    public const array POLICIES = [
        Site::class => SitePolicy::class,
        User::class => UserPolicy::class,
        Device::class => DevicePolicy::class,
        Checkpoint::class => CheckpointPolicy::class,
        Route::class => RoutePolicy::class,
        RoundSchedule::class => SchedulePolicy::class,
        ReportSubscription::class => ReportSubscriptionPolicy::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_FILE, 'waypost');

        // The guard of the package's tokens; Sanctum's "sanctum" guard reads the host application's token table.
        config()->set('auth.guards.waypost', ['driver' => 'waypost']);

        $this->app->singleton(SchemaValidator::class);
        $this->app->singleton(
            Gate::class,
            fn (Application $app): Gate => new Gate($app, fn (): ?Caller => Caller::of($app->make('request')), policies: self::POLICIES),
        );
        $this->app->bind(
            Caller::class,
            fn (Application $app): Caller => Caller::of($app->make('request')) ?? throw new AuthenticationException(),
        );
    }

    public function boot(): void
    {
        $this->callAfterResolving('auth', function (AuthManager $auth): void {
            $auth->extend('waypost', fn (Application $app): TokenGuard => new TokenGuard($app));
        });

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->renderable(fn (Throwable $e, Request $request) => $this->app->make(ErrorRenderer::class)->render($e, $request));
            }
        });

        $this->defineRateLimits();

        if (!($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            self::apiRoutes(self::ROUTES_FILE);
        }

        if ($this->app->runningInConsole()) {
            $this->bootConsole();
        }
    }

    /**
     * Registers routes the way the package's own API routes are: under waypost.route.prefix, named "waypost.…",
     * with server_time and the waypost.route.middleware. Also for tests of the API's behaviour.
     */
    public static function apiRoutes(Closure|string $routes): void
    {
        /** @var list<string> $middleware */
        $middleware = config('waypost.route.middleware', []);

        Router::prefix((string) config('waypost.route.prefix'))
            ->middleware([FormatApiResponse::class, ...$middleware])
            ->name('waypost.')
            ->group($routes);
    }

    private function defineRateLimits(): void
    {
        RateLimiter::for('waypost', fn (Request $request): Limit => Limit::perMinute((int) config('waypost.rate_limits.api'))
            ->by(self::rateLimitKey($request)));

        RateLimiter::for('waypost-login', function (Request $request): Limit {
            $email = $request->input('email');

            return Limit::perMinute((int) config('waypost.rate_limits.login'))
                ->by('waypost-login|' . Str::lower(is_string($email) ? $email : '') . '|' . $request->ip());
        });
    }

    /** The token's owner after Authenticate, otherwise the IP address. */
    private static function rateLimitKey(Request $request): string
    {
        $owner = Caller::of($request)?->owner();

        return $owner === null ? "waypost|ip|{$request->ip()}" : "waypost|{$owner->getMorphClass()}|{$owner->getKey()}";
    }

    private function bootConsole(): void
    {
        $this->commands([
            InstallCommand::class,
            CreateSupervisorCommand::class,
        ]);

        $this->publishes([
            self::CONFIG_FILE => config_path('waypost.php'),
        ], 'waypost-config');

        // Published rather than loaded, so the host application owns its migrations. Prefer waypost:install:
        // vendor:publish renames the files to the current date, so a second run would publish them again.
        $this->publishesMigrations([
            self::MIGRATIONS_DIRECTORY => database_path('migrations'),
        ], 'waypost-migrations');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new PruneIdempotencyKeys())->hourly()->name('waypost:prune-idempotency-keys');
        });
    }
}
