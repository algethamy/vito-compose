# Compose Plugin

## Docker (Compose) site type

Prerequisites: Docker Engine and Docker Compose v2 must be installed on the server, and the SSH user must be able to run `docker` commands.

Port behavior: each Docker site binds to a unique localhost-only host port (`127.0.0.1:<host_port>`). If you leave `host_http_port` empty, Vito auto-allocates a free port in the 30000–39999 range and persists it for redeploys. Nginx proxies the site’s domain to that port.

LibreChat example (repo source):

- `compose_source`: `repo`
- `repo_url`: `https://github.com/danny-avila/LibreChat.git`
- `repo_branch`: `main`
- `project_dir`: `app`
- `compose_file`: `docker-compose.yml`
- `container_http_port`: `3080`
- `host_http_port`: leave empty to auto-allocate
- `env_content`: paste the `.env` content from the LibreChat docs
