<?php

namespace Tests\Unit\Compose;

use App\Facades\SSH;
use App\Models\Site;
use App\Plugins\RegisterViews;
use App\Vito\Plugins\Vito\Compose\DockerHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\TestCase;

class DockerHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_repo_url_is_required_for_repo_source(): void
    {
        $site = Site::factory()->make([
            'server_id' => $this->server->id,
            'type' => 'docker',
        ]);

        $handler = new DockerHandler($site);

        $input = [
            'compose_source' => 'repo',
            'container_http_port' => 3080,
        ];

        $validator = Validator::make($input, $handler->createRules($input));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('repo_url', $validator->errors()->toArray());
    }

    public function test_host_port_must_be_available(): void
    {
        SSH::fake('USED');

        $site = Site::factory()->make([
            'server_id' => $this->server->id,
            'type' => 'docker',
        ]);

        $handler = new DockerHandler($site);

        $input = [
            'compose_source' => 'repo',
            'repo_url' => 'https://example.com/repo.git',
            'container_http_port' => 3080,
            'host_http_port' => 30010,
        ];

        $validator = Validator::make($input, $handler->createRules($input));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('host_http_port', $validator->errors()->toArray());
    }

    public function test_auto_port_allocation_uses_first_available_port(): void
    {
        $site = Site::factory()->make([
            'server_id' => $this->server->id,
            'type' => 'docker',
        ]);

        $handler = new class($site) extends DockerHandler {
            public array $availability = [];

            protected function isPortAvailable(int $port): bool
            {
                return $this->availability[$port] ?? false;
            }

            protected function portIsReserved(int $port): bool
            {
                return false;
            }
        };

        $handler->availability = [
            30000 => false,
            30001 => true,
        ];

        $method = new ReflectionMethod(DockerHandler::class, 'allocateHostPort');
        $method->setAccessible(true);

        $port = $method->invoke($handler);

        $this->assertSame(30001, $port);
    }

    public function test_port_check_command_prefers_ss(): void
    {
        $site = Site::factory()->make([
            'server_id' => $this->server->id,
            'type' => 'docker',
        ]);

        $handler = new DockerHandler($site);

        $method = new ReflectionMethod(DockerHandler::class, 'buildPortCheckCommand');
        $method->setAccessible(true);

        $command = $method->invoke($handler, 31234);

        $this->assertStringContainsString("ss -ltn '( sport = :31234 )'", $command);
    }

    public function test_nginx_vhost_contains_docker_proxy_block(): void
    {
        RegisterViews::make('compose')
            ->path(base_path('app/Vito/Plugins/Vito/Compose/views'))
            ->register();

        $site = Site::factory()->make([
            'server_id' => $this->server->id,
            'type' => 'docker',
            'port' => 31234,
            'type_data' => [
                'public_url_path' => '/chat',
                'healthcheck_url' => '/health',
            ],
        ]);

        $handler = new DockerHandler($site);
        $vhost = (string) $handler->vhost('nginx');

        $this->assertStringContainsString('#[docker-proxy]', $vhost);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:31234', $vhost);
        $this->assertStringContainsString('location /chat/', $vhost);
        $this->assertStringContainsString('location = /health', $vhost);
    }
}
