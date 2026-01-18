import requests
import time

url = "http://localhost:8000/api/user"
token = "1|rIChykfSoXL9rQ1eFpLzuLSSVlRqEuP42T2GYH6I77f4a980"
headers = {
    "Authorization": f"Bearer {token}",
    "Accept": "application/json"
}
limit = 60
attempts = 70

print(f"Testing authenticated rate limit for {url} (Limit: {limit}/min)")

for i in range(attempts):
    try:
        response = requests.get(url, headers=headers)
        status = response.status_code
        # print(f"Request {i+1}: Status {status}")
        
        if status == 429:
            print(f"\n✅ Rate limit triggered successfully at request {i+1} (429 Too Many Requests)!")
            print(f"Retry-After: {response.headers.get('Retry-After')} seconds")
            break
        elif status != 200:
             print(f"Request {i+1}: Unexpected Status {status}")

    except Exception as e:
        print(f"Error: {e}")
    
    # Minimal delay
    # time.sleep(0.05)

if status != 429:
    print(f"\n❌ Failed to trigger rate limit after {attempts} requests.")
