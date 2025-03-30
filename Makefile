# Automate commands

up:
	docker compose up -d --build

down:
	docker compose down

prune:
	docker volume prune -a
	docker network prune
	docker system prune -a