#[docker-proxy]
@if (!empty($publicUrlPath))
location = {{ $publicUrlPath }} {
    return 301 {{ $publicUrlPath }}/;
}
location {{ $publicUrlPath }}/ {
    proxy_pass http://127.0.0.1:{{ $site->port }}/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
@else
location / {
    proxy_pass http://127.0.0.1:{{ $site->port }};
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
@endif
@if (!empty($healthcheckUrl))
location = {{ $healthcheckUrl }} {
    proxy_pass http://127.0.0.1:{{ $site->port }};
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
@endif
#[/docker-proxy]
