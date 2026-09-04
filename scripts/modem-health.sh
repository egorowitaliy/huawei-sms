#!/bin/bash

set -uo pipefail

MODEM_URL="${MODEM_URL:-http://192.168.8.1}"
MODEM_URL="${MODEM_URL%/}"
MODEM_HOST="${MODEM_HOST:-$(
    printf '%s' "$MODEM_URL" |
        sed -E 's#^[a-zA-Z]+://##; s#/.*$##; s/:.*$//'
)}"

CURL_CONNECT_TIMEOUT="${CURL_CONNECT_TIMEOUT:-2}"
CURL_MAX_TIME="${MODEM_TIMEOUT:-3}"
MODEM_LOCK_FILE="${MODEM_LOCK_FILE:-}"

if [[ -n "$MODEM_LOCK_FILE" ]] && command -v flock >/dev/null 2>&1; then
    exec 9>"$MODEM_LOCK_FILE"

    if ! flock -w 10 9; then
        echo "МОДЕМ"
        echo "API: занят другим процессом"
        exit 11
    fi
fi


xml_get()
{
    local xml="${1:-}"
    local tag="${2:-}"

    printf '%s' "$xml" |
        tr -d '\r\n' |
        sed -n \
            "s:.*<${tag}>\([^<]*\)</${tag}>.*:\1:p" |
        head -n 1
}


format_connection_status()
{
    case "${1:-}" in
        900) printf 'подключение' ;;
        901) printf 'подключено' ;;
        902) printf 'отключено' ;;
        903) printf 'отключение' ;;
        904) printf 'ошибка подключения' ;;
        905) printf 'нет соединения' ;;
        '')  printf 'неизвестно' ;;
        *)   printf 'код %s' "$1" ;;
    esac
}


format_network_type()
{
    case "${1:-}" in
        1)       printf 'GSM' ;;
        2)       printf 'GPRS' ;;
        3)       printf 'EDGE' ;;
        4|41)    printf 'WCDMA' ;;
        5|42)    printf 'HSDPA' ;;
        6|43)    printf 'HSUPA' ;;
        7|44)    printf 'HSPA' ;;
        9|45)    printf 'HSPA+' ;;
        46)      printf 'DC-HSPA+' ;;
        19|101)  printf 'LTE' ;;
        0|'')    printf 'нет сети' ;;
        *)       printf 'тип %s' "$1" ;;
    esac
}


api_get()
{
    local endpoint="${1:-}"

    curl \
        -fsS \
        --connect-timeout "$CURL_CONNECT_TIMEOUT" \
        --max-time "$CURL_MAX_TIME" \
        -H "Cookie: ${COOKIE}" \
        -H "__RequestVerificationToken: ${TOKEN}" \
        "${MODEM_URL}${endpoint}" \
        2>/dev/null
}


api_response_ok()
{
    local xml="${1:-}"

    [[ -n "$xml" ]] &&
        [[ "$xml" != *"<error>"* ]]
}


PING_OUTPUT="$(
    ping \
        -n \
        -c 1 \
        -W 1 \
        "$MODEM_HOST" \
        2>/dev/null ||
        true
)"

PING_MS="$(
    printf '%s\n' "$PING_OUTPUT" |
        sed -n \
            's/.*time=\([0-9.]*\) ms.*/\1/p' |
        head -n 1
)"

START_MS="$(date +%s%3N)"

SESSION_XML="$(
    curl \
        -fsS \
        --connect-timeout "$CURL_CONNECT_TIMEOUT" \
        --max-time "$CURL_MAX_TIME" \
        "${MODEM_URL}/api/webserver/SesTokInfo" \
        2>/dev/null ||
        true
)"

SESSION="$(xml_get "$SESSION_XML" "SesInfo")"
TOKEN="$(xml_get "$SESSION_XML" "TokInfo")"

echo "МОДЕМ"

if [[ -z "$SESSION" || -z "$TOKEN" ]]; then
    if [[ -n "$PING_MS" ]]; then
        echo "Доступность: ICMP OK (${PING_MS} мс)"
        echo "API: недоступен"
    else
        echo "Доступность: нет связи"
    fi

    exit 10
fi

if [[ "$SESSION" == *=* ]]; then
    COOKIE="$SESSION"
else
    COOKIE="SessionID=${SESSION}"
fi

STATUS_XML="$(api_get "/api/monitoring/status" || true)"
SIGNAL_XML="$(api_get "/api/device/signal" || true)"
PLMN_XML="$(api_get "/api/net/current-plmn" || true)"
SMS_XML="$(api_get "/api/sms/sms-count" || true)"

if ! api_response_ok "$STATUS_XML"; then
    API_TIME_MS="$(( $(date +%s%3N) - START_MS ))"
    echo "Доступность: API отвечает с ошибкой"
    echo "Ошибка API: не удалось получить состояние соединения"
    echo "API: ${API_TIME_MS} мс"
    exit 12
fi

OPTIONAL_ERRORS=()

api_response_ok "$SIGNAL_XML" || OPTIONAL_ERRORS+=("параметры сигнала")
api_response_ok "$PLMN_XML" || OPTIONAL_ERRORS+=("оператор")
api_response_ok "$SMS_XML" || OPTIONAL_ERRORS+=("счётчик SMS")

API_TIME_MS="$(( $(date +%s%3N) - START_MS ))"

CONNECTION_CODE="$(
    xml_get "$STATUS_XML" "ConnectionStatus"
)"

CONNECTION="$(
    format_connection_status "$CONNECTION_CODE"
)"

NETWORK_CODE="$(
    xml_get "$STATUS_XML" "CurrentNetworkTypeEx"
)"

if [[ -z "$NETWORK_CODE" ]]; then
    NETWORK_CODE="$(
        xml_get "$STATUS_XML" "CurrentNetworkType"
    )"
fi

NETWORK="$(
    format_network_type "$NETWORK_CODE"
)"

WAN_IP="$(xml_get "$STATUS_XML" "WanIPAddress")"
SIGNAL_ICON="$(xml_get "$STATUS_XML" "SignalIcon")"

OPERATOR="$(xml_get "$PLMN_XML" "FullName")"

if [[ -z "$OPERATOR" ]]; then
    OPERATOR="$(xml_get "$PLMN_XML" "ShortName")"
fi

RSRP="$(xml_get "$SIGNAL_XML" "rsrp")"
RSRQ="$(xml_get "$SIGNAL_XML" "rsrq")"
RSSI="$(xml_get "$SIGNAL_XML" "rssi")"
SINR="$(xml_get "$SIGNAL_XML" "sinr")"

UNREAD_SMS="$(xml_get "$SMS_XML" "LocalUnread")"

if [[ -n "$PING_MS" ]]; then
    echo "Доступность: OK (${PING_MS} мс)"
else
    echo "Доступность: API доступен, ICMP NA"
fi

echo "Связь: ${CONNECTION}"

if [[ -n "$OPERATOR" ]]; then
    echo "Оператор: ${OPERATOR}"
fi

echo "Сеть: ${NETWORK}"

if [[ -n "$WAN_IP" && "$WAN_IP" != "0.0.0.0" ]]; then
    echo "IP: ${WAN_IP}"
fi

if [[ -n "$SIGNAL_ICON" ]]; then
    echo "Сигнал: ${SIGNAL_ICON}/5"
fi

SIGNAL_PARTS=()

if [[ -n "$RSRP" ]]; then
    SIGNAL_PARTS+=("RSRP ${RSRP}")
fi

if [[ -n "$RSRQ" ]]; then
    SIGNAL_PARTS+=("RSRQ ${RSRQ}")
fi

if [[ -n "$SINR" ]]; then
    SIGNAL_PARTS+=("SINR ${SINR}")
fi

if [[ -n "$RSSI" ]]; then
    SIGNAL_PARTS+=("RSSI ${RSSI}")
fi

if [[ "${#SIGNAL_PARTS[@]}" -gt 0 ]]; then
    printf 'Радио: '

    printf '%s' "${SIGNAL_PARTS[0]}"

    for ((INDEX = 1; INDEX < ${#SIGNAL_PARTS[@]}; INDEX++)); do
        printf ', %s' "${SIGNAL_PARTS[$INDEX]}"
    done

    printf '\n'
fi

if [[ -n "$UNREAD_SMS" ]]; then
    echo "Непрочитанные SMS: ${UNREAD_SMS}"
fi

if [[ "${#OPTIONAL_ERRORS[@]}" -gt 0 ]]; then
    printf 'Нет данных: %s' "${OPTIONAL_ERRORS[0]}"

    for ((INDEX = 1; INDEX < ${#OPTIONAL_ERRORS[@]}; INDEX++)); do
        printf ', %s' "${OPTIONAL_ERRORS[$INDEX]}"
    done

    printf '\n'
fi

echo "API: ${API_TIME_MS} мс"

exit 0
