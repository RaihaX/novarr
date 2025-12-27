#!/bin/zsh

# =============================================================================
# Novarr Smoke Test Script
# =============================================================================
# Validates deployment health and functionality
# Usage: ./docker/scripts/smoke-test.sh [container_name] [--verbose]
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Configuration
CONTAINER_NAME="${1:-novarr-app}"
VERBOSE=false
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_TOTAL=0

# Parse args
for arg in "$@"; do
    case $arg in
        --verbose|-v)
            VERBOSE=true
            ;;
    esac
done

# Functions
print_header() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${CYAN}========================================${NC}"
    echo ""
}

print_test() {
    local name=$1
    local result=$2
    local details=$3

    TESTS_TOTAL=$((TESTS_TOTAL + 1))

    if [[ "$result" == "pass" ]]; then
        echo -e "${GREEN}✓${NC} $name"
        TESTS_PASSED=$((TESTS_PASSED + 1))
    else
        echo -e "${RED}✗${NC} $name"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        if [[ -n "$details" ]]; then
            echo -e "  ${RED}$details${NC}"
        fi
    fi

    if [[ "$VERBOSE" == "true" ]] && [[ -n "$details" ]] && [[ "$result" == "pass" ]]; then
        echo -e "  ${CYAN}$details${NC}"
    fi
}

# =============================================================================
# Container Tests
# =============================================================================

test_container_running() {
    if docker ps -q -f name="$CONTAINER_NAME" | grep -q .; then
        print_test "Container is running" "pass" "Container: $CONTAINER_NAME"
        return 0
    else
        print_test "Container is running" "fail" "Container $CONTAINER_NAME not found"
        return 1
    fi
}

test_container_healthy() {
    local health=$(docker inspect --format='{{.State.Health.Status}}' "$CONTAINER_NAME" 2>/dev/null || echo "unknown")

    if [[ "$health" == "healthy" ]]; then
        print_test "Container health status" "pass" "Status: healthy"
        return 0
    else
        print_test "Container health status" "fail" "Status: $health"
        return 1
    fi
}

# =============================================================================
# HTTP Endpoint Tests
# =============================================================================

test_health_endpoint() {
    local response
    response=$(docker exec "$CONTAINER_NAME" curl -sf http://localhost:8000/api/health 2>/dev/null)

    if [[ $? -eq 0 ]]; then
        print_test "Health endpoint (internal)" "pass" "Response: $response"
        return 0
    else
        print_test "Health endpoint (internal)" "fail" "No response"
        return 1
    fi
}

test_health_endpoint_external() {
    local response
    response=$(curl -sf http://localhost:8000/api/health 2>/dev/null || curl -sf http://localhost/api/health 2>/dev/null)

    if [[ $? -eq 0 ]] && [[ -n "$response" ]]; then
        print_test "Health endpoint (external)" "pass" "Response: $response"
        return 0
    else
        print_test "Health endpoint (external)" "fail" "No response from localhost:8000 or localhost:80"
        return 1
    fi
}

# =============================================================================
# Application Tests
# =============================================================================

test_artisan_cli() {
    local version
    version=$(docker exec "$CONTAINER_NAME" php artisan --version 2>/dev/null)

    if [[ $? -eq 0 ]] && [[ -n "$version" ]]; then
        print_test "Artisan CLI" "pass" "$version"
        return 0
    else
        print_test "Artisan CLI" "fail" "Command failed"
        return 1
    fi
}

test_database_connection() {
    local result
    result=$(docker exec "$CONTAINER_NAME" php artisan tinker --execute="echo DB::connection()->getDatabaseName();" 2>/dev/null)

    if [[ $? -eq 0 ]] && [[ -n "$result" ]]; then
        print_test "Database connection" "pass" "Database: $result"
        return 0
    else
        print_test "Database connection" "fail" "Connection failed"
        return 1
    fi
}

test_redis_connection() {
    local result
    result=$(docker exec "$CONTAINER_NAME" php artisan tinker --execute="echo Redis::ping();" 2>/dev/null)

    if [[ $? -eq 0 ]]; then
        print_test "Redis connection" "pass" "Response: $result"
        return 0
    else
        print_test "Redis connection" "fail" "Connection failed"
        return 1
    fi
}

test_storage_writable() {
    local test_file="/var/www/html/storage/logs/smoke-test-$(date +%s).tmp"

    if docker exec "$CONTAINER_NAME" touch "$test_file" 2>/dev/null; then
        docker exec "$CONTAINER_NAME" rm "$test_file" 2>/dev/null || true
        print_test "Storage writable" "pass" "Can write to storage/logs"
        return 0
    else
        print_test "Storage writable" "fail" "Cannot write to storage"
        return 1
    fi
}

test_cache_operations() {
    local key="smoke_test_$(date +%s)"

    # Set cache value
    if docker exec "$CONTAINER_NAME" php artisan tinker --execute="Cache::put('$key', 'test', 60);" 2>/dev/null; then
        # Get cache value
        local value
        value=$(docker exec "$CONTAINER_NAME" php artisan tinker --execute="echo Cache::get('$key');" 2>/dev/null)

        # Cleanup
        docker exec "$CONTAINER_NAME" php artisan tinker --execute="Cache::forget('$key');" 2>/dev/null || true

        if [[ "$value" == *"test"* ]]; then
            print_test "Cache operations" "pass" "Set/Get/Forget working"
            return 0
        fi
    fi

    print_test "Cache operations" "fail" "Cache operations failed"
    return 1
}

# =============================================================================
# Performance Tests
# =============================================================================

test_response_time() {
    local start_time=$(date +%s%N)

    docker exec "$CONTAINER_NAME" curl -sf http://localhost:8000/api/health &>/dev/null

    local end_time=$(date +%s%N)
    local duration_ms=$(( (end_time - start_time) / 1000000 ))

    if [[ $duration_ms -lt 1000 ]]; then
        print_test "Response time" "pass" "${duration_ms}ms (< 1000ms)"
        return 0
    else
        print_test "Response time" "fail" "${duration_ms}ms (>= 1000ms)"
        return 1
    fi
}

# =============================================================================
# Nginx Tests
# =============================================================================

test_nginx_running() {
    if docker ps -q -f name=novarr-nginx | grep -q .; then
        print_test "Nginx is running" "pass"
        return 0
    else
        print_test "Nginx is running" "fail" "Container not running"
        return 1
    fi
}

test_nginx_config() {
    if docker exec novarr-nginx nginx -t 2>/dev/null; then
        print_test "Nginx config valid" "pass"
        return 0
    else
        print_test "Nginx config valid" "fail" "Config test failed"
        return 1
    fi
}

# =============================================================================
# Main Execution
# =============================================================================

print_header "Novarr Smoke Tests"

echo -e "Container: ${CYAN}$CONTAINER_NAME${NC}"
echo ""

# Container tests
echo -e "${YELLOW}Container Tests:${NC}"
test_container_running || true
test_container_healthy || true
echo ""

# HTTP tests
echo -e "${YELLOW}HTTP Endpoint Tests:${NC}"
test_health_endpoint || true
test_health_endpoint_external || true
echo ""

# Application tests
echo -e "${YELLOW}Application Tests:${NC}"
test_artisan_cli || true
test_database_connection || true
test_redis_connection || true
test_storage_writable || true
test_cache_operations || true
echo ""

# Performance tests
echo -e "${YELLOW}Performance Tests:${NC}"
test_response_time || true
echo ""

# Nginx tests
echo -e "${YELLOW}Nginx Tests:${NC}"
test_nginx_running || true
test_nginx_config || true
echo ""

# Summary
print_header "Test Summary"

echo -e "Total:   $TESTS_TOTAL"
echo -e "Passed:  ${GREEN}$TESTS_PASSED${NC}"
echo -e "Failed:  ${RED}$TESTS_FAILED${NC}"
echo ""

if [[ $TESTS_FAILED -eq 0 ]]; then
    echo -e "${GREEN}All smoke tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some smoke tests failed.${NC}"
    exit 1
fi
