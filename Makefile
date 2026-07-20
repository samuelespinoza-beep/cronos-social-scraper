PORT ?= 8010

help: ## Prints this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

dev: ## Starts the local dev server (php artisan serve), foreground, Ctrl+C to stop
	@php artisan serve --host=0.0.0.0 --port=$(PORT)

down: ## Kills any dev server left running on $(PORT) (por si quedo en background o en otra terminal)
	@pkill -f "artisan serve" 2>/dev/null && echo "Servidor detenido" || echo "No hay servidor corriendo"

clear: ## exec: php artisan [config:clear + route:clear + view:clear + cache:clear]
	@php artisan config:clear
	@php artisan route:clear
	@php artisan view:clear
	@php artisan cache:clear

install: ## Installs composer dependencies
	@composer install

migrate: ## Runs pending migrations
	@php artisan migrate

test: ## exec: phpunit
	@php artisan test
