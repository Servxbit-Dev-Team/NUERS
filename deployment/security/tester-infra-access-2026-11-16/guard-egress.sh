#!/bin/sh
set -eu
# Apply each chain atomically to avoid a transient open egress window on restart.
iptables-restore --wait 10 --noflush <<'RULES'
*filter
:NUERS_VAPT_USER - [0:0]
-F NUERS_VAPT_USER
-A NUERS_VAPT_USER -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
-A NUERS_VAPT_USER -p tcp -d 127.0.0.1 --dport 18080 -j ACCEPT
-A NUERS_VAPT_USER -p tcp -d 10.85.0.70 --dport 3306 -j ACCEPT
-A NUERS_VAPT_USER -p tcp -d 172.17.0.2 --dport 3306 -j ACCEPT
-A NUERS_VAPT_USER -j REJECT
COMMIT
RULES
iptables -w -C OUTPUT -m owner --uid-owner __UID__ -j NUERS_VAPT_USER 2>/dev/null || iptables -w -I OUTPUT 1 -m owner --uid-owner __UID__ -j NUERS_VAPT_USER
ip6tables-restore --wait 10 --noflush <<'RULES'
*filter
:NUERS_VAPT_USER - [0:0]
-F NUERS_VAPT_USER
-A NUERS_VAPT_USER -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
-A NUERS_VAPT_USER -j REJECT
COMMIT
RULES
ip6tables -w -C OUTPUT -m owner --uid-owner __UID__ -j NUERS_VAPT_USER 2>/dev/null || ip6tables -w -I OUTPUT 1 -m owner --uid-owner __UID__ -j NUERS_VAPT_USER
