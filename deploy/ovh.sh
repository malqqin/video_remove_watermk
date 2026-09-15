#!/usr/bin/env bash
# Ubuntu/Debian VPS deployment. Run as root, including when invoked via curl.
set -Eeuo pipefail

main() {
    local repo_url='https://github.com/malqqin/video_remove_watermk.git'
    local deploy_dir='/opt/video_remove_watermk'
    local env_file='/etc/video-remove-watermk/parser.env'
    local listen_port="${PORT:-8000}"
    local bind_address="${BIND_ADDRESS:-127.0.0.1}"
    local compose_package='' candidate container_id state attempt
    local -a compose

    [[ $EUID -eq 0 ]] || { echo 'Run with sudo bash deploy/ovh.sh (or as root).' >&2; return 1; }
    [[ $listen_port =~ ^[1-9][0-9]{0,4}$ ]] && (( listen_port <= 65535 )) || {
        echo 'PORT must be between 1 and 65535.' >&2; return 1;
    }
    [[ $bind_address == '0.0.0.0' || $bind_address == '127.0.0.1' ]] || {
        echo 'BIND_ADDRESS must be 0.0.0.0 or 127.0.0.1.' >&2; return 1;
    }
    [[ -f /etc/os-release ]] || { echo 'Linux /etc/os-release is required.' >&2; return 1; }
    . /etc/os-release
    case "$ID" in
        ubuntu|debian) ;;
        *) echo "This installer supports Ubuntu/Debian; detected $ID." >&2; return 1 ;;
    esac
    export DEBIAN_FRONTEND=noninteractive
    if ! command -v git >/dev/null; then
        apt-get update
        apt-get install -y git ca-certificates
    fi

    if [[ -e "$deploy_dir" ]]; then
        [[ -d "$deploy_dir/.git" ]] || { echo "$deploy_dir exists but is not a Git checkout." >&2; return 1; }
        [[ $(git -C "$deploy_dir" remote get-url origin) == "$repo_url" ]] || {
            echo 'Existing checkout has a different origin; leaving it untouched.' >&2; return 1;
        }
        [[ -z $(git -C "$deploy_dir" status --porcelain) ]] || {
            echo 'Server checkout has local changes. Save them before updating.' >&2; return 1;
        }
        [[ $(git -C "$deploy_dir" branch --show-current) == main ]] || {
            echo 'Server checkout is not on main.' >&2; return 1;
        }
        git -C "$deploy_dir" fetch origin main
        git -C "$deploy_dir" merge-base --is-ancestor HEAD origin/main || {
            echo 'Server checkout contains commits outside origin/main.' >&2; return 1;
        }
        git -C "$deploy_dir" merge --ff-only origin/main
    else
        git clone --branch main --single-branch "$repo_url" "$deploy_dir"
    fi
    [[ -f "$deploy_dir/compose.yaml" && -f "$deploy_dir/Dockerfile" ]] || {
        echo 'Push the deployment files to GitHub main before running this command.' >&2; return 1;
    }

    if ! command -v docker >/dev/null; then
        apt-get update
        apt-get install -y docker.io
    fi
    if docker compose version >/dev/null 2>&1; then
        compose=(docker compose)
    elif command -v docker-compose >/dev/null; then
        compose=(docker-compose)
    else
        apt-get update
        for candidate in docker-compose-v2 docker-compose-plugin docker-compose; do
            if apt-cache show "$candidate" >/dev/null 2>&1; then compose_package="$candidate"; break; fi
        done
        [[ -n "$compose_package" ]] || { echo 'Install Docker Compose for this OS version first.' >&2; return 1; }
        apt-get install -y "$compose_package"
        if docker compose version >/dev/null 2>&1; then compose=(docker compose); else compose=(docker-compose); fi
    fi
    systemctl enable --now docker
    docker info >/dev/null

    install -d -m 700 /etc/video-remove-watermk
    if [[ ! -f "$env_file" ]]; then
        install -m 600 /dev/null "$env_file"
    fi
    export PORT="$listen_port" BIND_ADDRESS="$bind_address"
    cd "$deploy_dir"
    compose+=(--project-name video-remove-watermk --file "$deploy_dir/compose.yaml")
    "${compose[@]}" config --quiet
    if ! "${compose[@]}" up --detach --build; then
        echo "Deployment failed. If TCP $listen_port is already occupied, choose a different unused PORT and keep the existing service running:" >&2
        echo "sudo ss -ltnp '( sport = :$listen_port )'" >&2
        return 1
    fi
    container_id=$("${compose[@]}" ps -q api)
    for attempt in {1..30}; do
        state=$(docker inspect --format '{{.State.Health.Status}}' "$container_id")
        if [[ $state == healthy ]]; then
            echo "Deployed commit $(git rev-parse --short HEAD)."
            echo "Server-local API: http://127.0.0.1:$listen_port/short_videos/sv2.php?url=ENCODED_VIDEO_URL"
            echo 'Parser cookies, if needed: /etc/video-remove-watermk/parser.env'
            echo "Logs: cd $deploy_dir && sudo docker compose -p video-remove-watermk logs --tail=100 -f api"
            if [[ $bind_address == '127.0.0.1' ]]; then
                echo 'Public access: add deploy/nginx-location.conf inside the existing site server block, then validate and reload Nginx.'
                echo "Ensure proxy_pass uses port $listen_port. Existing Nginx and port 3000 services stay running."
            else
                echo "For public access, allow TCP $listen_port in the host firewall and OVH network firewall if enabled."
            fi
            return 0
        fi
        [[ $state != unhealthy ]] || break
        sleep 2
    done
    "${compose[@]}" logs --tail=80 api
    echo 'Container did not become healthy; inspect the logs above.' >&2
    return 1
}

main "$@"
