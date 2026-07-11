FROM kalilinux/kali-rolling

ENV DEBIAN_FRONTEND=noninteractive

# Herramientas de las 3 fases: recon, explotacion, soporte
RUN apt-get update && apt-get install -y --no-install-recommends \
        nmap \
        sqlmap \
        nikto \
        gobuster \
        dirb \
        hydra \
        whatweb \
        curl \
        wget \
        netcat-traditional \
        wordlists \
        ca-certificates \
        iputils-ping \
        dnsutils \
    && rm -rf /var/lib/apt/lists/*

# Descomprime la wordlist de directorios mas usada (gobuster/dirb)
RUN [ -f /usr/share/wordlists/dirb/common.txt ] || true

# Cadena de ataque automatizada de las 3 fases
COPY attack.sh /opt/attack.sh
RUN chmod +x /opt/attack.sh

WORKDIR /root
# Metasploit y Burp headless son opcionales (imagen pesada). Para anadir msf:
#   apt-get install -y metasploit-framework
CMD ["bash"]
