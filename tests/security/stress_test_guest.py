import requests
import time

url = "http://localhost:8000/api/login"
limit = 10
attempts = 15

print(f"Testing rate limit for {url} (Limit: {limit}/min)")

for i in range(attempts):
    try:
        response = requests.post(url, json={"email": "test@example.com", "password": "wrongpassword"})
        status = response.status_code
        print(f"Request {i+1}: Status {status}")
        
        if status == 429:
            print("\n✅ Rate limit triggered successfully (429 Too Many Requests)!")
            print(f"Retry-After: {response.headers.get('Retry-After')} seconds")
            break
            
    except Exception as e:
        print(f"Error: {e}")

    # Small delay to prevent network flooding affecting local dev server too much
    # time.sleep(0.1) 
