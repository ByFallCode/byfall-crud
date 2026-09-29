<?php
declare(strict_types=1);

namespace ByfallCode\ByfallCrud\Tests\Feature\Commands;

use ByfallCode\ByfallCrud\Installation\ApiFoundationInstaller;
use ByfallCode\ByfallCrud\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler as CollisionExceptionHandler;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InstallCommandTest extends TestCase
{
    private string $temporaryBase;
    private string $originalBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = $this->app->basePath();
        $this->temporaryBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'byfall-command-'.bin2hex(random_bytes(6));
        (new Filesystem())->ensureDirectoryExists($this->temporaryBase.'/bootstrap');
        file_put_contents($this->temporaryBase.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");
        $this->app->setBasePath($this->temporaryBase);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        (new Filesystem())->deleteDirectory($this->temporaryBase);
        parent::tearDown();
    }

    public function test_command_installs_and_then_reports_an_idempotent_result(): void
    {
        $this->artisan('byfall:install')->assertExitCode(0);
        $this->artisan('byfall:install')->expectsOutputToContain('Nothing to change.')->assertExitCode(0);
        $this->artisan('byfall:install')->assertExitCode(0);

        self::assertFileExists($this->temporaryBase.'/app/Support/ApiResponse.php');
        self::assertSame(1, substr_count(
            file_get_contents($this->temporaryBase.'/bootstrap/providers.php'),
            ApiFoundationInstaller::PROVIDER.'::class',
        ));
    }

    public function test_command_reports_partial_installation_for_unknown_bootstrap_shape(): void
    {
        $path = $this->temporaryBase.'/bootstrap/providers.php';
        $custom = "<?php\nreturn custom_providers();\n";
        file_put_contents($path, $custom);

        $this->artisan('byfall:install', ['--force' => true])
            ->expectsOutputToContain('Installation is partial')
            ->assertExitCode(1);

        self::assertSame($custom, file_get_contents($path));
    }

    public function test_installed_provider_configures_http_exceptions_and_api_middleware(): void
    {
        file_put_contents(
            $this->temporaryBase.'/bootstrap/providers.php',
            "<?php\n\nuse App\\Providers\\AppServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n];\n",
        );

        $this->artisan('byfall:install')
            ->expectsOutputToContain('API exception handling configured')
            ->assertExitCode(0);

        $this->loadPublishedFoundation();
        $this->setRunningInConsole(false);

        $this->app->register(\App\Providers\ByfallApiServiceProvider::class);
        $apiMiddleware = $this->app['router']->getMiddlewareGroups()['api'] ?? [];

        self::assertContains(\App\Http\Middleware\ForceJsonResponse::class, $apiMiddleware);
        self::assertSame(1, count(array_filter(
            $apiMiddleware,
            static fn (string $middleware): bool => $middleware === \App\Http\Middleware\ForceJsonResponse::class,
        )));

        /** @var ExceptionHandlerContract $handler */
        $handler = $this->app->make(ExceptionHandlerContract::class);
        $apiRequest = Request::create('/api/unknown', server: ['HTTP_ACCEPT' => 'text/html']);

        $notFound = $handler->render($apiRequest, new NotFoundHttpException());
        self::assertInstanceOf(JsonResponse::class, $notFound);
        self::assertSame('ENDPOINT_NOT_FOUND', $notFound->getData(true)['error_code']);

        $method = $handler->render($apiRequest, new MethodNotAllowedHttpException(['GET']));
        self::assertSame(405, $method->getStatusCode());
        self::assertSame('GET', $method->headers->get('Allow'));

        $validation = $handler->render($apiRequest, ValidationException::withMessages(['name' => ['Required.']]));
        self::assertSame(422, $validation->getStatusCode());
        self::assertSame(['Required.'], $validation->getData(true)['errors']['name']);

        $missingModel = $handler->render($apiRequest, (new ModelNotFoundException())->setModel('Post', [1]));
        self::assertSame('RESOURCE_NOT_FOUND', $missingModel->getData(true)['error_code']);

        $unauthenticated = $handler->render($apiRequest, new AuthenticationException());
        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $unauthenticated->getData(true)['error_code']);

        $forbidden = $handler->render($apiRequest, new AuthorizationException());
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('FORBIDDEN', $forbidden->getData(true)['error_code']);

        $rateLimited = $handler->render(
            $apiRequest,
            new ThrottleRequestsException(headers: ['Retry-After' => '30']),
        );
        self::assertSame(429, $rateLimited->getStatusCode());
        self::assertSame('30', $rateLimited->headers->get('Retry-After'));

        $serverError = $handler->render($apiRequest, new RuntimeException('SQL password=secret'));
        self::assertSame(500, $serverError->getStatusCode());
        self::assertStringNotContainsString('SQL password=secret', (string) $serverError->getContent());

        $web = $handler->render(
            Request::create('/web-route', server: ['HTTP_ACCEPT' => 'text/html']),
            new NotFoundHttpException(),
        );
        self::assertNotInstanceOf(JsonResponse::class, $web);
    }

    public function test_collision_handler_does_not_break_artisan_after_installation(): void
    {
        $this->artisan('byfall:install')->assertExitCode(0);
        $this->loadPublishedFoundation();

        $laravelHandler = $this->app->make(ExceptionHandlerContract::class);
        $this->app->instance(
            ExceptionHandlerContract::class,
            new CollisionExceptionHandler($this->app, $laravelHandler),
        );
        self::assertInstanceOf(CollisionExceptionHandler::class, $this->app->make(ExceptionHandlerContract::class));

        $this->app->register(\App\Providers\ByfallApiServiceProvider::class);

        $this->artisan('list')->assertExitCode(0);
        $this->artisan('make:entity', ['name' => 'Post', '--source' => 'db'])
            ->expectsOutput('Driver non supporté : sqlite')
            ->assertExitCode(1);
    }

    public function test_normal_laravel_handler_does_not_break_artisan_after_installation(): void
    {
        $this->artisan('byfall:install')->assertExitCode(0);
        $this->loadPublishedFoundation();

        self::assertNotInstanceOf(
            CollisionExceptionHandler::class,
            $this->app->make(ExceptionHandlerContract::class),
        );
        $this->app->register(\App\Providers\ByfallApiServiceProvider::class);

        $this->artisan('list')->assertExitCode(0);
    }

    private function loadPublishedFoundation(): void
    {
        $classes = [
            'app/Support/ApiErrorCode.php' => \App\Support\ApiErrorCode::class,
            'app/Support/ApiResponse.php' => \App\Support\ApiResponse::class,
            'app/Support/ApiExceptionRenderer.php' => \App\Support\ApiExceptionRenderer::class,
            'app/Http/Middleware/ForceJsonResponse.php' => \App\Http\Middleware\ForceJsonResponse::class,
            'app/Providers/ByfallApiServiceProvider.php' => \App\Providers\ByfallApiServiceProvider::class,
        ];
        foreach ($classes as $relativePath => $class) {
            if (!class_exists($class, false) && !enum_exists($class, false)) {
                require_once $this->temporaryBase.'/'.$relativePath;
            }
        }
    }

    private function setRunningInConsole(bool $value): void
    {
        $property = new ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, $value);
    }
}
