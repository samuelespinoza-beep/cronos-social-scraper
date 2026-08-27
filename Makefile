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

chrome: ## Abre el Chrome de depuracion (puerto 9222) que Claude puede ver, para scraping
	@echo "Cerrando cualquier ventana de depuracion anterior..."
	@pkill -f "[r]emote-debugging-port=9222" 2>/dev/null; sleep 2; true
	@echo "Abriendo Chrome de depuracion en el puerto 9222..."
	@setsid google-chrome --remote-debugging-port=9222 --user-data-dir="$$HOME/.chrome-debug" --no-first-run --no-default-browser-check </dev/null >/dev/null 2>&1 & disown; \
	sleep 4; \
	curl -s http://localhost:9222/json/version >/dev/null 2>&1 && echo "OK: Chrome activo. Navega en esa ventana y pidele a Claude que la lea." || echo "ERROR: no arranco"

chrome-stop: ## Cierra la ventana de Chrome de depuracion (no toca tu Chrome normal)
	@pkill -f "[r]emote-debugging-port=9222" 2>/dev/null && echo "Ventana de depuracion cerrada" || echo "No habia ninguna abierta"

network: ## Escucha en vivo las requests de la ventana de depuracion. Filtro opcional: make network F=graphql
	@node scripts/watch-network.mjs $(F)

detail: ## Muestra headers/payload/response de una request capturada. Uso: make detail N=12
	@node scripts/request-detail.mjs $(N)

cookies: ## Muestra las cookies (Application) del sitio abierto, incl. httpOnly. Filtro: make cookies D=instagram (o D=all)
	@node scripts/cookies-dump.mjs $(D)

capture: ## Captura de una pasada (headless): red + cookies del perfil, para cron. URL opcional: make capture URL=...
	@node scripts/capture-once.mjs $(URL)
