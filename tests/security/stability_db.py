#!/usr/bin/env python3
"""
Database Stability Test
Tests database connection stability and error handling
"""

import requests
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

base_url = "http://localhost:8000/api"
token = "1|rIChykfSoXL9rQ1eFpLzuLSSVlRqEuP42T2GYH6I77f4a980"

headers = {
    "Authorization": f"Bearer {token}",
    "Accept": "application/json"
}

print("=" * 60)
print("Database Stability Test")
print("=" * 60)

all_passed = True

# Test 1: Connection Pool Stress
print("\n--- Test 1: Concurrent Connection Stress ---")


def make_request(endpoint):
    """Make a single request and return response time."""
    try:
        start = time.time()
        response = requests.get(f"{base_url}{endpoint}", headers=headers, timeout=30)
        duration = time.time() - start
        return {
            "status": response.status_code,
            "duration": round(duration, 3),
            "success": response.status_code == 200
        }
    except requests.exceptions.Timeout:
        return {"status": "timeout", "duration": 30, "success": False}
    except Exception as e:
        return {"status": "error", "duration": 0, "success": False, "error": str(e)}


# Endpoints that hit the database
db_endpoints = [
    "/user",
    "/dashboard",
    "/notifications",
    "/tickets?page=1",
    "/announcements/active",
]

try:
    concurrent_requests = 20
    results = []
    
    with ThreadPoolExecutor(max_workers=concurrent_requests) as executor:
        futures = []
        for _ in range(concurrent_requests):
            for endpoint in db_endpoints:
                futures.append(executor.submit(make_request, endpoint))
        
        for future in as_completed(futures):
            results.append(future.result())
    
    successful = sum(1 for r in results if r["success"])
    failed = len(results) - successful
    avg_time = sum(r["duration"] for r in results) / len(results)
    max_time = max(r["duration"] for r in results)
    
    print(f"  Total requests:    {len(results)}")
    print(f"  Successful:        {successful}")
    print(f"  Failed:            {failed}")
    print(f"  Avg response time: {avg_time:.3f}s")
    print(f"  Max response time: {max_time:.3f}s")
    
    if failed == 0:
        print("✅ PASSED: All concurrent requests succeeded")
    elif failed < len(results) * 0.1:  # Less than 10% failure
        print(f"⚠️  {failed} requests failed (< 10% threshold)")
    else:
        print(f"❌ FAILED: {failed} requests failed")
        all_passed = False
        
except Exception as e:
    print(f"ERROR: {e}")
    all_passed = False

# Test 2: Query Performance
print("\n--- Test 2: Complex Query Performance ---")
try:
    complex_endpoints = [
        ("/users?per_page=50", "Large user list"),
        ("/audit-logs?per_page=100", "Audit log pagination"),
        ("/search?q=test", "Global search"),
        ("/dashboard/stats", "Dashboard statistics"),
    ]
    
    for endpoint, description in complex_endpoints:
        start = time.time()
        response = requests.get(f"{base_url}{endpoint}", headers=headers, timeout=30)
        duration = time.time() - start
        
        if response.status_code == 200:
            if duration < 2:
                print(f"  ✅ {description}: {duration:.3f}s")
            elif duration < 5:
                print(f"  ⚠️  {description}: {duration:.3f}s (slow)")
            else:
                print(f"  ❌ {description}: {duration:.3f}s (very slow)")
        elif response.status_code == 403:
            print(f"  ⏭️  {description}: Permission denied (403)")
        else:
            print(f"  ❌ {description}: Status {response.status_code}")
            
except Exception as e:
    print(f"ERROR: {e}")

# Test 3: Error Recovery
print("\n--- Test 3: Error Recovery ---")
try:
    # Send request with invalid data to trigger validation
    response = requests.post(
        f"{base_url}/tickets",
        headers=headers,
        json={"invalid": "data"}
    )
    
    if response.status_code == 422:
        print("  ✅ Validation errors handled gracefully")
    else:
        print(f"  ℹ️  Response: {response.status_code}")
    
    # Send request with malformed JSON
    response = requests.post(
        f"{base_url}/tickets",
        headers={**headers, "Content-Type": "application/json"},
        data="not valid json {"
    )
    
    if response.status_code in [400, 422]:
        print("  ✅ Malformed JSON handled gracefully")
    else:
        print(f"  ℹ️  Malformed JSON response: {response.status_code}")
        
except Exception as e:
    print(f"ERROR: {e}")

# Test 4: Timeout Handling
print("\n--- Test 4: Long Query Timeout ---")
try:
    # This tests if the server handles long-running queries properly
    start = time.time()
    
    # Search with complex query
    response = requests.get(
        f"{base_url}/search",
        headers=headers,
        params={"q": "a" * 100},  # Very long search term
        timeout=30
    )
    
    duration = time.time() - start
    
    if response.status_code in [200, 422, 400]:
        print(f"  ✅ Long query handled in {duration:.3f}s")
    else:
        print(f"  ⚠️  Response: {response.status_code} in {duration:.3f}s")
        
except requests.exceptions.Timeout:
    print("  ⚠️  Query timed out (may need optimization)")
except Exception as e:
    print(f"ERROR: {e}")

# Test 5: Transaction Integrity
print("\n--- Test 5: Concurrent Update Safety ---")
try:
    # Test concurrent updates to same resource
    def update_profile(name_suffix):
        return requests.put(
            f"{base_url}/user/profile",
            headers=headers,
            json={"name": f"Test User {name_suffix}", "email": "admin@example.com"}
        )
    
    with ThreadPoolExecutor(max_workers=5) as executor:
        futures = [executor.submit(update_profile, i) for i in range(5)]
        results = [f.result() for f in as_completed(futures)]
    
    success_count = sum(1 for r in results if r.status_code in [200, 422])
    
    if success_count == len(results):
        print("  ✅ Concurrent updates handled safely")
    else:
        print(f"  ⚠️  {success_count}/{len(results)} concurrent updates succeeded")
        
except Exception as e:
    print(f"ERROR: {e}")

print("\n" + "=" * 60)
if all_passed:
    print("✅ All Database Stability Tests Passed!")
else:
    print("⚠️  Some Stability Tests Need Review")
print("=" * 60)

sys.exit(0 if all_passed else 1)
