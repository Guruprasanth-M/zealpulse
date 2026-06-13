#!/bin/bash
# Non-PHP CGI (RFC 3875) reached via cgiScriptAlias('/cgi-bin/'). Emits a CGI
# header block (Content-Type + Status:) then a blank line then the body; the
# framework's cgiInterpreterResponse parses Status:/headers and threads them back.
echo "Content-Type: text/plain"
echo "Status: 200 OK"
echo "X-CGI-Kind: shell"
echo ""
echo "zealpulse shell CGI — host status"
echo "date_utc:          $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "host:              $(hostname)"
echo "REQUEST_METHOD:    ${REQUEST_METHOD:-?}"
echo "QUERY_STRING:      ${QUERY_STRING:-}"
echo "GATEWAY_INTERFACE: ${GATEWAY_INTERFACE:-?}"
echo "SERVER_SOFTWARE:   ${SERVER_SOFTWARE:-?}"
echo "SCRIPT_NAME:       ${SCRIPT_NAME:-?}"
echo "HTTP_PROXY:        ${HTTP_PROXY:-<unset-good>}"
