DOCKER_EXEC = docker compose exec app

.PHONY: up down build restart shell logs \
        migrate fresh seed test artisan \
        cache-clear queue-work horizon \
        es-index monitoring-up monitoring-down metrics lab

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

es-index:
	$(DOCKER_EXEC) php artisan products:index

# ── Monitoring (Task03) ──────────────────────────────────────────────────────
# Prometheus http://127.0.0.1:9090   Grafana http://127.0.0.1:3001

monitoring-up:
	docker compose --profile monitoring up -d

monitoring-down:
	docker compose --profile monitoring stop prometheus grafana redis-exporter postgres-exporter php-fpm-exporter cadvisor

metrics:
	docker compose exec nginx wget -qO- http://127.0.0.1:8080/metrics

# ── Monitoring lab (Task03) ──────────────────────────────────────────────────
# make lab s=slow                     make lab s="n-plus-one?fixed=1"
# make lab m=POST s=jobs/backlog      make lab m=POST s="jobs/failing?mode=flaky"
LAB_TOKEN = $(shell grep '^LAB_TOKEN=' .env.docker | cut -d= -f2)

lab:
	@curl -s -X $(or $(m),GET) -H "Accept: application/json" -H "X-Lab-Token: $(LAB_TOKEN)" "http://localhost:8000/api/v1/lab/$(s)"; echo
