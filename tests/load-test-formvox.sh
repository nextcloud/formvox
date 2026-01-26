#!/bin/bash

# FormVox Load Test Script - Fixed for actual FormVox endpoint

BASE_URL="https://testvox.hvanextcloudpoc.src.surf-hosted.nl"
FILE_ID="81088"
TOKEN="IXuveEUhiro9ARs"
CONCURRENT="${1:-20}"
TOTAL="${2:-50}"

ENDPOINT="$BASE_URL/index.php/apps/formvox/public/$FILE_ID/$TOKEN/submit"

echo "FormVox Concurrent Submission Load Test"
echo "========================================"
echo "URL: $ENDPOINT"
echo "Concurrent requests: $CONCURRENT"
echo "Total requests: $TOTAL"
echo ""

TMPDIR=$(mktemp -d)
echo "Results directory: $TMPDIR"
echo ""

# Initialize results file
echo "id,status,duration_ms,http_code" > "$TMPDIR/results.csv"
> "$TMPDIR/errors.log"

echo "Starting load test..."

START_TIME=$(python3 -c 'import time; print(int(time.time() * 1000))')

# Run requests in parallel using background jobs
for i in $(seq 1 $TOTAL); do
    (
        start=$(python3 -c 'import time; print(int(time.time() * 1000))')
        
        # Submit with minimal required data
        response=$(curl -s -w "\n%{http_code}" -X POST "$ENDPOINT" \
            -H "Content-Type: application/json" \
            -H "OCS-APIREQUEST: true" \
            -d "{\"answers\":{\"demo_name\":\"Load Test $i\",\"demo_email\":\"test$i@example.com\",\"demo_experience\":\"beginner\",\"demo_features\":[\"easy\"],\"demo_priority\":\"speed\",\"demo_scale\":\"5\",\"demo_rating\":\"4\"}}" \
            2>/dev/null)
        
        end=$(python3 -c 'import time; print(int(time.time() * 1000))')
        duration=$((end - start))
        http_code=$(echo "$response" | tail -1)
        body=$(echo "$response" | sed '$d')
        
        if [[ "$http_code" == "200" ]] || [[ "$http_code" == "201" ]]; then
            echo "$i,success,$duration,$http_code" >> "$TMPDIR/results.csv"
        else
            echo "$i,failed,$duration,$http_code" >> "$TMPDIR/results.csv"
            echo "Request $i: HTTP $http_code - $body" >> "$TMPDIR/errors.log"
        fi
    ) &
    
    # Limit concurrent jobs
    if (( i % CONCURRENT == 0 )); then
        wait
    fi
done

wait

END_TIME=$(python3 -c 'import time; print(int(time.time() * 1000))')
TOTAL_TIME=$((END_TIME - START_TIME))

echo ""
echo "Results:"
echo "--------"

SUCCESS=$(grep -c ",success," "$TMPDIR/results.csv" 2>/dev/null || echo 0)
FAILED=$(grep -c ",failed," "$TMPDIR/results.csv" 2>/dev/null || echo 0)

echo "Total requests: $TOTAL"
echo "Successful: $SUCCESS"
echo "Failed: $FAILED"
echo "Total time: ${TOTAL_TIME}ms"

if [ "$TOTAL" -gt 0 ] && [ "$TOTAL_TIME" -gt 0 ]; then
    echo "Requests per second: $(echo "scale=1; $TOTAL * 1000 / $TOTAL_TIME" | bc)"
fi

if [ -s "$TMPDIR/errors.log" ]; then
    echo ""
    echo "Errors (first 5):"
    head -5 "$TMPDIR/errors.log"
fi

if [ "$SUCCESS" -gt 0 ]; then
    echo ""
    echo "Response time statistics:"
    awk -F',' '$2=="success" {print $3}' "$TMPDIR/results.csv" | sort -n | awk '
    BEGIN { count=0; sum=0 }
    { a[count++] = $1; sum += $1 }
    END {
        if (count > 0) {
            print "  Min: " a[0] "ms"
            print "  Max: " a[count-1] "ms"
            print "  Avg: " int(sum/count) "ms"
            print "  Median: " a[int(count/2)] "ms"
        }
    }'
fi

echo ""
if [ "$FAILED" -eq 0 ] && [ "$SUCCESS" -gt 0 ]; then
    echo "✓ All requests successful!"
else
    echo "✗ Some requests failed"
fi
