#!/bin/sh
# Restrict only inbound MariaDB TCP/3306. Approved NUERS database clients, 2026-10-29.
# Does not flush host firewall policy or change SSH, HTTP, Plesk or DB users.
set -eu
CHAIN=NUERS_DB_INGRESS
IP4=/usr/sbin/iptables
IP6=/usr/sbin/ip6tables
case "${1:-apply}" in
  apply)
    for tool in "$IP4" "$IP6"; do
      "$tool" -w 10 -N "$CHAIN" 2>/dev/null || "$tool" -w 10 -S "$CHAIN" >/dev/null
      "$tool" -w 10 -F "$CHAIN"
      "$tool" -w 10 -A "$CHAIN" -i lo -j RETURN
    done
    "$IP4" -w 10 -A "$CHAIN" -s 217.15.162.246/32 -j RETURN
    "$IP4" -w 10 -A "$CHAIN" -s 194.233.94.62/32 -j RETURN
    for tool in "$IP4" "$IP6"; do
      "$tool" -w 10 -A "$CHAIN" -p tcp -j REJECT --reject-with tcp-reset
      "$tool" -w 10 -C INPUT -p tcp --dport 3306 -j "$CHAIN" 2>/dev/null || "$tool" -w 10 -I INPUT 1 -p tcp --dport 3306 -j "$CHAIN"
    done
    ;;
  rollback)
    for tool in "$IP4" "$IP6"; do
      while "$tool" -w 10 -C INPUT -p tcp --dport 3306 -j "$CHAIN" 2>/dev/null; do
        "$tool" -w 10 -D INPUT -p tcp --dport 3306 -j "$CHAIN"
      done
      if "$tool" -w 10 -S "$CHAIN" >/dev/null 2>&1; then
        "$tool" -w 10 -F "$CHAIN"
        "$tool" -w 10 -X "$CHAIN"
      fi
    done
    systemctl disable nuers-db-ingress.service >/dev/null 2>&1 || true
    ;;
  *) echo 'Usage: nuers-db-ingress.sh apply|rollback' >&2; exit 2 ;;
esac
