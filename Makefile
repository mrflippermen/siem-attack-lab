# =====================================================================
#  Makefile — orquestacion del laboratorio SOC
#  Uso rapido:  make soc      (levanta + provisiona el SOC)
#               make attack   (lanza el ataque de 3 fases)
#               make alerts   (muestra el feed de alertas del SOC)
# =====================================================================
DC := docker compose
ES := http://localhost:9200
KB := http://localhost:5601

.DEFAULT_GOAL := help

.PHONY: help install check host up build provision soc attack alerts status logs ps test down clean restart open dashboard web

help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## Instala Docker + Compose + make + python3 en cualquier distro Linux
	@bash install-deps.sh

check: ## Verifica Docker/Compose; si faltan, ofrece instalarlos solo
	@if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then \
	  echo "✗ Faltan Docker o el plugin Compose v2."; \
	  echo ">> Instalando automaticamente con install-deps.sh (cualquier distro) ..."; \
	  bash install-deps.sh; \
	fi
	@command -v docker >/dev/null 2>&1 || { echo "✗ Docker sigue sin estar disponible. Ejecuta 'make install' a mano."; exit 1; }
	@docker compose version >/dev/null 2>&1 || { echo "✗ 'docker compose' v2 no disponible. Ejecuta 'make install'."; exit 1; }
	@docker info >/dev/null 2>&1 || { \
	  echo "✗ No puedo hablar con el demonio de Docker."; \
	  echo "  Arrancalo:  sudo systemctl enable --now docker"; \
	  echo "  Permisos :  sudo usermod -aG docker \$$USER  (cierra sesion y vuelve), o usa 'newgrp docker'"; \
	  echo "  O prueba con sudo:  sudo make soc"; \
	  exit 1; }
	@echo ">> Docker OK: $$(docker --version)"

host: ## Ajusta vm.max_map_count (requisito de Elasticsearch; pide sudo)
	@echo ">> Configurando vm.max_map_count=262144"
	@sudo sysctl -w vm.max_map_count=262144

up: check ## Levanta todos los contenedores (build incluido)
	$(DC) up -d --build

build: check ## Solo construye las imagenes (web, attacker)
	$(DC) build

provision: ## Configura el SOC (plantilla, data views, conector, reglas)
	python3 soc/provision.py

soc: check host up provision ## Pipeline completo: check + host + up + provision
	@echo ">> SOC listo. Kibana: $(KB)  | Target: http://localhost:8080"

attack: ## Ejecuta el ataque de 3 fases desde la attacker box
	$(DC) exec attacker bash /opt/attack.sh

alerts: ## Muestra las ultimas alertas generadas por el SOC
	@echo ">> Feed de alertas del SOC (indice soc-alerts):"
	@python3 soc/show_alerts.py

status: ## Estado del cluster y conteo de eventos
	@echo ">> Cluster:"; curl -s "$(ES)/_cluster/health?pretty" | grep -E 'status|number_of_nodes' || true
	@echo ">> Indices weblogs:"; curl -s "$(ES)/_cat/indices/weblogs-*?v" || true
	@echo ">> Eventos suspicious:"; \
	 curl -s "$(ES)/weblogs-*/_count" -H 'Content-Type: application/json' \
	   -d '{"query":{"term":{"tags":"suspicious"}}}' | python3 -c 'import sys,json;print("  ",json.load(sys.stdin).get("count"))' 2>/dev/null || true

ps: ## docker compose ps
	$(DC) ps

logs: ## Sigue los logs de logstash y filebeat (Ctrl-C para salir)
	$(DC) logs -f logstash filebeat

test: soc ## Test completo automatizado: levanta, ataca y muestra alertas
	@echo ">> Generando trafico legitimo de fondo ..."
	@$(DC) exec -T attacker bash -c 'for i in $$(seq 1 10); do curl -s -o /dev/null http://web/ ; curl -s -o /dev/null "http://web/product.php?id=$$((RANDOM%4+1))"; done'
	@echo ">> Lanzando ataque de 3 fases ..."
	@$(DC) exec -T attacker bash /opt/attack.sh || true
	@echo ">> Esperando a que las reglas evaluen (~40s) ..."
	@sleep 40
	@$(MAKE) --no-print-directory status
	@$(MAKE) --no-print-directory alerts

dashboard: ## (Re)genera e importa el dashboard de Kibana con Lens
	python3 soc/build_dashboard.py
	@curl -s -X POST "$(KB)/api/saved_objects/_import?overwrite=true" -H "kbn-xsrf: true" \
	  -F file=@kibana/soc-dashboard.ndjson | python3 -c 'import sys,json;d=json.load(sys.stdin);print("  dashboard import:",d.get("success"),d.get("successCount"))'

web: ## Abre la web vulnerable en el navegador
	@xdg-open http://localhost:8080 >/dev/null 2>&1 || echo "Abre manualmente http://localhost:8080"

open: ## Abre Kibana en el navegador (Linux)
	@xdg-open $(KB) >/dev/null 2>&1 || echo "Abre manualmente $(KB)"

restart: ## Reinicia los contenedores
	$(DC) restart

down: ## Detiene los contenedores (conserva datos)
	$(DC) down

clean: ## Detiene y BORRA volumenes (datos de ES y logs)
	$(DC) down -v
