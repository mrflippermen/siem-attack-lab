# Capítulo 4 — Conclusión

## 4.1 Qué demuestra el laboratorio

Un atacante pasó por tres fases —reconocimiento, explotación y exfiltración—
y consiguió volcar la credencial `admin / S3cr3t_FlaG_db_2026` desde la base de
datos mediante una inyección SQL. **Cada paso dejó rastro en el log del
servidor**, y ese rastro, centralizado en un SIEM, convierte un montón de texto
plano en una historia legible del ataque.

## 4.2 Lecciones aprendidas

### Sobre la defensa (monitoreo)
1. **El log solo sirve si se centraliza y se estructura.** En crudo, el ataque
   estaba ahí pero era invisible entre el ruido. Logstash + etiquetas lo hacen
   buscable y alertable.
2. **El User-Agent es oro barato.** sqlmap, nikto y gobuster se anuncian solos.
   Una regla sobre el User-Agent detecta el 80 % de los escaneos automatizados.
3. **Los códigos de estado cuentan la historia:** una ráfaga de **404** =
   enumeración de directorios; un pico de **500** = inyección rompiendo
   consultas SQL. Vigilar sus tasas es detección temprana.
4. **Detección ≠ prevención, pero la habilita.** Ver el ataque en marcha
   (fase de recon) permite bloquear la IP **antes** de la exfiltración. Ese es
   el valor de tiempo real: cerrar la ventana entre intrusión y brecha.

### Sobre el ataque (causa raíz)
5. **La vulnerabilidad nació en una línea de código:** concatenar input del
   usuario en una consulta. **Sentencias preparadas** la habrían eliminado por
   completo.
6. **Defensa en profundidad:** validación de entrada + sentencias preparadas +
   listas blancas (LFI) + un WAF + el SIEM. Ninguna capa es suficiente sola.

## 4.3 Del log a la alerta (siguiente paso)

Este lab *visualiza*. El paso natural en producción es **alertar**:
- Kibana **Alerting**: regla "si `tags:suspicious` supera N en 1 min → notificar".
- Bloqueo automático: la IP marcada se envía a fail2ban / al WAF / al firewall.

## 4.4 Mapa a un marco de detección

| Fase del ataque | Señal en el SIEM | Control defensivo |
|-----------------|------------------|-------------------|
| Reconocimiento  | Ráfaga de 404, User-Agent de escáner | Rate-limiting, alerta temprana |
| Explotación SQLi| `sqli_pattern`, picos de 500 | Sentencias preparadas, WAF |
| Exfiltración    | `UNION SELECT` con 200 + bytes altos | DLP, monitoreo de respuestas grandes |
| Post-explotación| Acceso a `/etc/passwd`, rutas raras | Lista blanca de includes, EDR |

## 4.5 Conclusión final

El monitoreo de logs no impide el primer paquete del atacante, pero **acorta
drásticamente el tiempo de detección**. En este laboratorio, el intervalo entre
el primer escaneo y la exfiltración fue de segundos; en un entorno real con
alertas sobre estas mismas etiquetas, ese intervalo es la oportunidad de
contener el incidente **antes de que se convierta en una brecha de datos**.
