.PHONY: up start down restart build logs ps console migrate test sh

up: ## Start the application and wait until reachable (no rebuild: code is live-mounted and workers restart on file change)
	docker compose up -d
	@echo "Waiting for http://localhost/healthz ..."
	@for i in $$(seq 1 90); do \
		if curl -sf http://localhost/healthz >/dev/null; then \
			echo "Application is up: http://localhost"; \
			break; \
		fi; \
		if [ $$i -eq 90 ]; then \
			echo "Timed out waiting for the app:"; docker compose ps; exit 1; \
		fi; \
		sleep 2; \
	done

rebuild: build up ## Rebuild the image and restart (fresh clone, Dockerfile or PHP extension changes)

start: up ## Alias for up

down: ## Stop the application (keeps volumes)
	docker compose down

restart: down up ## Restart the application

build: ## Build the app image without starting (fresh clone, new PHP extensions, composer.json/lock changes)
	docker compose build app

logs: ## Tail logs of all services (SVC=name to filter)
	docker compose logs -f $(SVC)

ps: ## Show container status
	docker compose ps

console: ## Run a console command inside the app container, e.g. make console CMD="doctrine:migrations:migrate"
	docker compose exec app php bin/console $(CMD)

migrate: ## Run pending Doctrine migrations inside the app container
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

test: ## Reset the test DB, migrate it and run PHPUnit inside the app container
	docker compose exec app php bin/console doctrine:database:drop --force --env=test || true
	docker compose exec app php bin/console doctrine:database:create --env=test
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
	docker compose exec -e APP_ENV=test app php bin/phpunit

sh: ## Open a shell inside the app container
	docker compose exec app sh
