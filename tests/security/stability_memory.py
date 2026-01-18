#!/usr/bin/env python3
"""
Memory Leak Detection Test
Monitors API endpoints for memory leaks during sustained load
"""

import requests
import time
import sys
import json
import os
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor

# Configuration
API_BASE = "http://localhost:8000/api"
TOKEN = "1|rIChykfSoXL9rQ1eFpLzuLSSVlRqEuP42T2GYH6I77f4a980"
HEADERS = {
    "Authorization": f"Bearer {TOKEN}",
    "Accept": "application/json"
}

# Test settings
ITERATIONS = 50  # Number of requests per endpoint
CONCURRENT_WORKERS = 5
MEMORY_SAMPLES = 5  # Number of memory snapshots

# Endpoints to stress test
ENDPOINTS = [
    "/user",
    "/dashboard",
    "/notifications",
    "/chat",
    "/announcements/active",
]

print("=" * 60)
print("Memory Leak Detection Test")
print("=" * 60)


def get_server_memory():
    """
    Get PHP memory usage via a debug endpoint or estimate.
    Since we don't have direct access, we'll measure response times
    as a proxy for memory issues.
    """
    # In a real scenario, you'd have a debug endpoint like /api/debug/memory
    # For now, we'll track response time degradation as a memory proxy
    return None


def make_request(endpoint):
    """Make a request and return timing info."""
    start = time.time()
    try:
        response = requests.get(f"{API_BASE}{endpoint}", headers=HEADERS, timeout=30)
        duration = time.time() - start
        return {
            "status": response.status_code,
            "duration": round(duration * 1000, 2),  # ms
            "size": len(response.content),
            "success": response.status_code == 200
        }
    except requests.exceptions.Timeout:
        return {"status": "timeout", "duration": 30000, "size": 0, "success": False}
    except Exception as e:
        return {"status": "error", "duration": 0, "size": 0, "success": False, "error": str(e)}


def test_endpoint_memory(endpoint):
    """Test a single endpoint for memory leaks via response time degradation."""
    print(f"\n--- Testing: {endpoint} ---")
    
    results = []
    
    # Baseline measurement
    print("  Taking baseline...")
    baseline = []
    for _ in range(5):
        result = make_request(endpoint)
        if result["success"]:
            baseline.append(result["duration"])
    
    if not baseline:
        print(f"  ⚠️  Endpoint not accessible (may be rate limited)")
        return None
    
    baseline_avg = sum(baseline) / len(baseline)
    print(f"  Baseline avg: {baseline_avg:.2f}ms")
    
    # Sustained load
    print(f"  Running {ITERATIONS} requests...")
    
    with ThreadPoolExecutor(max_workers=CONCURRENT_WORKERS) as executor:
        futures = [executor.submit(make_request, endpoint) for _ in range(ITERATIONS)]
        for f in futures:
            results.append(f.result())
    
    successful = [r for r in results if r["success"]]
    
    if not successful:
        print(f"  ⚠️  All requests failed (likely rate limited)")
        return None
    
    # Analyze results
    durations = [r["duration"] for r in successful]
    
    # Split into quartiles
    q1 = durations[:len(durations)//4]
    q4 = durations[-(len(durations)//4):]
    
    avg_q1 = sum(q1) / len(q1) if q1 else 0
    avg_q4 = sum(q4) / len(q4) if q4 else 0
    
    # Check for degradation (memory leak indicator)
    degradation = ((avg_q4 - avg_q1) / avg_q1 * 100) if avg_q1 > 0 else 0
    
    print(f"  First 25% avg: {avg_q1:.2f}ms")
    print(f"  Last 25% avg:  {avg_q4:.2f}ms")
    print(f"  Degradation:   {degradation:+.1f}%")
    
    if degradation > 50:
        print(f"  ❌ POTENTIAL LEAK: Response time degraded {degradation:.1f}%")
        return {"endpoint": endpoint, "leak_indicator": True, "degradation": degradation}
    elif degradation > 20:
        print(f"  ⚠️  Warning: Response time increased {degradation:.1f}%")
        return {"endpoint": endpoint, "leak_indicator": False, "degradation": degradation}
    else:
        print(f"  ✅ PASSED: No memory leak indicators")
        return {"endpoint": endpoint, "leak_indicator": False, "degradation": degradation}


def test_object_accumulation():
    """Test for object accumulation by checking response size patterns."""
    print("\n--- Object Accumulation Test ---")
    
    sizes = []
    
    for i in range(10):
        result = make_request("/user")
        if result.get("success"):
            sizes.append(result["size"])
        time.sleep(0.1)
    
    if len(sizes) < 5:
        print("  ⚠️  Not enough successful requests")
        return
    
    # Check if response size is growing (could indicate object leaks in response)
    first_half = sizes[:len(sizes)//2]
    second_half = sizes[len(sizes)//2:]
    
    avg_first = sum(first_half) / len(first_half)
    avg_second = sum(second_half) / len(second_half)
    
    growth = ((avg_second - avg_first) / avg_first * 100) if avg_first > 0 else 0
    
    if abs(growth) > 10:
        print(f"  ⚠️  Response size variance: {growth:+.1f}%")
    else:
        print(f"  ✅ Response sizes consistent (variance: {growth:+.1f}%)")


def test_connection_pool():
    """Test for connection pool exhaustion."""
    print("\n--- Connection Pool Test ---")
    
    # Make many rapid requests to stress connection pool
    start = time.time()
    success_count = 0
    fail_count = 0
    
    with ThreadPoolExecutor(max_workers=20) as executor:
        futures = [executor.submit(make_request, "/user") for _ in range(50)]
        for f in futures:
            result = f.result()
            if result.get("success") or result.get("status") == 429:
                success_count += 1
            else:
                fail_count += 1
    
    duration = time.time() - start
    
    print(f"  Completed: {success_count}, Failed: {fail_count}")
    print(f"  Duration: {duration:.2f}s")
    
    if fail_count > success_count * 0.3:  # More than 30% failures
        print(f"  ⚠️  High failure rate may indicate pool exhaustion")
    else:
        print(f"  ✅ Connection pool handled load")


def main():
    print(f"\nStarted: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Target: {API_BASE}")
    print(f"Iterations per endpoint: {ITERATIONS}")
    
    all_results = []
    leak_detected = False
    
    # Test each endpoint
    for endpoint in ENDPOINTS:
        result = test_endpoint_memory(endpoint)
        if result:
            all_results.append(result)
            if result.get("leak_indicator"):
                leak_detected = True
    
    # Additional tests
    test_object_accumulation()
    test_connection_pool()
    
    # Summary
    print("\n" + "=" * 60)
    print("Memory Leak Test Summary")
    print("=" * 60)
    
    if all_results:
        print("\nEndpoint Analysis:")
        for r in all_results:
            status = "❌ LEAK SUSPECTED" if r.get("leak_indicator") else "✅ OK"
            print(f"  {r['endpoint']:<30} {status} ({r['degradation']:+.1f}%)")
    
    if leak_detected:
        print("\n⚠️  Potential memory leaks detected!")
        print("   Consider profiling these endpoints with Xdebug or Blackfire")
    else:
        print("\n✅ No obvious memory leak patterns detected")
    
    print("=" * 60)
    
    # Save results
    report = {
        "timestamp": datetime.now().isoformat(),
        "target": API_BASE,
        "iterations": ITERATIONS,
        "results": all_results,
        "leak_detected": leak_detected
    }
    
    report_path = os.path.join(os.path.dirname(__file__), "memory_report.json")
    with open(report_path, "w") as f:
        json.dump(report, f, indent=2)
    
    print(f"\nReport saved: {report_path}")
    
    sys.exit(1 if leak_detected else 0)


if __name__ == "__main__":
    main()
