#!/usr/bin/env bash
# =====================================================================
#  install-deps.sh — instala las dependencias del laboratorio en
#  CUALQUIER distro Linux: Docker Engine + plugin Compose v2 + make + python3.
#
#  Soporta:  Debian/Ubuntu/Kali/Parrot/Mint  (apt)
#            Fedora/RHEL/CentOS/Rocky/Alma     (dnf / yum)
#            Arch/Manjaro                      (pacman)
#            openSUSE/SLES                     (zypper)
#            Alpine                            (apk)
#  Fallback: script oficial de Docker (https://get.docker.com)
#
#  Uso:  ./install-deps.sh      (pedira sudo)   |   sudo ./install-deps.sh
# =====================================================================
set -euo pipefail

# --- sudo solo si no somos root ---
if [ "$(id -u)" -ne 0 ]; then SUDO="sudo"; else SUDO=""; fi
# usuario real al que dar permisos de docker (aunque corramos con sudo)
TARGET_USER="${SUDO_USER:-$(id -un)}"

say() { printf '\033[36m>>\033[0m %s\n' "$*"; }
err() { printf '\033[31m✗\033[0m %s\n' "$*" >&2; }

# --- ya esta todo? ---
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  say "Docker y Compose v2 ya estan instalados:"
  docker --version; docker compose version
else
  say "Detectando distribucion ..."
  ID=""; ID_LIKE=""
  [ -r /etc/os-release ] && . /etc/os-release || true
  FAMILY="${ID:-} ${ID_LIKE:-}"
  say "Distro: ${PRETTY_NAME:-desconocida}"

  install_apt()    { $SUDO apt-get update -y; \
                     $SUDO apt-get install -y docker.io docker-compose-v2 make python3 curl \
                     || $SUDO apt-get install -y docker.io make python3 curl; }
  install_dnf()    { $SUDO dnf install -y docker docker-compose-plugin make python3 curl \
                     || $SUDO dnf install -y moby-engine docker-compose make python3 curl; }
  install_yum()    { $SUDO yum install -y docker docker-compose-plugin make python3 curl; }
  install_pacman() { $SUDO pacman -Sy --noconfirm docker docker-compose make python curl; }
  install_zypper() { $SUDO zypper --non-interactive install docker docker-compose make python3 curl; }
  install_apk()    { $SUDO apk add --no-cache docker docker-cli-compose make python3 curl; }
  install_official() {
    say "Usando el script oficial de Docker (get.docker.com) ..."
    curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
    $SUDO sh /tmp/get-docker.sh
    # make/python3 con el gestor que haya
    command -v apt-get >/dev/null && $SUDO apt-get install -y make python3 || true
    command -v dnf     >/dev/null && $SUDO dnf install -y make python3 || true
    command -v pacman  >/dev/null && $SUDO pacman -Sy --noconfirm make python || true
    command -v zypper  >/dev/null && $SUDO zypper --non-interactive install make python3 || true
    command -v apk     >/dev/null && $SUDO apk add --no-cache make python3 || true
  }

  case "$FAMILY" in
    *debian*|*ubuntu*)            install_apt    ;;
    *fedora*|*rhel*|*centos*)     install_dnf || install_yum || install_official ;;
    *arch*|*manjaro*)            install_pacman ;;
    *suse*|*sles*)               install_zypper ;;
    *alpine*)                    install_apk    ;;
    *)  # distro no reconocida: probamos por gestor de paquetes disponible
        if   command -v apt-get >/dev/null; then install_apt
        elif command -v dnf     >/dev/null; then install_dnf || install_official
        elif command -v yum     >/dev/null; then install_yum || install_official
        elif command -v pacman  >/dev/null; then install_pacman
        elif command -v zypper  >/dev/null; then install_zypper
        elif command -v apk     >/dev/null; then install_apk
        else install_official; fi ;;
  esac
fi

# --- arrancar y habilitar el demonio ---
if command -v systemctl >/dev/null 2>&1; then
  $SUDO systemctl enable --now docker || true
  $SUDO systemctl start docker 2>/dev/null || true   # fuerza start si ya estaba enable
elif command -v rc-update >/dev/null 2>&1; then   # OpenRC (Alpine)
  $SUDO rc-update add docker boot || true
  $SUDO service docker start || true
fi

# --- permitir usar docker sin sudo ---
$SUDO usermod -aG docker "$TARGET_USER" 2>/dev/null || true

# --- garantizar el plugin Compose v2 ---
if ! docker compose version >/dev/null 2>&1; then
  say "Instalando el plugin 'docker compose' v2 manualmente ..."
  DEST=/usr/local/lib/docker/cli-plugins
  $SUDO mkdir -p "$DEST"
  ARCH="$(uname -m)"
  $SUDO curl -fsSL \
    "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-${ARCH}" \
    -o "$DEST/docker-compose"
  $SUDO chmod 0755 "$DEST/docker-compose"
fi

echo
say "Verificacion final:"
docker --version || err "docker no quedo instalado"
docker compose version || err "compose v2 no quedo disponible"
echo
if sg docker -c "docker info" >/dev/null 2>&1; then
  printf '\033[32m✓\033[0m Docker demonio accesible con el grupo docker.\n'
else
  printf '\033[33m⚠️  Docker instalado pero el shell actual no tiene el grupo docker.\n'
  printf '    Makefile lo detectara y usara automaticamente "sg docker".\033[0m\n'
fi
