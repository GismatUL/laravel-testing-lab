DOCKER_EXEC = docker compose exec app

.PHONY: up down build restart shell logs \
        migrate fresh seed test artisan \
        cache-clear queue-work horizon

# ── Container lifecycle ──────────────────────────────────────────────────────

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose up -d --build

restart:
	docker compose restart

# ── Shell access ─────────────────────────────────────────────────────────────

shell:
	$(DOCKER_EXEC) sh

logs:
	docker compose logs -f app

# ── Laravel ──────────────────────────────────────────────────────────────────

migrate:
	$(DOCKER_EXEC) php artisan migrate

fresh:
	$(DOCKER_EXEC) php artisan migrate:fresh --seed

seed:
	$(DOCKER_EXEC) php artisan db:seed

test:
	$(DOCKER_EXEC) php artisan test

artisan:
	$(DOCKER_EXEC) php artisan $(cmd)

cache-clear:
	$(DOCKER_EXEC) php artisan cache:clear
	$(DOCKER_EXEC) php artisan config:clear
	$(DOCKER_EXEC) php artisan route:clear

# ── Workers ──────────────────────────────────────────────────────────────────

queue-work:
	$(DOCKER_EXEC) php artisan queue:work redis --tries=3

horizon:
	$(DOCKER_EXEC) php artisan horizon
