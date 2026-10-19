# NUERS_API_KEY must already be in the process environment.
# Avoid shell tracing; protect output files.
umask 077
curl --config - <<EOF
url = "https://nuers.net/api/integrations/transactions"
request = "POST"
header = "Accept: application/json"
header = "Content-Type: application/json"
header = "X-NUERS-API-Key: ${NUERS_API_KEY}"
data-binary = "@event.json"
connect-timeout = 10
max-time = 30
output = "nuers-response.json"
dump-header = "nuers-response.headers"
write-out = "%{http_code}\n"
EOF
