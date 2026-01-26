#!/bin/bash

# FormVox Load Test Script
# Tests concurrent form submissions to verify locking mechanism works correctly

# Configuration
BASE_URL="${1:-https://your-nextcloud-instance.com}"
TOKEN="${2:-your-form-token}"
CONCURRENT="${3:-20}"
TOTAL="${4:-50}"

ENDPOINT="$BASE_URL/apps/formvox/f/$TOKEN/submit"

echo "FormVox Concurrent Submission Load Test"
echo "========================================"
echo "URL: $ENDPOINT"
echo "Concurrent requests: $CONCURRENT"
echo "Total requests: $TOTAL"
echo ""

# Check if curl is available
if ! command -v curl &> /dev/null; then
    echo "Error: curl is required"
    exit 1
fi

# Create temp directory for results
TMPDIR=$(mktemp -d)
echo "Results directory: $TMPDIR"
echo ""

# Function to get time in milliseconds (cross-platform)
get_time_ms() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS: use python for millisecond precision
        python3 -c 'import time; print(int(time.time() * 1000))'
    else
        # Linux: use date with nanoseconds
        echo $(($(date +%s%N)/1000000))
    fi
}

# Function to submit a form response
submit_response() {
    local id=$1
    local start=$(get_time_ms)

    # Generate unique answer for this submission
    local response=$(curl -s -w "\n%{http_code}" -X POST "$ENDPOINT" \
        -H "Content-Type: application/json" \
        -H "OCS-APIREQUEST: true" \
        -d "{\"answers\":{\"q1\":\"Load test response $id\"}}" \
        2>/dev/null)

    local end=$(get_time_ms)
    local duration=$((end - start))
    local http_code=$(echo "$response" | tail -n1)
    local body=$(echo "$response" | head -n-1)

    # Check for success
    if [[ "$http_code" == "200" ]] || [[ "$http_code" == "201" ]]; then
        echo "$id,success,$duration,$http_code" >> "$TMPDIR/results.csv"
    else
        echo "$id,failed,$duration,$http_code" >> "$TMPDIR/results.csv"
        echo "Request $id: HTTP $http_code - $body" >> "$TMPDIR/errors.log"
    fi
}

export -f submit_response
export -f get_time_ms
export ENDPOINT TMPDIR OSTYPE

echo "Starting load test..."
echo ""

# Initialize results file
echo "id,status,duration_ms,http_code" > "$TMPDIR/results.csv"
> "$TMPDIR/errors.log"

# Run concurrent requests using xargs
START_TIME=$(get_time_ms)
seq 1 $TOTAL | xargs -P $CONCURRENT -I {} bash -c 'submit_response {}'
END_TIME=$(get_time_ms)

TOTAL_TIME=$((END_TIME - START_TIME))

echo ""
echo "Results:"
echo "--------"

# Count successes and failures
SUCCESS=$(grep -c ",success," "$TMPDIR/results.csv" 2>/dev/null || echo 0)
FAILED=$(grep -c ",failed," "$TMPDIR/results.csv" 2>/dev/null || echo 0)

echo "Total requests: $TOTAL"
echo "Successful: $SUCCESS"
echo "Failed: $FAILED"
echo "Total time: ${TOTAL_TIME}ms"

if [ "$TOTAL" -gt 0 ] && [ "$TOTAL_TIME" -gt 0 ]; then
    echo "Average time per request: $((TOTAL_TIME / TOTAL))ms"
    echo "Requests per second: $(echo "scale=1; $TOTAL * 1000 / $TOTAL_TIME" | bc)"
fi

if [ -s "$TMPDIR/errors.log" ]; then
    echo ""
    echo "Errors (first 10):"
    head -10 "$TMPDIR/errors.log"
fi

# Calculate response time stats
if [ "$SUCCESS" -gt 0 ]; then
    echo ""
    echo "Response time statistics (successful requests):"
    awk -F',' '$2=="success" {print $3}' "$TMPDIR/results.csv" | sort -n | awk '
    BEGIN { count=0; sum=0 }
    {
        a[count++] = $1
        sum += $1
    }
    END {
        if (count > 0) {
            print "  Min: " a[0] "ms"
            print "  Max: " a[count-1] "ms"
            print "  Avg: " int(sum/count) "ms"
            print "  Median: " a[int(count/2)] "ms"
            print "  P95: " a[int(count*0.95)] "ms"
        }
    }'
fi

echo ""
echo "Full results saved to: $TMPDIR/results.csv"

# Return exit code based on success rate
if [ "$FAILED" -eq 0 ] && [ "$SUCCESS" -gt 0 ]; then
    echo ""
    echo "✓ All requests successful!"
    exit 0
else
    echo ""
    echo "✗ Some requests failed. Check errors above."
    exit 1
fi
