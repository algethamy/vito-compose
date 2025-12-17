<?php

namespace App\Vito\Plugins\Vito\Compose;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\ServerLog;
use App\Models\Site;
use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterSiteType;
use App\Plugins\RegisterViews;
use Throwable;

class Plugin extends AbstractPlugin
{
    protected string $name = 'Compose';
    protected string $description = 'Docker Compose site type for VitoDeploy.';

    public function boot(): void
    {
        RegisterViews::make('compose')
            ->path(__DIR__.'/views')
            ->register();

        RegisterSiteType::make('docker')
            ->label('Docker (Compose)')
            ->handler(DockerHandler::class)
            ->form(DynamicForm::make([
                DynamicField::make('compose_source')
                    ->select()
                    ->label('Compose Source')
                    ->default('repo')
                    ->options([
                        'repo',
                        'inline',
                    ])
                    ->description('Use a git repository or provide an inline Compose template.'),
                DynamicField::make('repo_url')
                    ->text()
                    ->label('Repository URL')
                    ->placeholder('https://github.com/owner/repo.git')
                    ->description('Required when Compose Source is set to repo.'),
                DynamicField::make('repo_branch')
                    ->text()
                    ->label('Repository Branch')
                    ->default('main')
                    ->description('Used only for repo source.'),
                DynamicField::make('project_dir')
                    ->text()
                    ->label('Project Directory')
                    ->default('app')
                    ->placeholder('app')
                    ->description('Relative to the site root where the repository/template lives.'),
                DynamicField::make('compose_file')
                    ->text()
                    ->label('Compose File')
                    ->default('docker-compose.yml')
                    ->description('Relative to the project directory.'),
                DynamicField::make('compose_inline')
                    ->textarea()
                    ->label('Inline Compose Template')
                    ->description('Required when Compose Source is set to inline.'),
                DynamicField::make('container_http_port')
                    ->text()
                    ->label('Container HTTP Port')
                    ->default(3080)
                    ->description('Internal container port the app listens on.'),
                DynamicField::make('host_http_port')
                    ->text()
                    ->label('Host HTTP Port')
                    ->placeholder('Auto-allocate if empty')
                    ->description('Bound on 127.0.0.1; leave empty to auto-allocate.'),
                DynamicField::make('public_url_path')
                    ->text()
                    ->label('Public URL Path')
                    ->placeholder('/ (leave empty for root)')
                    ->description('Optional path prefix for proxying.'),
                DynamicField::make('env_content')
                    ->textarea()
                    ->label('.env Content')
                    ->description('Optional environment overrides merged into .env.'),
                DynamicField::make('healthcheck_url')
                    ->text()
                    ->label('Healthcheck URL')
                    ->placeholder('/health')
                    ->description('Optional path to expose for health checks.'),
            ]))
            ->register();

        Site::deleting(function (Site $site): void {
            if ($site->type !== DockerHandler::id()) {
                return;
            }

            try {
                (new DockerHandler($site))->uninstall();
            } catch (Throwable $exception) {
                ServerLog::log(
                    $site->server,
                    'compose-uninstall-failed',
                    $exception->getMessage(),
                    $site
                );
            }
        });
    }
}
