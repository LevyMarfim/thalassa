# Automate commands

up:
	docker compose up -d --build

down:
	docker compose down

prune:
	docker system prune -a
	docker volume prune -a
	docker network prune