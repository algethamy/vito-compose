<?php

namespace App\Vito\Plugins\Vito\Compose;

use App\Exceptions\SSHError;
use App\Models\Site;
use App\SiteTypes\AbstractSiteType;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use RuntimeException;

class DockerHandler extends AbstractSiteType
{
    private const string DEFAULT_COMPOSE_SOURCE = 'repo';
    private const string DEFAULT_REPO_BRANCH = 'main';
    private const string DEFAULT_PROJECT_DIR = 'app';
    private const string DEFAULT_COMPOSE_FILE = 'docker-compose.yml';
    private const int DEFAULT_CONTAINER_HTTP_PORT = 3080;
    private const int AUTO_PORT_RANGE_START = 30000;
    private const int AUTO_PORT_RANGE_END = 39999;
    private const string COMPOSE_OVERRIDE_FILE = 'docker-compose.vito.yml';

    private ?int $resolvedHostPort = null;

    public static function make(): self
    {
        return new self(new Site(['type' => self::id()]));
    }

    public static function id(): string
    {
        return 'docker';
    }

    public function language(): string
    {
        return 'docker';
    }

    public function requiredServices(): array
    {
        return ['webserver'];
    }

    public function createRules(array $input): array
    {
        $composeSource = $input['compose_source'] ?? self::DEFAULT_COMPOSE_SOURCE;

        return [
            'compose_source' => [
                'required',
                Rule::in(['repo', 'inline']),
            ],
            'repo_url' => [
                Rule::requiredIf($composeSource === 'repo'),
                'nullable',
                'string',
                'max:255',
            ],
            'repo_branch' => [
                'nullable',
                'string',
                'max:255',
            ],
            'project_dir' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9._\-\/]+$/',
                'not_regex:/\.\./',
            ],
            'compose_file' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9._\-\/]+$/',
                'not_regex:/\.\./',
            ],
            'compose_inline' => [
                Rule::requiredIf($composeSource === 'inline'),
                'nullable',
                'string',
            ],
            'container_http_port' => [
                'required',
                'integer',
                'between:1,65535',
            ],
            'host_http_port' => [
                'nullable',
                'integer',
                'between:'.self::AUTO_PORT_RANGE_START.','.self::AUTO_PORT_RANGE_END,
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $port = (int) $value;
                    if ($this->portIsReserved($port)) {
                        $fail('The selected host port is already assigned to another site.');

                        return;
                    }

                    try {
                        if (! $this->isPortAvailable($port)) {
                            $fail('The selected host port is already in use on the server.');
                        }
                    } catch (RuntimeException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'public_url_path' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^\/?[a-zA-Z0-9._\-\/]*$/',
                'not_regex:/\.\./',
            ],
            'env_content' => [
                'nullable',
                'string',
            ],
            'healthcheck_url' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^\/?[a-zA-Z0-9._\-\/]*$/',
                'not_regex:/\.\./',
            ],
        ];
    }

    public function createFields(array $input): array
    {
        $port = $this->resolveHostPort($input);

        $fields = [
            'port' => $port,
        ];

        if (($input['compose_source'] ?? self::DEFAULT_COMPOSE_SOURCE) === 'repo') {
            $fields['repository'] = $input['repo_url'] ?? '';
            $fields['branch'] = $input['repo_branch'] ?? self::DEFAULT_REPO_BRANCH;
        }

        return $fields;
    }

    public function data(array $input): array
    {
        $projectDir = $this->normalizeProjectDir($input['project_dir'] ?? self::DEFAULT_PROJECT_DIR);
        $composeFile = $this->normalizeComposeFile($input['compose_file'] ?? self::DEFAULT_COMPOSE_FILE);
        $composeSource = $input['compose_source'] ?? self::DEFAULT_COMPOSE_SOURCE;
        $containerPort = (int) ($input['container_http_port'] ?? self::DEFAULT_CONTAINER_HTTP_PORT);
        $hostPort = $this->resolvedHostPort ?? $this->resolveHostPort($input);
        $publicUrlPath = $this->normalizePublicUrlPath($input['public_url_path'] ?? '');
        $healthcheckUrl = $this->normalizeHealthcheckUrl($input['healthcheck_url'] ?? '');

        return [
            'compose_source' => $composeSource,
            'repo_url' => $composeSource === 'repo' ? trim((string) ($input['repo_url'] ?? '')) : null,
            'repo_branch' => $composeSource === 'repo' ? trim((string) ($input['repo_branch'] ?? self::DEFAULT_REPO_BRANCH)) : null,
            'project_dir' => $projectDir,
            'compose_file' => $composeFile,
            'container_http_port' => $containerPort,
            'host_http_port' => $hostPort,
            'public_url_path' => $publicUrlPath,
            'healthcheck_url' => $healthcheckUrl,
            'compose_inline' => $composeSource === 'inline' ? ($input['compose_inline'] ?? null) : null,
            'env_content' => $input['env_content'] ?? null,
            'env_path' => $this->envPath($projectDir),
        ];
    }

    /**
     * @throws SSHError
     */
    public function install(): void
    {
        $this->progress(5);
        $this->ensureDockerAvailable();
        $this->progress(10);

        $this->site->webserver()->createVHost($this->site);
        $this->progress(20);

        $workdir = $this->workdir();
        $this->prepareWorkdir($workdir);

        if ($this->composeSource() === 'repo') {
            $this->cloneRepository($workdir);
        } else {
            $this->writeInlineCompose($workdir);
        }

        $this->progress(45);

        $this->mergeEnvFile($workdir, $this->typeData('env_content'));
        $this->progress(55);

        $hostPort = $this->hostPort();
        $containerPort = (int) $this->typeData('container_http_port', self::DEFAULT_CONTAINER_HTTP_PORT);
        if (! $this->isPortAvailable($hostPort)) {
            throw new RuntimeException('The allocated host port is already in use on the server.');
        }

        $service = $this->resolveComposeService($workdir, $containerPort);
        $this->site->jsonUpdate('type_data', 'compose_service', $service);

        $this->writeComposeOverride($workdir, $service, $hostPort, $containerPort);
        $this->writeDeploymentScript($service, $hostPort, $containerPort);
        $this->progress(70);

        $this->runCompose($workdir, 'pull');
        $this->runCompose($workdir, 'up -d --remove-orphans');
        $this->progress(90);

        $this->clearSensitiveTypeData();
        $this->progress(100);
    }

    /**
     * @throws SSHError
     */
    public function uninstall(): void
    {
        $this->ensureDockerAvailable();

        $workdir = $this->workdir();
        if (! $this->composeFileExists($workdir)) {
            return;
        }

        $this->runCompose($workdir, 'down');
    }

    public function baseCommands(): array
    {
        return [];
    }

    public function vhost(string $webserver): string|View
    {
        if ($webserver === 'nginx') {
            return view('ssh.services.webserver.nginx.vhost', [
                'header' => [
                    view('ssh.services.webserver.nginx.vhost-blocks.force-ssl', ['site' => $this->site]),
                ],
                'main' => [
                    view('ssh.services.webserver.nginx.vhost-blocks.port', ['site' => $this->site]),
                    view('ssh.services.webserver.nginx.vhost-blocks.core', ['site' => $this->site]),
                    view('compose::nginx.vhost-blocks.docker-proxy', [
                        'site' => $this->site,
                        'publicUrlPath' => $this->normalizePublicUrlPath($this->typeData('public_url_path', '')),
                        'healthcheckUrl' => $this->normalizeHealthcheckUrl($this->typeData('healthcheck_url', '')),
                    ]),
                    view('ssh.services.webserver.nginx.vhost-blocks.redirects', ['site' => $this->site]),
                ],
            ]);
        }

        if ($webserver === 'caddy') {
            return view('ssh.services.webserver.caddy.vhost', [
                'main' => [
                    view('ssh.services.webserver.caddy.vhost-blocks.reverse-proxy', ['site' => $this->site]),
                ],
            ]);
        }

        return '';
    }

    private function composeSource(): string
    {
        return (string) $this->typeData('compose_source', self::DEFAULT_COMPOSE_SOURCE);
    }

    private function hostPort(): int
    {
        $port = (int) ($this->site->port ?? 0);
        if ($port > 0) {
            return $port;
        }

        $port = (int) $this->typeData('host_http_port', 0);
        if ($port > 0) {
            $this->site->port = $port;
            $this->site->save();

            return $port;
        }

        throw new RuntimeException('Host port is missing for this Docker site.');
    }

    private function resolveHostPort(array $input): int
    {
        if ($this->resolvedHostPort !== null) {
            return $this->resolvedHostPort;
        }

        if ($this->site->port) {
            $this->resolvedHostPort = (int) $this->site->port;

            return $this->resolvedHostPort;
        }

        $provided = $input['host_http_port'] ?? null;
        if ($provided !== null && $provided !== '') {
            $port = (int) $provided;
            if ($this->portIsReserved($port)) {
                throw new RuntimeException('The selected host port is already assigned to another site.');
            }
            if (! $this->isPortAvailable($port)) {
                throw new RuntimeException('The selected host port is already in use on the server.');
            }
            $this->resolvedHostPort = $port;

            return $port;
        }

        $this->resolvedHostPort = $this->allocateHostPort();

        return $this->resolvedHostPort;
    }

    private function allocateHostPort(): int
    {
        for ($port = self::AUTO_PORT_RANGE_START; $port <= self::AUTO_PORT_RANGE_END; $port++) {
            if ($this->portIsReserved($port)) {
                continue;
            }
            if ($this->isPortAvailable($port)) {
                return $port;
            }
        }

        throw new RuntimeException('No available host ports found in the configured range.');
    }

    protected function portIsReserved(int $port): bool
    {
        return Site::query()
            ->where('server_id', $this->site->server_id)
            ->where('port', $port)
            ->when($this->site->exists, fn ($query) => $query->where('id', '!=', $this->site->id))
            ->exists();
    }

    protected function buildPortCheckCommand(int $port): string
    {
        $port = (int) $port;

        return <<<SH
if command -v ss >/dev/null 2>&1; then
    ss -ltn '( sport = :{$port} )' | grep -q ':{$port} ' && echo 'USED' || echo 'FREE'
elif command -v lsof >/dev/null 2>&1; then
    lsof -iTCP:{$port} -sTCP:LISTEN >/dev/null 2>&1 && echo 'USED' || echo 'FREE'
else
    echo 'UNKNOWN'
fi
SH;
    }

    protected function isPortAvailable(int $port): bool
    {
        $command = $this->buildPortCheckCommand($port);
        $output = trim($this->ssh()->exec($command));

        if ($output === 'FREE') {
            return true;
        }

        if ($output === 'USED') {
            return false;
        }

        throw new RuntimeException('Unable to verify port availability. Install ss or lsof on the server.');
    }

    private function ensureDockerAvailable(): void
    {
        try {
            $this->ssh()->exec('docker --version');
            $this->ssh()->exec('docker compose version');
        } catch (SSHError $exception) {
            throw new RuntimeException('Docker and Docker Compose must be installed and available to the SSH user.');
        }
    }

    private function workdir(): string
    {
        $projectDir = $this->normalizeProjectDir($this->typeData('project_dir', self::DEFAULT_PROJECT_DIR));
        $basePath = rtrim($this->site->path, '/');

        if ($projectDir === '') {
            return $basePath;
        }

        return $basePath.'/'.$projectDir;
    }

    private function envPath(string $projectDir): string
    {
        $basePath = rtrim($this->site->path, '/');
        if ($projectDir === '') {
            return $basePath.'/.env';
        }

        return $basePath.'/'.$projectDir.'/.env';
    }

    private function prepareWorkdir(string $workdir): void
    {
        $this->ssh($this->site->user)->exec('mkdir -p '.escapeshellarg($workdir));
    }

    private function cloneRepository(string $workdir): void
    {
        $repoUrl = (string) $this->typeData('repo_url');
        $branch = (string) $this->typeData('repo_branch', self::DEFAULT_REPO_BRANCH);

        $ssh = $this->ssh($this->site->user);
        $ssh->exec('rm -rf '.escapeshellarg($workdir));
        $ssh->exec('mkdir -p '.escapeshellarg(dirname($workdir)));
        $ssh->exec(
            'git clone -b '.escapeshellarg($branch).' '.escapeshellarg($repoUrl).' '.escapeshellarg($workdir)
        );
    }

    private function writeInlineCompose(string $workdir): void
    {
        $composeFile = $this->composeFile();
        $composeContent = (string) $this->typeData('compose_inline', '');
        $path = $workdir.'/'.$composeFile;

        $this->prepareWorkdir(dirname($path));
        $this->ssh($this->site->user)->write($path, trim($composeContent)."\n", $this->site->user);
    }

    private function mergeEnvFile(string $workdir, ?string $envContent): void
    {
        if (! $envContent) {
            return;
        }

        $envPath = $workdir.'/.env';
        $existing = $this->ssh($this->site->user)->exec(
            'if [ -f '.escapeshellarg($envPath).' ]; then cat '.escapeshellarg($envPath).'; fi'
        );

        $merged = $this->mergeEnvContents($existing, $envContent);
        $this->ssh($this->site->user)->write($envPath, $merged."\n", $this->site->user);
    }

    private function mergeEnvContents(string $existing, string $incoming): string
    {
        $existingParsed = $this->parseEnv($existing);
        $incomingParsed = $this->parseEnv($incoming);

        $values = $existingParsed['values'];
        foreach ($incomingParsed['values'] as $key => $value) {
            $values[$key] = $value;
        }

        $order = $existingParsed['order'];
        foreach (array_keys($incomingParsed['values']) as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        $lines = [];
        foreach ($order as $key) {
            $lines[] = $key.'='.$values[$key];
        }

        return implode("\n", $lines);
    }

    private function parseEnv(string $content): array
    {
        $values = [];
        $order = [];

        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $values[$key] = $value;
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        return [
            'values' => $values,
            'order' => $order,
        ];
    }

    private function composeFile(): string
    {
        return $this->normalizeComposeFile($this->typeData('compose_file', self::DEFAULT_COMPOSE_FILE));
    }

    private function resolveComposeService(string $workdir, int $containerPort): string
    {
        $services = $this->listComposeServices($workdir);
        if (count($services) === 1) {
            return $services[0];
        }

        $serviceWithPort = $this->findServiceByPort($workdir, $containerPort);
        if ($serviceWithPort) {
            return $serviceWithPort;
        }

        if (count($services) > 0) {
            return $services[0];
        }

        throw new RuntimeException('No services found in the docker compose configuration.');
    }

    private function listComposeServices(string $workdir): array
    {
        $command = $this->composeCommand($workdir, 'config --services', false);
        $output = trim($this->ssh()->exec($command));

        if ($output === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    private function findServiceByPort(string $workdir, int $containerPort): ?string
    {
        $command = $this->composeCommand($workdir, 'config', false);
        $output = $this->ssh()->exec($command);

        $currentService = null;
        $inPorts = false;

        foreach (preg_split('/\r?\n/', (string) $output) as $line) {
            if (preg_match('/^\s{2}([a-zA-Z0-9._-]+):\s*$/', $line, $matches)) {
                $currentService = $matches[1];
                $inPorts = false;
                continue;
            }

            if ($currentService && preg_match('/^\s{4}(ports|expose):\s*$/', $line)) {
                $inPorts = true;
                continue;
            }

            if ($currentService && $inPorts && preg_match('/^\s{6}-\s+(.+)$/', $line, $matches)) {
                if ($this->lineContainsPort($matches[1], $containerPort)) {
                    return $currentService;
                }
                continue;
            }

            if ($currentService && $inPorts && ! preg_match('/^\s{6}-\s+/', $line)) {
                $inPorts = false;
            }
        }

        return null;
    }

    private function lineContainsPort(string $line, int $port): bool
    {
        preg_match_all('/\d+/', $line, $matches);
        foreach ($matches[0] ?? [] as $match) {
            if ((int) $match === $port) {
                return true;
            }
        }

        return false;
    }

    private function composeCommand(string $workdir, string $subcommand, bool $includeOverride = true): string
    {
        $composeFile = $this->composeFile();
        $overrideFile = self::COMPOSE_OVERRIDE_FILE;
        $projectName = $this->composeProjectName();

        $command = sprintf(
            'cd %s && docker compose -f %s',
            escapeshellarg($workdir),
            escapeshellarg($composeFile)
        );

        if ($includeOverride) {
            $command .= ' -f '.escapeshellarg($overrideFile);
        }

        $command .= sprintf(
            ' --project-name %s %s',
            escapeshellarg($projectName),
            $subcommand
        );

        return $command;
    }

    private function composeProjectName(): string
    {
        return 'vito-site-'.$this->site->id;
    }

    private function writeComposeOverride(string $workdir, string $service, int $hostPort, int $containerPort): void
    {
        $content = "services:\n".
            "  {$service}:\n".
            "    ports:\n".
            "      - \"127.0.0.1:{$hostPort}:{$containerPort}\"\n";

        $path = $workdir.'/'.self::COMPOSE_OVERRIDE_FILE;
        $this->ssh($this->site->user)->write($path, $content, $this->site->user);
    }

    private function runCompose(string $workdir, string $subcommand): void
    {
        $command = $this->composeCommand($workdir, $subcommand);
        $this->ssh()->exec($command, 'docker-compose', $this->site->id);
    }

    private function composeFileExists(string $workdir): bool
    {
        $composePath = $workdir.'/'.$this->composeFile();
        $command = 'if [ -f '.escapeshellarg($composePath).' ]; then echo "yes"; fi';

        return trim($this->ssh($this->site->user)->exec($command)) === 'yes';
    }

    private function writeDeploymentScript(string $service, int $hostPort, int $containerPort): void
    {
        $this->site->ensureDeploymentScriptsExist();

        $deploymentScript = $this->site->deploymentScript;
        if (! $deploymentScript) {
            return;
        }

        $deploymentScript->content = $this->deploymentScriptContent($service, $hostPort, $containerPort);
        $deploymentScript->save();
    }

    private function deploymentScriptContent(string $service, int $hostPort, int $containerPort): string
    {
        $projectDir = $this->normalizeProjectDir($this->typeData('project_dir', self::DEFAULT_PROJECT_DIR));
        $composeFile = $this->composeFile();
        $overrideFile = self::COMPOSE_OVERRIDE_FILE;
        $projectName = $this->composeProjectName();
        $composeSource = $this->composeSource();
        $repoUrl = (string) $this->typeData('repo_url', '');
        $repoBranch = (string) $this->typeData('repo_branch', self::DEFAULT_REPO_BRANCH);

        $repoUrlArg = escapeshellarg($repoUrl);
        $repoBranchArg = escapeshellarg($repoBranch);
        $composeFileArg = escapeshellarg($composeFile);
        $overrideFileArg = escapeshellarg($overrideFile);
        $projectNameArg = escapeshellarg($projectName);

        $workdirLine = $projectDir === ''
            ? 'WORKDIR="$SITE_PATH"'
            : 'WORKDIR="$SITE_PATH/'.trim($projectDir, '/').'"';

        $script = [
            '#!/usr/bin/env bash',
            'set -e',
            $workdirLine,
            'mkdir -p "$WORKDIR"',
        ];

        if ($composeSource === 'repo' && $repoUrl !== '') {
            $script[] = 'if [ -d "$WORKDIR/.git" ]; then';
            $script[] = '  cd "$WORKDIR" && git pull origin '.$repoBranchArg;
            $script[] = 'else';
            $script[] = '  git clone -b '.$repoBranchArg.' '.$repoUrlArg.' "$WORKDIR"';
            $script[] = 'fi';
        }

        $script[] = 'cat > "$WORKDIR/'.self::COMPOSE_OVERRIDE_FILE.'" <<EOF';
        $script[] = 'services:';
        $script[] = '  '.$service.':';
        $script[] = '    ports:';
        $script[] = '      - "127.0.0.1:'.$hostPort.':'.$containerPort.'"';
        $script[] = 'EOF';
        $script[] = 'cd "$WORKDIR" && docker compose -f '.$composeFileArg.' -f '.$overrideFileArg.' --project-name '.$projectNameArg.' pull';
        $script[] = 'cd "$WORKDIR" && docker compose -f '.$composeFileArg.' -f '.$overrideFileArg.' --project-name '.$projectNameArg.' up -d --remove-orphans';

        return implode("\n", $script)."\n";
    }

    private function normalizeProjectDir(?string $dir): string
    {
        $dir = trim((string) $dir);
        if ($dir === '' || $dir === '.' || $dir === '/') {
            return '';
        }

        return trim($dir, '/');
    }

    private function normalizeComposeFile(?string $file): string
    {
        $file = trim((string) $file);
        if ($file === '') {
            return self::DEFAULT_COMPOSE_FILE;
        }

        return ltrim($file, '/');
    }

    private function normalizePublicUrlPath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '' || $path === '/') {
            return '';
        }

        return '/'.trim($path, '/');
    }

    private function normalizeHealthcheckUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '' || $path === '/') {
            return null;
        }

        return '/'.ltrim($path, '/');
    }

    private function typeData(string $key, mixed $default = null): mixed
    {
        return $this->site->type_data[$key] ?? $default;
    }

    private function clearSensitiveTypeData(): void
    {
        $typeData = $this->site->type_data ?? [];
        unset($typeData['env_content'], $typeData['compose_inline']);
        $this->site->type_data = $typeData;
        $this->site->save();
    }

    private function ssh(?string $user = null)
    {
        return $this->site->server->ssh($user);
    }
}
