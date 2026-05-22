.PHONY: build up down restart status logs backend-shell frontend-shell migrate seed key-generate test fetch setup

# Build containers
build:
	docker compose build

# Start containers
up:
	docker compose up -d

# Stop containers
down:
	docker compose down

# Restart containers
restart:
	docker compose down && docker compose up -d

# Check status of containers
status:
	docker compose ps

# View real-time logs
logs:
	docker compose logs -f

# Enter Backend container shell
backend-shell:
	docker compose exec backend sh

# Enter Frontend container shell
frontend-shell:
	docker compose exec frontend sh

# Run database migrations
migrate:
	docker compose exec backend php artisan migrate --force

# Seed database
seed:
	docker compose exec backend php artisan db:seed

# Generate application key
key-generate:
	docker compose exec backend php artisan key:generate

# Run Laravel test suite
test:
	docker compose exec backend php artisan test

# Manually trigger email aggregation and evaluation
fetch:
	docker compose exec backend php artisan emails:fetch

# Full project setup after checkout
setup:
	@echo "Initializing Gmail Aggregator & Evaluator Project Setup..."
	@mkdir -p backend frontend
	docker compose build
	docker compose up -d
	@echo "Waiting for services to initialize..."
	sleep 10
	docker compose exec backend composer install
	docker compose exec backend cp .env.example .env || true
	docker compose exec backend php artisan key:generate
	docker compose exec backend php artisan migrate --force
	@echo "Setup complete! Open http://localhost:8088 in your browser."
