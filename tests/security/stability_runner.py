#!/usr/bin/env python3
"""
Stability Test Runner
Unified security and stability test runner with comprehensive reporting
"""

import subprocess
import sys
import json
import os
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

# Configuration
TEST_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_URL = "http://localhost:8000"

# Define test suites
SECURITY_TESTS = [
    {"name": "IDOR/Authorization", "script": "pentest_idor.py", "category": "security"},
    {"name": "SQL/XSS Injection", "script": "pentest_injection.py", "category": "security"},
    {"name": "Mass Assignment", "script": "pentest_mass_assignment.py", "category": "security"},
    {"name": "CSRF Protection", "script": "pentest_csrf.py", "category": "security"},
    {"name": "Authentication", "script": "pentest_auth.py", "category": "security"},
    {"name": "Session Security", "script": "pentest_session.py", "category": "security"},
    {"name": "Security Headers", "script": "pentest_headers.py", "category": "security"},
    {"name": "File Upload", "script": "pentest_file_upload.py", "category": "security"},
    {"name": "WebSocket Security", "script": "pentest_websocket.py", "category": "security"},
]

STABILITY_TESTS = [
    {"name": "API Rate Limiting", "script": "stress_test_api.py", "category": "stability"},
    {"name": "Guest Rate Limiting", "script": "stress_test_guest.py", "category": "stability"},
    {"name": "Database Stability", "script": "stability_db.py", "category": "stability"},
    {"name": "Memory Leak Detection", "script": "stability_memory.py", "category": "stability"},
]


def run_test(test_info):
    """Run a single test script and capture results."""
    script_path = os.path.join(TEST_DIR, test_info["script"])
    
    if not os.path.exists(script_path):
        return {
            "name": test_info["name"],
            "category": test_info["category"],
            "status": "SKIP",
            "message": f"Script not found: {test_info['script']}",
            "duration": 0,
            "output": ""
        }
    
    start_time = datetime.now()
    
    try:
        result = subprocess.run(
            ["python3", script_path],
            capture_output=True,
            text=True,
            timeout=120  # 2 minute timeout per test
        )
        
        duration = (datetime.now() - start_time).total_seconds()
        
        return {
            "name": test_info["name"],
            "category": test_info["category"],
            "status": "PASS" if result.returncode == 0 else "FAIL",
            "exit_code": result.returncode,
            "duration": round(duration, 2),
            "output": result.stdout[-2000:] if len(result.stdout) > 2000 else result.stdout,
            "errors": result.stderr[-500:] if result.stderr else ""
        }
        
    except subprocess.TimeoutExpired:
        return {
            "name": test_info["name"],
            "category": test_info["category"],
            "status": "TIMEOUT",
            "message": "Test exceeded 120 second timeout",
            "duration": 120,
            "output": ""
        }
    except Exception as e:
        return {
            "name": test_info["name"],
            "category": test_info["category"],
            "status": "ERROR",
            "message": str(e),
            "duration": 0,
            "output": ""
        }


def check_server():
    """Check if the server is running."""
    import requests
    try:
        response = requests.get(f"{BASE_URL}/api/auth/config", timeout=5)
        return response.status_code < 500
    except:
        return False


def print_header(title):
    """Print formatted header."""
    print("\n" + "=" * 70)
    print(f" {title}")
    print("=" * 70)


def print_result(result):
    """Print individual test result."""
    status_icons = {
        "PASS": "✅",
        "FAIL": "❌",
        "SKIP": "⏭️",
        "TIMEOUT": "⏰",
        "ERROR": "💥"
    }
    
    icon = status_icons.get(result["status"], "❓")
    duration_str = f"({result['duration']}s)" if result["duration"] else ""
    
    print(f"  {icon} {result['name']:<30} {result['status']:<8} {duration_str}")


def main():
    print_header("Security & Stability Test Runner")
    print(f"  Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"  Target:  {BASE_URL}")
    
    # Check server
    print("\n  Checking server availability...")
    if not check_server():
        print("  ❌ Server not responding. Please ensure the app is running.")
        print("     Run: npm run start-all")
        sys.exit(1)
    print("  ✅ Server is running")
    
    all_results = []
    
    # Run Security Tests
    print_header("Security Tests")
    for test in SECURITY_TESTS:
        result = run_test(test)
        all_results.append(result)
        print_result(result)
    
    # Run Stability Tests
    print_header("Stability Tests")
    for test in STABILITY_TESTS:
        result = run_test(test)
        all_results.append(result)
        print_result(result)
    
    # Summary
    print_header("Test Summary")
    
    passed = sum(1 for r in all_results if r["status"] == "PASS")
    failed = sum(1 for r in all_results if r["status"] == "FAIL")
    skipped = sum(1 for r in all_results if r["status"] == "SKIP")
    errors = sum(1 for r in all_results if r["status"] in ["ERROR", "TIMEOUT"])
    total = len(all_results)
    
    print(f"  Total:   {total}")
    print(f"  Passed:  {passed} ✅")
    print(f"  Failed:  {failed} ❌")
    print(f"  Skipped: {skipped} ⏭️")
    print(f"  Errors:  {errors} 💥")
    print()
    
    # Calculate score
    if total > 0:
        score = round((passed / total) * 100, 1)
        print(f"  Security Score: {score}%")
        
        if score >= 90:
            print("  Rating: EXCELLENT 🏆")
        elif score >= 70:
            print("  Rating: GOOD 👍")
        elif score >= 50:
            print("  Rating: NEEDS IMPROVEMENT ⚠️")
        else:
            print("  Rating: CRITICAL ATTENTION REQUIRED 🚨")
    
    # Save JSON report
    report = {
        "timestamp": datetime.now().isoformat(),
        "target": BASE_URL,
        "summary": {
            "total": total,
            "passed": passed,
            "failed": failed,
            "skipped": skipped,
            "errors": errors,
            "score": score if total > 0 else 0
        },
        "results": all_results
    }
    
    report_path = os.path.join(TEST_DIR, "security_report.json")
    with open(report_path, "w") as f:
        json.dump(report, f, indent=2)
    
    print(f"\n  Report saved: {report_path}")
    print("=" * 70)
    
    # Exit with appropriate code
    sys.exit(0 if failed == 0 and errors == 0 else 1)


if __name__ == "__main__":
    main()
